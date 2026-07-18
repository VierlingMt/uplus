<?php
/**
 * Project-Closing (Abschluss-Retrospektive) je Wettbewerbsjahr.
 *
 * JEDE beteiligte Person (Projektleitung, Jury, Lehrkräfte) hält eigene
 * Rückmeldungen in drei Kategorien fest: „Was lief gut", „Was lief schlecht",
 * „Was können wir verbessern". Beim Abschlusstermin lässt die Verwaltung die
 * gesammelten Notizen per KI zu Themen clustern und zusammenfassen, um sie
 * gemeinsam zu besprechen. Ein Protokoll hält die Ergebnisse fest.
 *
 * Rechte: Schreiben (eigene Notizen) für alle; KI-Cluster, Protokoll, Eckdaten
 * und das Löschen fremder Notizen nur für die Verwaltung (Auth::isManager()).
 */

declare(strict_types=1);

Access::requireRead('closing');

$cycles        = Cycle::all();
$activeCycleId = Cycle::activeId();

$cycleId = (int) input('cycle', $activeCycleId);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = $activeCycleId;
}
$cycle     = $cycleId ? Cycle::find($cycleId) : null;
$meeting   = $cycleId ? Meeting::ensure($cycleId, 'closing') : null;
$isManager = Auth::isManager();

$back = fn() => redirect(url('closing', ['cycle' => $cycleId]));

if (is_post()) {
    Access::requireWrite('closing');
    Csrf::check();
    if (!$cycle) {
        flash('error', 'Kein Wettbewerbsjahr ausgewählt.');
        $back();
    }
    $action = (string) input('action');

    // --- Eigene Notiz anlegen / bearbeiten (jede beteiligte Person) ---
    if ($action === 'note_save') {
        $id   = (int) input('note_id', 0);
        $cat  = (string) input('category');
        $body = trim((string) input('body'));
        if (!Meeting::validCategory($cat)) {
            flash('error', 'Unbekannte Kategorie.');
            $back();
        }
        if ($body === '') {
            flash('error', 'Bitte einen Text eingeben.');
            $back();
        }
        $body = mb_substr($body, 0, 2000);
        if ($id > 0) {
            $note = Database::one('SELECT * FROM retro_notes WHERE id=? AND cycle_id=?', [$id, $cycleId]);
            if (!$note || (!$isManager && (int) $note['user_id'] !== Auth::id())) {
                flash('error', 'Diese Notiz kann nicht bearbeitet werden.');
                $back();
            }
            Database::run('UPDATE retro_notes SET category=?, body=? WHERE id=? AND cycle_id=?', [$cat, $body, $id, $cycleId]);
            flash('success', 'Notiz aktualisiert.');
        } else {
            Database::insert(
                'INSERT INTO retro_notes (cycle_id, user_id, category, body) VALUES (?,?,?,?)',
                [$cycleId, Auth::id(), $cat, $body]
            );
            flash('success', 'Danke für deine Rückmeldung!');
        }
        Audit::log('closing.note_save', 'Retro-Notiz gespeichert (' . Meeting::categoryLabel($cat) . ')', 'cycle', $cycleId);
        $back();
    }

    if ($action === 'note_delete') {
        $id   = (int) input('note_id', 0);
        $note = Database::one('SELECT * FROM retro_notes WHERE id=? AND cycle_id=?', [$id, $cycleId]);
        if (!$note || (!$isManager && (int) $note['user_id'] !== Auth::id())) {
            flash('error', 'Diese Notiz kann nicht gelöscht werden.');
            $back();
        }
        Database::run('DELETE FROM retro_notes WHERE id=? AND cycle_id=?', [$id, $cycleId]);
        Audit::log('closing.note_delete', 'Retro-Notiz gelöscht', 'cycle', $cycleId);
        flash('success', 'Notiz gelöscht.');
        $back();
    }

    // --- Verwaltungsaktionen ---
    if (!$isManager) {
        // Für alle weiteren Aktionen fehlt Nicht-Verwaltern die Berechtigung.
        flash('error', 'Diese Aktion ist der Projektleitung vorbehalten.');
        $back();
    }

    if ($action === 'save_meeting') {
        $title = trim((string) input('title')) ?: Meeting::defaultTitle('closing');
        $date  = trim((string) input('meeting_date')) ?: null;
        $time  = trim((string) input('meeting_time')) ?: null;
        $loc   = trim((string) input('location')) ?: null;
        Database::run(
            'UPDATE project_meetings SET title=?, meeting_date=?, meeting_time=?, location=? WHERE cycle_id=? AND type="closing"',
            [$title, $date, $time, $loc, $cycleId]
        );
        Audit::log('closing.update', 'Project-Closing-Eckdaten gespeichert', 'cycle', $cycleId);
        flash('success', 'Eckdaten gespeichert.');
        $back();
    }

    if ($action === 'save_protocol') {
        $protocol = trim((string) input('protocol')) ?: null;
        Database::run('UPDATE project_meetings SET protocol=? WHERE cycle_id=? AND type="closing"', [$protocol, $cycleId]);
        Audit::log('closing.protocol', 'Project-Closing-Protokoll gespeichert', 'cycle', $cycleId);
        flash('success', 'Protokoll gespeichert.');
        $back();
    }

    if ($action === 'run_cluster') {
        // KI-Aufruf kann dauern: Session freigeben, damit die App nicht blockiert.
        session_write_close();
        @set_time_limit(180);
        $res = Meeting::runClustering($cycleId);
        if ($res['ok']) {
            Audit::log('closing.cluster', 'Retro per KI geclustert (' . (int) ($res['themes'] ?? 0) . ' Themen)', 'cycle', $cycleId);
        }
        flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? ('KI-Cluster erstellt: ' . (int) ($res['themes'] ?? 0) . ' Themen.') : ('KI-Fehler: ' . ($res['error'] ?? 'unbekannt')));
        $back();
    }

    $back();
}

// ------------------------------------------------------------------ Ansicht
$dateFmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$stats   = $cycleId ? Meeting::noteStats($cycleId) : ['total' => 0, 'good' => 0, 'bad' => 0, 'improve' => 0, 'people' => 0];
$cluster = Meeting::clusterData($meeting);

// Eigene Notizen (für das persönliche Retro-Board).
$myByCat = ['good' => [], 'bad' => [], 'improve' => []];
foreach ($cycleId ? Meeting::notesByUser($cycleId, Auth::id()) : [] as $n) {
    $myByCat[$n['category']][] = $n;
}
// Alle Notizen (nur Verwaltung sieht die Gesamtübersicht).
$allByCat = ['good' => [], 'bad' => [], 'improve' => []];
if ($isManager && $cycleId) {
    foreach (Meeting::notes($cycleId) as $n) {
        $allByCat[$n['category']][] = $n;
    }
}

$cycleSwitcher = function () use ($cycles, $cycleId) {
    if (count($cycles) < 2) {
        return '';
    }
    ob_start(); ?>
    <form method="get" action="<?= url('closing') ?>" style="display:inline">
      <input type="hidden" name="r" value="closing">
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
  <h1>🎯 Project-Closing<?= $cycle ? ' <span class="muted" style="font-weight:400;font-size:.7em">' . e($cycle['year_label']) . '</span>' : '' ?></h1>
  <?= $cycleSwitcher() ?>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Zuerst unter <a href="<?= url('cycles') ?>">Wettbewerbsjahre</a> ein Jahr anlegen und aktiv setzen.</p>
  </div></div>
<?php else: ?>

  <p class="muted" style="max-width:75ch;margin:-4px 0 16px">
    Abschluss-Retrospektive: Alle Beteiligten halten fest, <strong>was gut lief, was schlecht lief und was wir
    verbessern können</strong>. Beim Abschlusstermin werden die gesammelten Rückmeldungen per KI zu Themen
    geclustert und gemeinsam besprochen. Deine Einträge sind nur für dich und die Projektleitung sichtbar.
  </p>

  <!-- Eckdaten -->
  <div class="card mb">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <span>Abschlusstermin</span>
      <?php if ($isManager): ?>
        <button type="button" class="btn btn--ghost btn--sm" data-modal-open="closingModal"
          data-fill='<?= e(json_encode([
            'title' => $meeting['title'], 'meeting_date' => $meeting['meeting_date'],
            'meeting_time' => $timeFmt($meeting['meeting_time']), 'location' => $meeting['location'],
          ], JSON_UNESCAPED_UNICODE)) ?>'>Bearbeiten</button>
      <?php endif; ?>
    </div>
    <div class="card__body">
      <div class="grid cols-3">
        <div class="field" style="margin:0"><label>Datum</label>
          <div><?= $meeting['meeting_date'] ? '<strong>' . e($dateFmt($meeting['meeting_date'])) . '</strong>' : '<span class="pill amber">offen</span>' ?>
            <?= $meeting['meeting_time'] ? ' · ' . e($timeFmt($meeting['meeting_time'])) . ' Uhr' : '' ?></div>
        </div>
        <div class="field" style="margin:0"><label>Ort</label><div><?= e($meeting['location'] ?: '—') ?></div></div>
        <div class="field" style="margin:0"><label>Rückmeldungen</label>
          <div><strong><?= (int) $stats['total'] ?></strong> von <strong><?= (int) $stats['people'] ?></strong> Person(en)</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Persönliches Retro-Board -->
  <div class="card mb">
    <div class="card__head">Meine Rückmeldung</div>
    <div class="card__body">
      <div class="grid cols-3">
        <?php foreach (Meeting::RETRO_CATEGORIES as $cat => [$label, $color, $icon]): ?>
          <div class="field" style="margin:0">
            <label><span class="pill <?= $color ?>"><?= $icon ?> <?= e($label) ?></span></label>
            <div style="display:flex;flex-direction:column;gap:8px;margin:10px 0">
              <?php foreach ($myByCat[$cat] as $n):
                $fill = e(json_encode(['note_id' => (int) $n['id'], 'category' => $n['category'], 'body' => $n['body']], JSON_UNESCAPED_UNICODE)); ?>
                <div style="border:1px solid var(--line,#e4e7ee);border-radius:8px;padding:8px 10px;background:#fff">
                  <div style="white-space:pre-wrap;font-size:14px"><?= e((string) $n['body']) ?></div>
                  <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:6px">
                    <button type="button" class="btn btn--ghost btn--sm" data-modal-open="noteModal" data-fill="<?= $fill ?>">Bearbeiten</button>
                    <form method="post" action="<?= url('closing') ?>" style="display:inline" data-confirm="Notiz löschen?">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="note_delete"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                      <button class="btn btn--danger btn--sm">×</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <form method="post" action="<?= url('closing') ?>">
              <?= Csrf::field() ?><input type="hidden" name="action" value="note_save"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="note_id" value="0"><input type="hidden" name="category" value="<?= e($cat) ?>">
              <textarea name="body" rows="2" placeholder="Punkt hinzufügen…" style="width:100%"></textarea>
              <div style="text-align:right;margin-top:6px"><button class="btn btn--ghost btn--sm">+ hinzufügen</button></div>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($isManager): ?>
    <!-- KI-Clustering -->
    <div class="card mb">
      <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <span>KI-Auswertung</span>
        <form method="post" action="<?= url('closing') ?>" data-confirm="Alle Rückmeldungen jetzt per KI clustern und zusammenfassen?">
          <?= Csrf::field() ?><input type="hidden" name="action" value="run_cluster"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <button class="btn btn--teal btn--sm" <?= (int) $stats['total'] === 0 ? 'disabled' : '' ?>>✨ <?= $cluster ? 'Neu clustern' : 'Per KI clustern & zusammenfassen' ?></button>
        </form>
      </div>
      <div class="card__body">
        <?php if ((int) $stats['total'] === 0): ?>
          <p class="muted">Noch keine Rückmeldungen – sobald Notizen vorliegen, lassen sie sich hier zu Themen clustern.</p>
        <?php elseif (!$cluster): ?>
          <p class="muted"><?= (int) $stats['total'] ?> Rückmeldung(en) von <?= (int) $stats['people'] ?> Person(en) gesammelt. Mit „Per KI clustern" werden sie thematisch zusammengefasst – ideal als Grundlage fürs Abschlussgespräch.</p>
        <?php else: ?>
          <p class="muted" style="font-size:13px;margin:0 0 4px">Zuletzt erstellt am <?= e(date('d.m.Y, H:i', strtotime((string) $meeting['ai_generated_at']))) ?> Uhr<?= $meeting['ai_model'] ? ' · Modell ' . e((string) $meeting['ai_model']) : '' ?>.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($cluster): ?>
      <div class="card mb">
        <div class="card__head">Themen (KI-Cluster)</div>
        <div class="card__body">
          <?php foreach (Meeting::RETRO_CATEGORIES as $cat => [$label, $color, $icon]):
            $themes = array_values(array_filter($cluster['themes'] ?? [], fn($t) => ($t['category'] ?? '') === $cat));
            if (!$themes) continue; ?>
            <div style="margin-bottom:16px">
              <div style="margin-bottom:8px"><span class="pill <?= $color ?>"><?= $icon ?> <?= e($label) ?></span></div>
              <?php foreach ($themes as $t): ?>
                <div style="border-left:3px solid var(--line,#e4e7ee);padding:4px 0 4px 12px;margin-bottom:10px">
                  <strong><?= e((string) ($t['title'] ?? '')) ?></strong>
                  <?php if (!empty($t['mentions'])): ?><span class="muted" style="font-size:12px"> · <?= (int) $t['mentions'] ?>×</span><?php endif; ?>
                  <?php if (!empty($t['summary'])): ?><div class="muted" style="font-size:14px;margin-top:2px"><?= e((string) $t['summary']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>

          <?php if (!empty($cluster['action_items'])): ?>
            <div style="margin-top:8px">
              <div style="margin-bottom:8px"><span class="pill blue">✅ Konkrete Verbesserungen fürs nächste Jahr</span></div>
              <ul style="margin:0;padding-left:20px">
                <?php foreach ($cluster['action_items'] as $a): ?><li style="margin-bottom:4px"><?= e((string) $a) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if (!empty($cluster['overall'])): ?>
            <p style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line,#e4e7ee)"><strong>Gesamtfazit:</strong> <?= e((string) $cluster['overall']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Alle Rückmeldungen (Rohdaten für die Verwaltung) -->
    <div class="card mb">
      <div class="card__head">Alle Rückmeldungen <span class="muted" style="font-weight:400;font-size:13px">· <?= (int) $stats['total'] ?> gesamt</span></div>
      <div class="card__body">
        <div class="grid cols-3">
          <?php foreach (Meeting::RETRO_CATEGORIES as $cat => [$label, $color, $icon]): ?>
            <div>
              <div style="margin-bottom:8px"><span class="pill <?= $color ?>"><?= $icon ?> <?= e($label) ?> (<?= count($allByCat[$cat]) ?>)</span></div>
              <?php foreach ($allByCat[$cat] as $n): ?>
                <div style="border:1px solid var(--line,#e4e7ee);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:#fff">
                  <div style="white-space:pre-wrap;font-size:14px"><?= e((string) $n['body']) ?></div>
                  <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-top:6px">
                    <span class="muted" style="font-size:12px"><?= e((string) ($n['author'] ?? 'Unbekannt')) ?></span>
                    <form method="post" action="<?= url('closing') ?>" style="display:inline" data-confirm="Notiz löschen?">
                      <?= Csrf::field() ?><input type="hidden" name="action" value="note_delete"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="note_id" value="<?= (int) $n['id'] ?>">
                      <button class="btn btn--danger btn--sm">×</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$allByCat[$cat]): ?><p class="muted" style="font-size:13px">–</p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php elseif ($cluster): ?>
    <!-- Für Beteiligte: das gemeinsame Ergebnis (nach dem Abschlusstermin) -->
    <div class="card mb">
      <div class="card__head">Gemeinsames Ergebnis</div>
      <div class="card__body">
        <?php if (!empty($cluster['overall'])): ?><p><?= e((string) $cluster['overall']) ?></p><?php endif; ?>
        <?php if (!empty($cluster['action_items'])): ?>
          <div style="margin-top:8px"><span class="pill blue">✅ Verbesserungen fürs nächste Jahr</span>
            <ul style="margin:8px 0 0;padding-left:20px">
              <?php foreach ($cluster['action_items'] as $a): ?><li style="margin-bottom:4px"><?= e((string) $a) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Protokoll -->
  <div class="card">
    <div class="card__head">Protokoll / Notizen</div>
    <div class="card__body">
      <?php if ($isManager): ?>
        <form method="post" action="<?= url('closing') ?>">
          <?= Csrf::field() ?><input type="hidden" name="action" value="save_protocol"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <div class="field"><textarea name="protocol" rows="8" placeholder="Ergebnisse und Beschlüsse des Abschlusstermins festhalten…"><?= e((string) ($meeting['protocol'] ?? '')) ?></textarea></div>
          <div style="text-align:right"><button class="btn btn--primary">Protokoll speichern</button></div>
        </form>
      <?php elseif (!empty($meeting['protocol'])): ?>
        <div style="white-space:pre-wrap"><?= e((string) $meeting['protocol']) ?></div>
      <?php else: ?>
        <p class="muted">Noch kein Protokoll hinterlegt.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===================== Modals ===================== -->
  <div class="modal-overlay" id="noteModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="noteModalTitle">
      <div class="modal__head"><h3 id="noteModalTitle">Notiz bearbeiten</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('closing') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="note_save"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="note_id" value="0">
        <div class="field"><label>Kategorie</label><select name="category">
          <?php foreach (Meeting::RETRO_CATEGORIES as $cat => [$label, $color, $icon]): ?><option value="<?= e($cat) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Text *</label><textarea name="body" rows="4" required></textarea></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
      </form>
    </div>
  </div>

  <?php if ($isManager): ?>
  <div class="modal-overlay" id="closingModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="closingModalTitle">
      <div class="modal__head"><h3 id="closingModalTitle">Abschlusstermin bearbeiten</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('closing') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_meeting"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
        <div class="field"><label>Titel</label><input type="text" name="title" value="Project-Closing"></div>
        <div class="grid cols-2">
          <div class="field"><label>Datum</label><input type="date" name="meeting_date"></div>
          <div class="field"><label>Uhrzeit</label><input type="time" name="meeting_time"></div>
        </div>
        <div class="field"><label>Ort</label><input type="text" name="location"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Project-Closing';
require APP_PATH . '/pages/_layout.php';
