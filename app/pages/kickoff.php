<?php
/**
 * Kick-Off je Wettbewerbsjahr.
 *
 * Kernaufgabe: die Terminschiene (Meilensteine des Zyklus) abstimmen und
 * fixieren. Die Verwaltung pflegt hier den Projektablauf, hält ihn per
 * „Terminplan fixieren" fest und dokumentiert das Kick-Off-Meeting im Protokoll.
 * Die Meilensteine sind dieselben, die als Zeitleiste auf dem Dashboard und unter
 * „Wettbewerbsjahre" erscheinen – hier im Kick-Off-Kontext gebündelt.
 */

declare(strict_types=1);

Access::requireRead('kickoff');

$cycles        = Cycle::all();
$activeCycleId = Cycle::activeId();

$cycleId = (int) input('cycle', $activeCycleId);
if ($cycleId <= 0 || Cycle::find($cycleId) === null) {
    $cycleId = $activeCycleId;
}
$cycle   = $cycleId ? Cycle::find($cycleId) : null;
$meeting = $cycleId ? Meeting::ensure($cycleId, 'kickoff') : null;

$back = fn() => redirect(url('kickoff', ['cycle' => $cycleId]));

if (is_post()) {
    Access::requireWrite('kickoff');
    Csrf::check();
    if (!$cycle) {
        flash('error', 'Kein Wettbewerbsjahr ausgewählt.');
        $back();
    }
    $action = (string) input('action');

    if ($action === 'save_meeting') {
        $title = trim((string) input('title')) ?: Meeting::defaultTitle('kickoff');
        $date  = trim((string) input('meeting_date')) ?: null;
        $time  = trim((string) input('meeting_time')) ?: null;
        $loc   = trim((string) input('location')) ?: null;
        Database::run(
            'UPDATE project_meetings SET title=?, meeting_date=?, meeting_time=?, location=? WHERE cycle_id=? AND type="kickoff"',
            [$title, $date, $time, $loc, $cycleId]
        );
        Audit::log('kickoff.update', 'Kick-Off-Eckdaten gespeichert', 'cycle', $cycleId);
        flash('success', 'Eckdaten gespeichert.');
        $back();
    }

    if ($action === 'save_protocol') {
        $protocol = trim((string) input('protocol')) ?: null;
        Database::run(
            'UPDATE project_meetings SET protocol=? WHERE cycle_id=? AND type="kickoff"',
            [$protocol, $cycleId]
        );
        Audit::log('kickoff.protocol', 'Kick-Off-Protokoll gespeichert', 'cycle', $cycleId);
        flash('success', 'Protokoll gespeichert.');
        $back();
    }

    if ($action === 'toggle_fix') {
        $fix = (int) input('fix') === 1;
        Database::run(
            'UPDATE project_meetings SET schedule_fixed_at=' . ($fix ? 'NOW()' : 'NULL') . ' WHERE cycle_id=? AND type="kickoff"',
            [$cycleId]
        );
        Audit::log('kickoff.fix', $fix ? 'Terminplan fixiert' : 'Fixierung des Terminplans aufgehoben', 'cycle', $cycleId);
        flash('success', $fix
            ? 'Terminplan fixiert – die Termine gelten jetzt als verbindlich abgestimmt.'
            : 'Fixierung aufgehoben – der Terminplan kann wieder frei angepasst werden.');
        $back();
    }

    // --- Meilensteine (= Terminschiene) ---
    if ($action === 'milestone_save') {
        $mid    = (int) input('milestone_id', 0);
        $label  = trim((string) input('label'));
        $from   = trim((string) input('date_from'));
        $to     = trim((string) input('date_to'));
        $period = trim((string) input('period_label'));
        $status = (string) input('status', 'auto');
        $sort   = (int) input('sort_order', 0);
        if (!in_array($status, Cycle::MILESTONE_STATUS, true)) {
            $status = 'auto';
        }
        if ($label === '') {
            flash('error', 'Bitte einen Namen für den Termin angeben.');
            $back();
        }
        if ($mid > 0) {
            Database::run(
                'UPDATE cycle_milestones SET label=?, date_from=?, date_to=?, period_label=?, status=?, sort_order=?
                 WHERE id=? AND cycle_id=?',
                [$label, $from ?: null, $to ?: null, $period ?: null, $status, $sort, $mid, $cycleId]
            );
            Audit::log('kickoff.milestone_update', 'Termin bearbeitet: ' . $label, 'cycle', $cycleId);
            flash('success', 'Termin aktualisiert.');
        } else {
            Database::insert(
                'INSERT INTO cycle_milestones (cycle_id, label, date_from, date_to, period_label, status, sort_order)
                 VALUES (?,?,?,?,?,?,?)',
                [$cycleId, $label, $from ?: null, $to ?: null, $period ?: null, $status, $sort]
            );
            Audit::log('kickoff.milestone_create', 'Termin hinzugefügt: ' . $label, 'cycle', $cycleId);
            flash('success', 'Termin hinzugefügt.');
        }
        $back();
    }

    if ($action === 'milestone_delete') {
        $mid  = (int) input('milestone_id', 0);
        $mlbl = (string) Database::value('SELECT label FROM cycle_milestones WHERE id=? AND cycle_id=?', [$mid, $cycleId]);
        Database::run('DELETE FROM cycle_milestones WHERE id=? AND cycle_id=?', [$mid, $cycleId]);
        Audit::log('kickoff.milestone_delete', 'Termin gelöscht: ' . ($mlbl ?: ('#' . $mid)), 'cycle', $cycleId);
        flash('success', 'Termin gelöscht.');
        $back();
    }

    $back();
}

// ------------------------------------------------------------------ Ansicht
$canEdit    = Access::canWrite('kickoff');
$dateFmt    = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : null;
$timeFmt    = fn(?string $t) => $t ? substr($t, 0, 5) : null;
$milestones = $cycleId ? Cycle::milestones($cycleId) : [];
$fixed      = $meeting && !empty($meeting['schedule_fixed_at']);
$statusLbl  = ['auto' => 'Automatisch', 'done' => 'Erledigt', 'active' => 'Aktiv', 'upcoming' => 'Bevorstehend'];

$cycleSwitcher = function () use ($cycles, $cycleId) {
    if (count($cycles) < 2) {
        return '';
    }
    ob_start(); ?>
    <form method="get" action="<?= url('kickoff') ?>" style="display:inline">
      <input type="hidden" name="r" value="kickoff">
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
  <h1>🚀 Kick-Off<?= $cycle ? ' <span class="muted" style="font-weight:400;font-size:.7em">' . e($cycle['year_label']) . '</span>' : '' ?></h1>
  <?= $cycleSwitcher() ?>
</div>

<?php if (!$cycleId): ?>
  <div class="card"><div class="card__body">
    <p class="muted">Zuerst unter <a href="<?= url('cycles') ?>">Wettbewerbsjahre</a> ein Jahr anlegen und aktiv setzen.</p>
  </div></div>
<?php else: ?>

  <p class="muted" style="max-width:75ch;margin:-4px 0 16px">
    Beim Kick-Off wird die <strong>Terminschiene abgestimmt und fixiert</strong>. Die hier gepflegten Termine
    erscheinen als Zeitleiste „Projektablauf" auf dem Dashboard aller Beteiligten.
  </p>

  <!-- Eckdaten des Kick-Off-Meetings -->
  <div class="card mb">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
      <span>Kick-Off-Meeting</span>
      <?php if ($canEdit): ?>
        <button type="button" class="btn btn--ghost btn--sm" data-modal-open="kickoffModal"
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
        <div class="field" style="margin:0"><label>Terminplan</label>
          <div><?= $fixed
            ? '<span class="pill teal">✔ fixiert</span> <span class="muted" style="font-size:12px">am ' . e(date('d.m.Y', strtotime((string) $meeting['schedule_fixed_at']))) . '</span>'
            : '<span class="pill amber">noch nicht fixiert</span>' ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Terminschiene / Meilensteine -->
  <div class="card mb">
    <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <span>Terminschiene &amp; Meilensteine</span>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($canEdit): ?>
          <form method="post" action="<?= url('kickoff') ?>" style="display:inline"<?= $fixed ? ' data-confirm="Fixierung aufheben? Die Termine können danach wieder frei geändert werden."' : '' ?>>
            <?= Csrf::field() ?><input type="hidden" name="action" value="toggle_fix"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="fix" value="<?= $fixed ? '0' : '1' ?>">
            <button class="btn <?= $fixed ? 'btn--ghost' : 'btn--teal' ?> btn--sm"><?= $fixed ? '🔓 Fixierung aufheben' : '📌 Terminplan fixieren' ?></button>
          </form>
          <button type="button" class="btn btn--primary btn--sm" data-modal-open="milestoneModal">+ Termin</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data data--cards">
        <thead><tr><th>Reihenfolge</th><th>Termin / Meilenstein</th><th>Zeitpunkt / Zeitraum</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($milestones as $m): $miid = (int) $m['id'];
          $fillM = e(json_encode([
            'milestone_id' => $miid, 'label' => $m['label'], 'date_from' => $m['date_from'],
            'date_to' => $m['date_to'], 'period_label' => $m['period_label'],
            'status' => $m['status'], 'sort_order' => (int) $m['sort_order'],
          ], JSON_UNESCAPED_UNICODE));
          $state = Cycle::milestoneState($m);
        ?>
          <tr>
            <td data-label="Reihenfolge"><span class="muted"><?= (int) $m['sort_order'] ?></span></td>
            <td data-label="Termin"><strong><?= e($m['label']) ?></strong></td>
            <td data-label="Zeitpunkt / Zeitraum"><?= e(Cycle::milestoneDateLabel($m)) ?: '<span class="muted">–</span>' ?></td>
            <td data-label="Status"><span class="pill <?= $state === 'done' ? 'teal' : ($state === 'active' ? 'blue' : 'muted') ?>"><?= e($statusLbl[$m['status']] ?? $m['status']) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <button type="button" class="btn btn--ghost btn--sm" data-modal-open="milestoneModal" data-edit data-fill="<?= $fillM ?>">Bearbeiten</button>
              <form method="post" action="<?= url('kickoff') ?>" style="display:inline" data-confirm="Termin „<?= e($m['label']) ?>“ löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="milestone_delete"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="milestone_id" value="<?= $miid ?>">
                <button class="btn btn--danger btn--sm">×</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$milestones): ?><tr><td colspan="<?= $canEdit ? 5 : 4 ?>" class="muted">Noch keine Termine – <?= $canEdit ? 'über „+ Termin" die Terminschiene aufbauen.' : 'die Projektleitung stimmt sie beim Kick-Off ab.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($fixed): ?>
      <div class="card__body" style="padding-top:0"><p class="muted" style="font-size:13px;margin:0">📌 Der Terminplan ist fixiert (am <?= e(date('d.m.Y', strtotime((string) $meeting['schedule_fixed_at']))) ?>) – als verbindlich abgestimmt gekennzeichnet. Änderungen bleiben möglich, sollten aber abgestimmt werden.</p></div>
    <?php endif; ?>
  </div>

  <!-- Protokoll -->
  <div class="card">
    <div class="card__head">Protokoll / Notizen</div>
    <div class="card__body">
      <?php if ($canEdit): ?>
        <form method="post" action="<?= url('kickoff') ?>">
          <?= Csrf::field() ?><input type="hidden" name="action" value="save_protocol"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
          <div class="field"><textarea name="protocol" rows="8" placeholder="Ergebnisse und Absprachen des Kick-Off-Meetings festhalten…"><?= e((string) ($meeting['protocol'] ?? '')) ?></textarea></div>
          <div style="text-align:right"><button class="btn btn--primary">Protokoll speichern</button></div>
        </form>
      <?php elseif (!empty($meeting['protocol'])): ?>
        <div style="white-space:pre-wrap"><?= e((string) $meeting['protocol']) ?></div>
      <?php else: ?>
        <p class="muted">Noch kein Protokoll hinterlegt.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canEdit): ?>
  <!-- ===================== Modals ===================== -->
  <div class="modal-overlay" id="kickoffModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="kickoffModalTitle">
      <div class="modal__head"><h3 id="kickoffModalTitle">Kick-Off-Meeting bearbeiten</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('kickoff') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save_meeting"><input type="hidden" name="cycle" value="<?= $cycleId ?>">
        <div class="field"><label>Titel</label><input type="text" name="title" value="Kick-Off"></div>
        <div class="grid cols-2">
          <div class="field"><label>Datum</label><input type="date" name="meeting_date"></div>
          <div class="field"><label>Uhrzeit</label><input type="time" name="meeting_time"></div>
        </div>
        <div class="field"><label>Ort</label><input type="text" name="location" placeholder="z. B. Sparkasse Forchheim"></div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary">Speichern</button></div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="milestoneModal" data-modal-static hidden>
    <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="milestoneModalTitle">
      <div class="modal__head"><h3 id="milestoneModalTitle" data-modal-title data-title-new="Neuer Termin" data-title-edit="Termin bearbeiten">Neuer Termin</h3><button type="button" class="modal__close" data-modal-close>&times;</button></div>
      <form method="post" action="<?= url('kickoff') ?>" class="modal__body" data-modal-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="milestone_save"><input type="hidden" name="cycle" value="<?= $cycleId ?>"><input type="hidden" name="milestone_id" value="0">
        <div class="field"><label>Termin / Meilenstein *</label><input type="text" name="label" required placeholder="z. B. Einsendeschluss"></div>
        <div class="grid cols-2">
          <div class="field"><label>Von (Datum)</label><input type="date" name="date_from"></div>
          <div class="field"><label>Bis (Datum, optional)</label><input type="date" name="date_to"></div>
        </div>
        <div class="field"><label>Zeitraum-Text (statt Datum, optional)</label><input type="text" name="period_label" placeholder="z. B. „KW 40" oder „Oktober"></div>
        <div class="grid cols-2">
          <div class="field"><label>Status</label><select name="status">
            <option value="auto">Automatisch (aus Datum)</option>
            <option value="upcoming">Bevorstehend</option>
            <option value="active">Aktiv</option>
            <option value="done">Erledigt</option>
          </select></div>
          <div class="field"><label>Reihenfolge</label><input type="number" name="sort_order" value="0" step="1"></div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button><button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Kick-Off';
require APP_PATH . '/pages/_layout.php';
