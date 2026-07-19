<?php
/**
 * Kommunikation – KI-gestützte Öffentlichkeitsarbeit je Wettbewerbsjahr.
 *
 * Die Projektleitung (Verwaltung) legt Beiträge zu drei Anlässen an – Social
 * Media zum Jury-Feedback, Social Media zum Pitch Day und die Pressemitteilung
 * zum Pitch Day –, lässt Texte per KI generieren, verbessert sie iterativ per
 * Feedback, hängt Bilder aus der Mediengalerie an (mit Bildunterschrift & Fotograf)
 * und veröffentlicht das Ergebnis. Pressemitteilungen werden als Word-Dokument
 * (Fließtext + Bildanhang) erzeugt.
 *
 * Sichtbarkeit: Der gesamte Arbeitsbereich ist NUR für Projektleitung & Admin.
 * Erst VERÖFFENTLICHTE Beiträge sehen alle übrigen Beteiligten (Nur-Lese).
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

    $itemId = (int) input('item_id', 0);
    $item   = $itemId > 0 ? Communication::find($itemId) : null;
    if ($itemId > 0 && (!$item || (int) $item['cycle_id'] !== $cycleId)) {
        flash('error', 'Beitrag nicht gefunden.');
        $back();
    }

    if ($action === 'save_guidance') {
        Settings::set('communication_guidance', trim((string) input('guidance')) ?: null);
        Audit::log('communication.guidance', 'Stil-Hinweise für die Kommunikation gespeichert', 'cycle', $cycleId);
        flash('success', 'Stil-Hinweise gespeichert.');
        $back();
    }

    if ($action === 'create') {
        $type = (string) input('type');
        if (!Communication::isValidType($type)) {
            flash('error', 'Unbekannter Anlass.');
            $back();
        }
        $title = trim((string) input('title'));
        if ($title === '') {
            $title = Communication::typeLabel($type) . ($cycle ? ' ' . (string) $cycle['year_label'] : '');
        }
        $briefing = Communication::autoBriefing($cycleId, $type);
        $newId = Database::insert(
            'INSERT INTO communication_items (cycle_id, type, title, briefing, created_by) VALUES (?,?,?,?,?)',
            [$cycleId, $type, mb_substr($title, 0, 190), $briefing, Auth::id()]
        );
        Audit::log('communication.create', 'Kommunikationsbeitrag angelegt: ' . Communication::typeLabel($type), 'communication', $newId);
        flash('success', 'Beitrag angelegt – Briefing prüfen und Text generieren.');
        redirect(url('communication', ['cycle' => $cycleId, 'open' => $newId]));
    }

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

    // --- Bilder: mehrere aus der Galerie anhängen ---
    if ($action === 'attach_images') {
        $ids = (array) input('media_ids', []);
        // Nur Bilder des Zyklus zulassen.
        $valid = [];
        foreach (array_map('intval', $ids) as $mid) {
            if ($mid > 0 && Database::value("SELECT id FROM media_items WHERE id = ? AND cycle_id = ? AND kind = 'image'", [$mid, $cycleId])) {
                $valid[] = $mid;
            }
        }
        $n = Communication::attachImages($itemId, $valid);
        flash($n > 0 ? 'success' : 'info', $n > 0 ? ($n . ' Bild(er) hinzugefügt.') : 'Keine neuen Bilder ausgewählt.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'save_image_meta') {
        Communication::updateImageMeta((int) input('image_id', 0), $itemId, (string) input('caption'), (string) input('photographer'));
        flash('success', 'Bildangaben gespeichert.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'remove_image') {
        Communication::removeImage((int) input('image_id', 0), $itemId);
        flash('success', 'Bild entfernt.');
        redirect(url('communication', $openParam));
    }

    if ($action === 'publish') {
        if (trim((string) ($item['body'] ?? '')) === '') {
            flash('error', 'Es gibt noch keinen Text zum Veröffentlichen.');
            redirect(url('communication', $openParam));
        }
        $isPress = Communication::kindOf((string) $item['type']) === 'press';
        $url = trim((string) input('published_url'));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            flash('error', 'Bitte einen gültigen Link (mit http:// oder https://) angeben.');
            redirect(url('communication', $openParam));
        }

        // Optionale PDF der abgedruckten Pressemitteilung.
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

        // Social: Link zum Post ist Pflicht (dort „lebt" der Beitrag). Presse:
        // das Word-Dokument ist immer verfügbar, Link/PDF sind optional.
        if (!$isPress && $url === '') {
            flash('error', 'Bitte den Link zum veröffentlichten Post (z. B. Instagram) angeben.');
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

/**
 * Öffentliche Ansicht eines Beitrags (so sehen ihn ALLE). Wird sowohl für die
 * Nur-Lese-Ansicht als auch als Vorschau im Editor genutzt.
 */
$publicView = function (array $it, array $imgs) use ($imgThumb, $imgView): string {
    $type    = (string) $it['type'];
    $isPress = Communication::kindOf($type) === 'press';
    ob_start(); ?>
    <?php if ($imgs): ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <?php foreach ($imgs as $ci): $mid = (int) ($ci['media_id'] ?? 0); ?>
          <figure style="margin:0;max-width:220px">
            <?php if ($mid && !empty($ci['stored_name'])): ?>
              <a href="<?= e($imgView($mid)) ?>" target="_blank" rel="noopener"><img src="<?= e($imgThumb($mid)) ?>" alt="" style="max-width:220px;border-radius:10px;border:1px solid var(--line,#e4e7ee)"></a>
            <?php else: ?>
              <div class="muted" style="font-size:12px">(Bild nicht mehr verfügbar)</div>
            <?php endif; ?>
            <?php if (!empty($ci['caption']) || !empty($ci['photographer'])): ?>
              <figcaption class="muted" style="font-size:12px;margin-top:4px;max-width:220px">
                <?= e((string) ($ci['caption'] ?? '')) ?>
                <?php if (!empty($ci['photographer'])): ?><br><em>Foto: <?= e((string) $ci['photographer']) ?></em><?php endif; ?>
              </figcaption>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="white-space:pre-wrap"><?= e((string) ($it['body'] ?? '')) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      <?php if (!empty($it['published_url'])): ?>
        <a class="btn btn--primary btn--sm" href="<?= e((string) $it['published_url']) ?>" target="_blank" rel="noopener">↗ Zum Beitrag</a>
      <?php endif; ?>
      <?php if ($isPress): ?>
        <a class="btn btn--teal btn--sm" href="<?= url('communication_docx', ['id' => (int) $it['id']]) ?>">⬇ Word (mit Bildanhang)</a>
      <?php endif; ?>
      <?php if (!empty($it['pdf_path'])): ?>
        <a class="btn btn--ghost btn--sm" href="<?= url('communication_pdf', ['id' => (int) $it['id']]) ?>" target="_blank" rel="noopener">📄 Abgedruckte PM (PDF)</a>
      <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};

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

  <?php if ($isManager): ?>
    <!-- Sichtbarkeits-Hinweis (Werkstatt) -->
    <div class="card mb" style="border-left:4px solid #f4c430">
      <div class="card__body" style="padding:12px 16px">
        <strong>🔒 Arbeitsbereich – nur Projektleitung &amp; Admin.</strong>
        <span class="muted"> Hier entstehen die Beiträge. <strong>Erst mit „Veröffentlichen"</strong> werden sie für
        <strong>alle</strong> Beteiligten (Lehrkräfte, Jury) sichtbar. Jeder Beitrag zeigt unten die Vorschau
        <em>„So sehen es alle"</em>.</span>
      </div>
    </div>

    <!-- Aktionsleiste -->
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
  <?php else: ?>
    <p class="muted" style="max-width:80ch;margin:-4px 0 16px">
      Hier findest du die <strong>veröffentlichten</strong> Beiträge rund um den Wettbewerb – mit Bild(ern),
      Link zum Instagram-Post bzw. der Pressemitteilung als Word/PDF.
    </p>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="card"><div class="card__body">
      <p class="muted"><?= $isManager
        ? 'Noch keine Beiträge – lege oben einen an.'
        : 'Es wurden noch keine Beiträge veröffentlicht.' ?></p>
    </div></div>
  <?php endif; ?>

  <?php foreach ($items as $it):
    $type    = (string) $it['type'];
    [$stLabel, $stColor] = Communication::statusLabel((string) $it['status']);
    $isPress = Communication::kindOf($type) === 'press';
    $hasBody = trim((string) ($it['body'] ?? '')) !== '';
    $bodyId  = 'body_' . (int) $it['id'];
    $open    = $openId === (int) $it['id'];
    $imgs    = Communication::images((int) $it['id']);
    ?>

    <?php if (!$isManager): /* ===== Nur-Lese-Ansicht ===== */ ?>
      <div class="card mb">
        <div class="card__head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span><?= Communication::typeIcon($type) ?> <strong><?= e((string) $it['title']) ?></strong></span>
          <span class="muted" style="font-size:12px;margin-left:auto"><?= $it['published_at'] ? e(date('d.m.Y', strtotime((string) $it['published_at']))) : '' ?></span>
        </div>
        <div class="card__body"><?= $publicView($it, $imgs) ?></div>
      </div>
      <?php continue; ?>
    <?php endif; ?>

    <!-- ===== Verwaltungs-Ansicht (voller Editor) ===== -->
    <div class="card mb" id="item-<?= (int) $it['id'] ?>">
      <div class="card__head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span><?= Communication::typeIcon($type) ?> <strong><?= e((string) $it['title']) ?></strong></span>
        <span class="pill <?= $stColor ?>"><?= e($stLabel) ?></span>
        <?php if ((string) $it['status'] === 'published'): ?>
          <span class="muted" style="font-size:12px">👁 für alle sichtbar</span>
        <?php else: ?>
          <span class="muted" style="font-size:12px">🔒 nur Projektleitung &amp; Admin</span>
        <?php endif; ?>
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
              <div class="field"><label>Briefing – Fakten &amp; Stichpunkte für die KI</label>
                <textarea name="briefing" rows="9" placeholder="Zahlen, Platzierungen, Namen, Zitate, Sponsoren, @handles …"><?= e((string) ($it['briefing'] ?? '')) ?></textarea>
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

          <!-- Rechte Spalte: Text + Bilder -->
          <div>
            <form method="post" action="<?= url('communication') ?>">
              <?= Csrf::field() ?><input type="hidden" name="action" value="save_body"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
              <div class="field"><label>Text <span class="muted" style="font-weight:400">(bearbeitbar)</span></label>
                <textarea id="<?= $bodyId ?>" name="body" rows="12" placeholder="Noch kein Text – links generieren."><?= e((string) ($it['body'] ?? '')) ?></textarea>
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
                <button type="button" class="btn btn--ghost btn--sm" onclick="var t=document.getElementById('<?= $bodyId ?>');t.select();if(navigator.clipboard){navigator.clipboard.writeText(t.value);this.textContent='Kopiert ✓';var b=this;setTimeout(function(){b.textContent='Text kopieren'},1500);}">Text kopieren</button>
                <button class="btn btn--ghost btn--sm">Text speichern</button>
              </div>
            </form>

            <!-- Bilder aus der Mediengalerie -->
            <div class="field" style="margin-top:10px">
              <label style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span>Bilder<?= $isPress ? ' (Anhang der Pressemitteilung)' : '' ?></span>
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="galleryModal"
                  data-fill='<?= e(json_encode(['item_id' => (int) $it['id']], JSON_UNESCAPED_UNICODE)) ?>'>🖼 Bilder auswählen</button>
              </label>
              <?php if (!$imgs): ?>
                <p class="muted" style="font-size:13px;margin:6px 0 0">Noch keine Bilder gewählt. Personen bitte je Bild benennen (z. B. „v.l.n.r. …") und den Fotografen angeben.</p>
              <?php endif; ?>
              <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px">
                <?php foreach ($imgs as $ci): $mid = (int) ($ci['media_id'] ?? 0); ?>
                  <div style="display:flex;gap:10px;border:1px solid var(--line,#e4e7ee);border-radius:8px;padding:8px;background:#fff">
                    <div style="flex:0 0 auto">
                      <?php if ($mid && !empty($ci['stored_name'])): ?>
                        <a href="<?= e($imgView($mid)) ?>" target="_blank" rel="noopener"><img src="<?= e($imgThumb($mid)) ?>" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:6px"></a>
                      <?php else: ?>
                        <span class="muted" style="font-size:12px">(Bild fehlt)</span>
                      <?php endif; ?>
                    </div>
                    <form method="post" action="<?= url('communication') ?>" style="flex:1;min-width:0">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="save_image_meta"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="image_id" value="<?= (int) $ci['id'] ?>">
                      <div class="field" style="margin:0 0 6px"><label style="font-size:12px">Bildunterschrift (Personen, z. B. v.l.n.r.)</label>
                        <input type="text" name="caption" value="<?= e((string) ($ci['caption'] ?? '')) ?>" maxlength="500" placeholder="v.l.n.r. …">
                      </div>
                      <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                        <div class="field" style="margin:0;flex:1;min-width:140px"><label style="font-size:12px">Fotograf / Urheber</label>
                          <input type="text" name="photographer" value="<?= e((string) ($ci['photographer'] ?? '')) ?>" maxlength="190" placeholder="z. B. Markus Feihl">
                        </div>
                        <button class="btn btn--ghost btn--sm">Speichern</button>
                      </div>
                    </form>
                    <form method="post" action="<?= url('communication') ?>" data-confirm="Bild aus diesem Beitrag entfernen?" style="flex:0 0 auto">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="remove_image"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="image_id" value="<?= (int) $ci['id'] ?>">
                      <button class="btn btn--danger btn--sm" title="Entfernen">×</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
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

        <!-- Veröffentlichen -->
        <div style="border-top:1px solid var(--line,#e4e7ee);margin-top:14px;padding-top:14px">
          <?php $curPdf = !empty($it['pdf_path']); ?>
          <?php if ($isPress): ?>
            <div style="margin-bottom:10px">
              <a class="btn btn--teal btn--sm" href="<?= url('communication_docx', ['id' => (int) $it['id']]) ?>">⬇ Word herunterladen (Fließtext + Bildanhang)</a>
              <span class="muted" style="font-size:12px"> – die Pressemitteilung als .docx zum Versenden.</span>
            </div>
          <?php endif; ?>
          <form method="post" action="<?= url('communication') ?>" enctype="multipart/form-data">
            <?= Csrf::field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
            <div class="grid cols-2" style="align-items:end">
              <div class="field" style="margin:0">
                <label>Link zum veröffentlichten Beitrag<?= $isPress ? ' (optional)' : '' ?></label>
                <input type="text" inputmode="url" name="published_url" value="<?= e((string) ($it['published_url'] ?? '')) ?>" placeholder="https://www.instagram.com/p/…">
              </div>
              <?php if ($isPress): ?>
                <div class="field" style="margin:0">
                  <label>PDF der abgedruckten Pressemitteilung<?= $curPdf ? ' (vorhanden – neu hochladen zum Ersetzen)' : ' (optional)' ?></label>
                  <input type="file" name="pdf" accept="application/pdf">
                  <?php if ($curPdf): ?><p class="muted" style="font-size:12px;margin:4px 0 0"><a href="<?= url('communication_pdf', ['id' => (int) $it['id']]) ?>" target="_blank" rel="noopener">📄 <?= e((string) $it['pdf_name']) ?></a></p><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px">
              <?php if ((string) $it['status'] === 'published'): ?>
                <button class="btn btn--ghost btn--sm">Angaben aktualisieren</button>
                <button class="btn btn--ghost btn--sm" name="action" value="unpublish" formnovalidate>Veröffentlichung zurückziehen</button>
              <?php else: ?>
                <button class="btn btn--primary btn--sm" <?= $hasBody ? '' : 'disabled' ?>>✅ Veröffentlichen</button>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <!-- Vorschau: So sehen es alle -->
        <div style="border-top:1px dashed var(--line,#e4e7ee);margin-top:14px;padding-top:12px">
          <div style="margin-bottom:8px"><span class="pill <?= (string) $it['status'] === 'published' ? 'teal' : 'muted' ?>">👁 So sehen es alle<?= (string) $it['status'] === 'published' ? '' : ' (noch nicht veröffentlicht)' ?></span></div>
          <?php if ($hasBody): ?>
            <div style="background:#f7f9fc;border:1px solid var(--line,#e4e7ee);border-radius:10px;padding:12px"><?= $publicView($it, $imgs) ?></div>
          <?php else: ?>
            <p class="muted" style="font-size:13px">Sobald ein Text generiert ist, erscheint hier die Vorschau.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
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
          <p class="muted" style="font-size:13px">Das Briefing wird mit den bekannten Fakten (Kennzahlen, Finalteams, Sponsoren, @handles …) vorbefüllt – du kannst es danach anpassen.</p>
          <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Anlegen</button></div>
        </form>
      </div>
    </div>

    <div class="modal-overlay" id="guidanceModal" data-modal-static hidden>
      <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="guidanceModalTitle">
        <div class="modal__head"><h3 id="guidanceModalTitle">Stil-Hinweise für die KI</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
        <form method="post" action="<?= url('communication') ?>" class="modal__body" data-modal-form>
          <?= Csrf::field() ?><input type="hidden" name="action" value="save_guidance"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <div class="field"><label>Dauerhafte Hinweise (Tonalität, Do's &amp; Don'ts)</label>
            <textarea name="guidance" rows="5" placeholder="z. B. immer gendern, Stadthalle Ebermannstadt erwähnen, kein Ausrufezeichen im Titel"><?= e($guidance) ?></textarea>
          </div>
          <p class="muted" style="font-size:13px">Diese Hinweise fließen in jede Generierung ein (für alle Beiträge).</p>
          <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
        </form>
      </div>
    </div>

    <!-- Bild-Auswahl aus der Mediengalerie (Mehrfachauswahl) -->
    <div class="modal-overlay" id="galleryModal" data-modal-static hidden>
      <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="galleryModalTitle" style="max-width:860px">
        <div class="modal__head"><h3 id="galleryModalTitle">Bilder auswählen</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
        <form method="post" action="<?= url('communication') ?>" class="modal__body" data-modal-form>
          <?= Csrf::field() ?><input type="hidden" name="action" value="attach_images"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="item_id" value="0">
          <?php if (!$gallery): ?>
            <p class="muted">Noch keine Bilder in der <a href="<?= url('gallery') ?>">Mediengalerie</a> dieses Jahres. Lade dort zuerst Fotos hoch.</p>
          <?php else: ?>
            <p class="muted" style="font-size:13px;margin:0 0 10px">Mehrfachauswahl möglich – zum Vergrößern auf ein Bild klicken. Bereits angehängte Bilder werden übersprungen.</p>
            <div class="imgpick">
              <?php foreach ($gallery as $g):
                $lbl = trim((string) ($g['title'] ?: $g['original_name'] ?: ('Bild #' . $g['id']))); ?>
                <label class="imgpick__item">
                  <input type="checkbox" name="media_ids[]" value="<?= (int) $g['id'] ?>">
                  <img src="<?= e($imgThumb((int) $g['id'])) ?>" alt="<?= e($lbl) ?>" loading="lazy">
                  <span class="imgpick__cap"><?= e(mb_substr($lbl, 0, 40)) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary"<?= $gallery ? '' : ' disabled' ?>>Auswahl übernehmen</button></div>
        </form>
      </div>
    </div>

    <style>
      .imgpick{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;max-height:60vh;overflow:auto;padding:2px}
      .imgpick__item{position:relative;display:block;cursor:pointer;border:2px solid var(--line,#e4e7ee);border-radius:10px;overflow:hidden;background:#fff}
      .imgpick__item img{display:block;width:100%;height:130px;object-fit:cover}
      .imgpick__item input{position:absolute;top:8px;left:8px;width:20px;height:20px;z-index:2;cursor:pointer}
      .imgpick__cap{display:block;font-size:12px;padding:5px 8px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .imgpick__item:has(input:checked){border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.25)}
      .imgpick__item:has(input:checked)::after{content:"✓";position:absolute;top:6px;right:8px;background:#003594;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:14px;z-index:2}
    </style>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Kommunikation';
require APP_PATH . '/pages/_layout.php';
