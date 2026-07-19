<?php
/**
 * Kommunikation – KI-gestützte Öffentlichkeitsarbeit je Wettbewerbsjahr.
 *
 * Die Projektleitung (Verwaltung) legt Beiträge zu drei Anlässen an – Social
 * Media zum Jury-Feedback, Social Media zum Pitch Day und die Pressemitteilung
 * zum Pitch Day –, lässt Texte per KI generieren, verbessert sie iterativ per
 * Feedback, hängt ein Bild aus der Mediengalerie an und veröffentlicht das
 * Ergebnis (Link zum Instagram-Post bzw. PDF der abgedruckten Pressemitteilung).
 *
 * Alle übrigen Beteiligten sehen die VERÖFFENTLICHTEN Beiträge (Nur-Lese).
 *
 * Rechte: Lesen = alle mit Zugriff (Zugriffsmatrix); Schreiben (Erstellen,
 * Generieren, Veröffentlichen) nur die Verwaltung (Auth::isManager()).
 */

declare(strict_types=1);

Access::requireRead('communication');

$cycles        = Cycle::all();
$activeCycleId = Cycle::activeId();

$cycleId = (int) input('cycle', $activeCycleId);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = $activeCycleId;
}
$cycle     = $cycleId ? Cycle::find($cycleId) : null;
$isManager = Auth::isManager();

$back = fn() => redirect(url('communication', ['cycle' => $cycleId]));

if (is_post()) {
    Access::requireWrite('communication');
    Csrf::check();
    if (!$cycle) {
        flash('error', 'Kein Wettbewerbsjahr ausgewählt.');
        $back();
    }
    $action = (string) input('action');

    // Beitrag der aktuellen Auswahl laden (für alle beitragsbezogenen Aktionen).
    $itemId = (int) input('item_id', 0);
    $item   = $itemId > 0 ? Communication::find($itemId) : null;
    if ($itemId > 0 && (!$item || (int) $item['cycle_id'] !== $cycleId)) {
        flash('error', 'Beitrag nicht gefunden.');
        $back();
    }

    // --- Globale Stil-Hinweise (für alle Generierungen) ---
    if ($action === 'save_guidance') {
        Settings::set('communication_guidance', trim((string) input('guidance')) ?: null);
        Audit::log('communication.guidance', 'Stil-Hinweise für die Kommunikation gespeichert', 'cycle', $cycleId);
        flash('success', 'Stil-Hinweise gespeichert.');
        $back();
    }

    // --- Neuen Beitrag anlegen ---
    if ($action === 'create') {
        $type = (string) input('type');
        if (!Communication::isValidType($type)) {
            flash('error', 'Unbekannter Anlass.');
            $back();
        }
        $title = trim((string) input('title'));
        if ($title === '') {
            $title = Communication::typeLabel($type)
                . ($cycle ? ' ' . (string) $cycle['year_label'] : '');
        }
        $briefing = Communication::autoBriefing($cycleId, $type);
        $newId = Database::insert(
            'INSERT INTO communication_items (cycle_id, type, title, briefing, created_by)
             VALUES (?,?,?,?,?)',
            [$cycleId, $type, mb_substr($title, 0, 190), $briefing, Auth::id()]
        );
        Audit::log('communication.create', 'Kommunikationsbeitrag angelegt: ' . Communication::typeLabel($type), 'communication', $newId);
        flash('success', 'Beitrag angelegt – Briefing prüfen und Text generieren.');
        redirect(url('communication', ['cycle' => $cycleId, 'open' => $newId]));
    }

    // Ab hier ist ein gültiger Beitrag nötig.
    if (!$item) {
        flash('error', 'Kein Beitrag ausgewählt.');
        $back();
    }
    $openParam = ['cycle' => $cycleId, 'open' => $itemId];

    if ($action === 'save_briefing') {
        Database::run(
            'UPDATE communication_items SET briefing = ?, title = ? WHERE id = ?',
            [trim((string) input('briefing')) ?: null, mb_substr(trim((string) input('title')) ?: (string) $item['title'], 0, 190), $itemId]
        );
        flash('success', 'Briefing gespeichert.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'generate') {
        @set_time_limit(180);
        // Feedback (optional): mit Text vorhanden = gezielt verbessern, sonst neu
        // generieren. generate() nutzt den aktuellen Text des Beitrags als Grundlage.
        $feedback = trim((string) input('feedback')) ?: null;
        $res = Communication::generate($itemId, $feedback);
        if ($res['ok']) {
            Audit::log('communication.generate',
                ($feedback ? 'Text per Feedback verbessert' : 'Text per KI generiert') . ': ' . Communication::typeLabel((string) $item['type']),
                'communication', $itemId);
            flash('success', $feedback ? 'Verbesserte Fassung erstellt.' : 'Text generiert.');
        } else {
            flash('error', 'KI-Fehler: ' . ($res['error'] ?? 'unbekannt'));
        }
        redirect(url('communication', $openParam));
    }

    if ($action === 'save_body') {
        Database::run('UPDATE communication_items SET body = ? WHERE id = ?', [trim((string) input('body')) ?: null, $itemId]);
        flash('success', 'Text gespeichert.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'set_image') {
        $mediaId = (int) input('image_media_id', 0);
        $valid = $mediaId > 0
            ? Database::one("SELECT id FROM media_items WHERE id = ? AND cycle_id = ? AND kind = 'image'", [$mediaId, $cycleId])
            : null;
        Database::run('UPDATE communication_items SET image_media_id = ? WHERE id = ?', [$valid ? $mediaId : null, $itemId]);
        flash('success', $valid ? 'Bild verknüpft.' : 'Bild entfernt.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'publish') {
        if (trim((string) ($item['body'] ?? '')) === '') {
            flash('error', 'Es gibt noch keinen Text zum Veröffentlichen.');
            redirect(url('communication', $openParam));
        }
        $url = trim((string) input('published_url'));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            flash('error', 'Bitte einen gültigen Link (mit http:// oder https://) angeben.');
            redirect(url('communication', $openParam));
        }

        // PDF der abgedruckten Pressemitteilung (optional bei Presse-Beiträgen).
        $pdfPath = $item['pdf_path'];
        $pdfName = $item['pdf_name'];
        if (!empty($_FILES['pdf']['name']) && is_uploaded_file($_FILES['pdf']['tmp_name'] ?? '')) {
            $f = $_FILES['pdf'];
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $maxBytes = (int) cfg('upload_max_bytes', 25 * 1024 * 1024);
            if ($ext !== 'pdf') {
                flash('error', 'Bitte eine PDF-Datei hochladen.');
                redirect(url('communication', $openParam));
            }
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) $f['size'] > $maxBytes) {
                flash('error', 'PDF konnte nicht hochgeladen werden (zu groß?).');
                redirect(url('communication', $openParam));
            }
            if (!Communication::ensurePdfDir()) {
                flash('error', 'Speicherordner für PDFs fehlt.');
                redirect(url('communication', $openParam));
            }
            // Alte PDF ersetzen.
            if ($pdfPath) {
                @unlink(Communication::pdfDir() . '/' . basename((string) $pdfPath));
            }
            $stored = 'pm_' . bin2hex(random_bytes(8)) . '.pdf';
            if (!move_uploaded_file($f['tmp_name'], Communication::pdfDir() . '/' . $stored)) {
                flash('error', 'PDF-Upload fehlgeschlagen.');
                redirect(url('communication', $openParam));
            }
            $pdfPath = $stored;
            $pdfName = mb_substr((string) $f['name'], 0, 255);
        }

        // Mindestens ein „Fundort" der Veröffentlichung.
        if ($url === '' && !$pdfPath) {
            flash('error', Communication::kindOf((string) $item['type']) === 'press'
                ? 'Bitte den Link zum Beitrag angeben oder die PDF der Pressemitteilung hochladen.'
                : 'Bitte den Link zum veröffentlichten Post (z. B. Instagram) angeben.');
            redirect(url('communication', $openParam));
        }

        Database::run(
            'UPDATE communication_items
                SET status = "published", published_url = ?, pdf_path = ?, pdf_name = ?, published_at = COALESCE(published_at, NOW())
              WHERE id = ?',
            [$url !== '' ? mb_substr($url, 0, 500) : null, $pdfPath, $pdfName, $itemId]
        );
        Audit::log('communication.publish', 'Kommunikationsbeitrag veröffentlicht: ' . (string) $item['title'], 'communication', $itemId);
        flash('success', 'Beitrag veröffentlicht – jetzt für alle sichtbar.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'unpublish') {
        Database::run('UPDATE communication_items SET status = "draft" WHERE id = ?', [$itemId]);
        Audit::log('communication.unpublish', 'Veröffentlichung zurückgezogen: ' . (string) $item['title'], 'communication', $itemId);
        flash('success', 'Veröffentlichung zurückgezogen (wieder Entwurf).');
        redirect(url('communication', $openParam));
    }

    if ($action === 'delete') {
        if ($item['pdf_path']) {
            @unlink(Communication::pdfDir() . '/' . basename((string) $item['pdf_path']));
        }
        Database::run('DELETE FROM communication_items WHERE id = ?', [$itemId]);
        Audit::log('communication.delete', 'Kommunikationsbeitrag gelöscht: ' . (string) $item['title'], 'communication', $itemId);
        flash('success', 'Beitrag gelöscht.');
        $back();
    }

    $back();
}

// ------------------------------------------------------------------ Ansicht
$items    = $cycleId ? Communication::forCycle($cycleId, !$isManager) : [];
$openId   = (int) input('open', 0);
$guidance = (string) Settings::get('communication_guidance', '');
$gallery  = ($isManager && $cycleId) ? Communication::galleryImages($cycleId) : [];

$imgThumb = fn(int $id) => url('media_file', ['id' => $id, 'v' => 'thumb']);
$imgView  = fn(int $id) => url('media_file', ['id' => $id, 'v' => 'view']);

$cycleSwitcher = function () use ($cycles, $cycleId) {
    if (count($cycles) < 2) {
        return '';
    }
    ob_start(); ?>
    <form method="get" action="<?= url('communication') ?>" style="display:inline">
      <input type="hidden" name="r" value="communication">
      <select name="cycle" onchange="this.form.submit()" style="min-width:130px">
        <?php foreach ($cycles as $cy): ?>
          <option value="<?= (int) $cy['id'] ?>" <?= (int) $cy['id'] === $cycleId ? 'selected' : '' ?>>
            <?= e($cy['year_label']) ?><?= $cy['is_active'] ? ' •' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php return (string) ob_get_clean();
};

ob_start(); ?>
<div class="page-head">
  <h1>📣 Kommunikation<?= $cycle ? ' <span class="muted" style="font-weight:400;font-size:.7em">' . e($cycle['year_label']) . '</span>' : '' ?></h1>
  <?= $cycleSwitcher() ?>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Zuerst unter <a href="<?= url('cycles') ?>">Wettbewerbsjahre</a> ein Jahr anlegen und aktiv setzen.</p>
  </div></div>
<?php else: ?>

  <p class="muted" style="max-width:80ch;margin:-4px 0 16px">
    <?php if ($isManager): ?>
      Erstelle Beiträge für <strong>Social Media (Instagram)</strong> und die <strong>Pressemitteilung</strong>.
      Die KI generiert aus deinem Briefing einen fertigen Text, den du per <strong>Feedback iterativ verbessern</strong>
      kannst. Ein Bild wählst du aus der <a href="<?= url('gallery') ?>">Mediengalerie</a>. Nach der Veröffentlichung
      hinterlegst du den <strong>Link zum Post</strong> bzw. die <strong>PDF der abgedruckten Pressemitteilung</strong> –
      erst dann sehen alle Beteiligten den Beitrag.
    <?php else: ?>
      Hier findest du die <strong>veröffentlichten</strong> Beiträge rund um den Wettbewerb – mit Link zum
      Instagram-Post bzw. der PDF der Pressemitteilung.
    <?php endif; ?>
  </p>

  <?php if ($isManager): ?>
    <!-- Aktionsleiste: neuer Beitrag + Stil-Hinweise -->
    <div class="card mb">
      <div class="card__body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php foreach (Communication::TYPES as $tk => $t): ?>
            <button type="button" class="btn btn--primary btn--sm" data-modal-open="createModal"
              data-fill='<?= e(json_encode(['type' => $tk], JSON_UNESCAPED_UNICODE)) ?>'>
              <?= $t[1] ?> <?= e($t[0]) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn--ghost btn--sm" data-modal-open="guidanceModal">⚙ Stil-Hinweise</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="card"><div class="card__body">
      <p class="muted"><?= $isManager
        ? 'Noch keine Beiträge – lege oben einen an.'
        : 'Es wurden noch keine Beiträge veröffentlicht.' ?></p>
    </div></div>
  <?php endif; ?>

  <?php foreach ($items as $it):
    $type   = (string) $it['type'];
    [$stLabel, $stColor] = Communication::statusLabel((string) $it['status']);
    $isPress = Communication::kindOf($type) === 'press';
    $hasBody = trim((string) ($it['body'] ?? '')) !== '';
    $imgId   = (int) ($it['image_media_id'] ?? 0);
    $bodyId  = 'body_' . (int) $it['id'];
    $open    = $openId === (int) $it['id'];
    ?>

    <?php if ($isManager): /* ============ Verwaltungs-Ansicht (voller Editor) ============ */ ?>
      <div class="card mb" id="item-<?= (int) $it['id'] ?>">
        <div class="card__head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span><?= Communication::typeIcon($type) ?> <strong><?= e((string) $it['title']) ?></strong></span>
          <span class="pill <?= $stColor ?>"><?= e($stLabel) ?></span>
          <span class="muted" style="font-size:12px"><?= e(Communication::typeLabel($type)) ?></span>
          <form method="post" action="<?= url('communication') ?>" style="margin-left:auto" data-confirm="Diesen Beitrag mit allen Fassungen wirklich löschen?">
            <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
            <button class="btn btn--danger btn--sm">Löschen</button>
          </form>
        </div>
        <div class="card__body">
          <div class="grid cols-2" style="align-items:start">

            <!-- Linke Spalte: Briefing + Generierung -->
            <div>
              <form method="post" action="<?= url('communication') ?>">
                <?= Csrf::field() ?><input type="hidden" name="action" value="save_briefing"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <div class="field"><label>Titel (intern)</label>
                  <input type="text" name="title" value="<?= e((string) $it['title']) ?>" maxlength="190">
                </div>
                <div class="field"><label>Briefing – Fakten & Stichpunkte für die KI</label>
                  <textarea name="briefing" rows="10" placeholder="Zahlen, Platzierungen, Namen, Zitate, Sponsoren, @handles …"><?= e((string) ($it['briefing'] ?? '')) ?></textarea>
                </div>
                <div style="text-align:right"><button class="btn btn--ghost btn--sm">Briefing speichern</button></div>
              </form>

              <div style="border-top:1px solid var(--line,#e4e7ee);margin-top:12px;padding-top:12px">
                <?php if ($hasBody): ?>
                  <form method="post" action="<?= url('communication') ?>">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                    <div class="field"><label>Feedback – was soll verbessert werden?</label>
                      <textarea name="feedback" rows="3" required placeholder="z. B. kürzer, mehr Fokus auf das Siegerteam, lockerer Ton"></textarea>
                    </div>
                    <div style="text-align:right"><button class="btn btn--teal btn--sm">✨ Mit Feedback verbessern</button></div>
                  </form>
                  <form method="post" action="<?= url('communication') ?>" data-confirm="Text komplett neu generieren? Die aktuelle Fassung wird als Revision behalten." style="text-align:right;margin-top:6px">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                    <button class="btn btn--ghost btn--sm">🔄 Neu generieren</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="<?= url('communication') ?>">
                    <?= Csrf::field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                    <div style="text-align:right"><button class="btn btn--teal btn--sm">✨ Text generieren</button></div>
                  </form>
                <?php endif; ?>
                <?php if (!empty($it['ai_generated_at'])): ?>
                  <p class="muted" style="font-size:12px;margin:8px 0 0">Zuletzt generiert am <?= e(date('d.m.Y, H:i', strtotime((string) $it['ai_generated_at']))) ?> Uhr<?= $it['ai_model'] ? ' · ' . e((string) $it['ai_model']) : '' ?>.</p>
                <?php endif; ?>
              </div>
            </div>

            <!-- Rechte Spalte: Text + Bild -->
            <div>
              <form method="post" action="<?= url('communication') ?>">
                <?= Csrf::field() ?><input type="hidden" name="action" value="save_body"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                <div class="field"><label>Text <span class="muted" style="font-weight:400">(bearbeitbar)</span></label>
                  <textarea id="<?= $bodyId ?>" name="body" rows="14" placeholder="Noch kein Text – links generieren."><?= e((string) ($it['body'] ?? '')) ?></textarea>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
                  <button type="button" class="btn btn--ghost btn--sm" onclick="var t=document.getElementById('<?= $bodyId ?>');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);this.textContent='Kopiert ✓';var b=this;setTimeout(function(){b.textContent='Text kopieren'},1500);}">Text kopieren</button>
                  <button class="btn btn--ghost btn--sm">Text speichern</button>
                </div>
              </form>

              <!-- Bild aus der Mediengalerie -->
              <div class="field" style="margin-top:10px">
                <label>Bild aus der Mediengalerie</label>
                <?php if ($imgId): ?>
                  <div style="margin-bottom:8px"><img src="<?= e($imgThumb($imgId)) ?>" alt="" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid var(--line,#e4e7ee)"></div>
                <?php endif; ?>
                <form method="post" action="<?= url('communication') ?>">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="set_image"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <select name="image_media_id" onchange="this.form.submit()" style="flex:1;min-width:180px">
                      <option value="0">— kein Bild —</option>
                      <?php foreach ($gallery as $g):
                        $lbl = trim((string) ($g['title'] ?: $g['original_name'] ?: ('Bild #' . $g['id']))); ?>
                        <option value="<?= (int) $g['id'] ?>" <?= (int) $g['id'] === $imgId ? 'selected' : '' ?>><?= e(mb_substr($lbl, 0, 70)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn--ghost btn--sm">Übernehmen</button></noscript>
                  </div>
                  <?php if (!$gallery): ?>
                    <p class="muted" style="font-size:12px;margin:6px 0 0">Noch keine Bilder – lade welche in der <a href="<?= url('gallery') ?>">Mediengalerie</a> hoch.</p>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          </div>

          <!-- Veröffentlichen -->
          <div style="border-top:1px solid var(--line,#e4e7ee);margin-top:14px;padding-top:14px">
            <?php $curPdf = !empty($it['pdf_path']); ?>
            <form method="post" action="<?= url('communication') ?>" enctype="multipart/form-data">
              <?= Csrf::field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
              <div class="grid cols-2" style="align-items:end">
                <div class="field" style="margin:0">
                  <label>Link zum veröffentlichten Beitrag<?= $isPress ? ' (optional)' : '' ?></label>
                  <input type="url" name="published_url" value="<?= e((string) ($it['published_url'] ?? '')) ?>" placeholder="https://www.instagram.com/p/…">
                </div>
                <?php if ($isPress): ?>
                  <div class="field" style="margin:0">
                    <label>PDF der abgedruckten Pressemitteilung<?= $curPdf ? ' (vorhanden – neu hochladen zum Ersetzen)' : '' ?></label>
                    <input type="file" name="pdf" accept="application/pdf">
                    <?php if ($curPdf): ?><p class="muted" style="font-size:12px;margin:4px 0 0"><a href="<?= url('communication_pdf', ['id' => (int) $it['id']]) ?>" target="_blank" rel="noopener">📄 <?= e((string) $it['pdf_name']) ?></a></p><?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px">
                <?php if ((string) $it['status'] === 'published'): ?>
                  <button class="btn btn--ghost btn--sm">Angaben aktualisieren</button>
                  <button class="btn btn--ghost btn--sm" formaction="<?= url('communication') ?>" name="action" value="unpublish" formnovalidate>Veröffentlichung zurückziehen</button>
                <?php else: ?>
                  <button class="btn btn--primary btn--sm" <?= $hasBody ? '' : 'disabled' ?>>✅ Veröffentlichen</button>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <!-- Frühere Fassungen -->
          <?php $revs = Communication::revisions((int) $it['id']); if ($revs): ?>
            <details style="margin-top:12px"<?= $open ? ' open' : '' ?>>
              <summary class="muted" style="cursor:pointer;font-size:13px">Frühere Fassungen (<?= count($revs) ?>)</summary>
              <div style="margin-top:8px;display:flex;flex-direction:column;gap:8px">
                <?php foreach ($revs as $r): ?>
                  <div style="border:1px solid var(--line,#e4e7ee);border-radius:8px;padding:8px 10px;background:#fff">
                    <div class="muted" style="font-size:12px;margin-bottom:4px">
                      <?= e(date('d.m.Y, H:i', strtotime((string) $r['created_at']))) ?> Uhr
                      <?= $r['author'] ? '· ' . e((string) $r['author']) : '' ?>
                      <?= !empty($r['feedback']) ? '· Feedback: „' . e(mb_substr((string) $r['feedback'], 0, 120)) . '"' : '· Neu generiert' ?>
                    </div>
                    <div style="white-space:pre-wrap;font-size:13px;max-height:160px;overflow:auto"><?= e((string) $r['body']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endif; ?>
        </div>
      </div>

    <?php else: /* ============ Nur-Lese-Ansicht (veröffentlichte Beiträge) ============ */ ?>
      <div class="card mb">
        <div class="card__head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span><?= Communication::typeIcon($type) ?> <strong><?= e((string) $it['title']) ?></strong></span>
          <span class="muted" style="font-size:12px;margin-left:auto"><?= $it['published_at'] ? e(date('d.m.Y', strtotime((string) $it['published_at']))) : '' ?></span>
        </div>
        <div class="card__body">
          <div class="<?= $imgId ? 'grid cols-2' : '' ?>" style="align-items:start">
            <?php if ($imgId): ?>
              <div><a href="<?= e($imgView($imgId)) ?>" target="_blank" rel="noopener"><img src="<?= e($imgThumb($imgId)) ?>" alt="" style="max-width:100%;border-radius:10px;border:1px solid var(--line,#e4e7ee)"></a></div>
            <?php endif; ?>
            <div>
              <div style="white-space:pre-wrap"><?= e((string) $it['body']) ?></div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <?php if (!empty($it['published_url'])): ?>
                  <a class="btn btn--primary btn--sm" href="<?= e((string) $it['published_url']) ?>" target="_blank" rel="noopener">↗ Zum Beitrag</a>
                <?php endif; ?>
                <?php if (!empty($it['pdf_path'])): ?>
                  <a class="btn btn--ghost btn--sm" href="<?= url('communication_pdf', ['id' => (int) $it['id']]) ?>" target="_blank" rel="noopener">📄 Pressemitteilung (PDF)</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($isManager): ?>
    <!-- ===================== Modals ===================== -->
    <div class="modal-overlay" id="createModal" data-modal-static hidden>
      <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
        <div class="modal__head"><h3 id="createModalTitle">Neuer Beitrag</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
        <form method="post" action="<?= url('communication') ?>" class="modal__body" data-modal-form>
          <?= Csrf::field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <div class="field"><label>Anlass</label><select name="type">
            <?php foreach (Communication::TYPES as $tk => $t): ?><option value="<?= e($tk) ?>"><?= e($t[0]) ?></option><?php endforeach; ?>
          </select></div>
          <div class="field"><label>Titel (intern, optional)</label><input type="text" name="title" maxlength="190" placeholder="wird sonst automatisch gesetzt"></div>
          <p class="muted" style="font-size:13px">Das Briefing wird mit den bekannten Fakten (Kennzahlen, Finalteams, Sponsoren …) vorbefüllt – du kannst es danach anpassen.</p>
          <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Anlegen</button></div>
        </form>
      </div>
    </div>

    <div class="modal-overlay" id="guidanceModal" data-modal-static hidden>
      <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="guidanceModalTitle">
        <div class="modal__head"><h3 id="guidanceModalTitle">Stil-Hinweise für die KI</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
        <form method="post" action="<?= url('communication') ?>" class="modal__body" data-modal-form>
          <?= Csrf::field() ?><input type="hidden" name="action" value="save_guidance"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <div class="field"><label>Dauerhafte Hinweise (Tonalität, Do's & Don'ts)</label>
            <textarea name="guidance" rows="5" placeholder="z. B. immer gendern, Stadthalle Ebermannstadt erwähnen, kein Ausrufezeichen im Titel"><?= e($guidance) ?></textarea>
          </div>
          <p class="muted" style="font-size:13px">Diese Hinweise fließen in jede Generierung ein (für alle Beiträge).</p>
          <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
        </form>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Kommunikation';
require APP_PATH . '/pages/_layout.php';
