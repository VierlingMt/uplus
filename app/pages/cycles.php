<?php
/**
 * Wettbewerbszyklen (Wettbewerbsjahre) – zentrale Anlage & Verwaltung.
 * Hier wird ein neues Wettbewerbsjahr angelegt; Jury, Projektleitung und
 * Schulen werden je Jahr zugeordnet. Frühere Jahre bleiben als Historie
 * erhalten (nur Projektleitung).
 */
declare(strict_types=1);

Auth::requireManager();

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');
    $id     = (int) input('id', 0);

    if ($action === 'delete') {
        $yl = (string) Database::value('SELECT year_label FROM competition_cycles WHERE id = ?', [$id]);
        Database::run('DELETE FROM competition_cycles WHERE id = ?', [$id]);
        Audit::log('cycle.delete', 'Wettbewerbsjahr gelöscht: ' . ($yl ?: ('#' . $id)), 'cycle', $id);
        flash('success', 'Wettbewerbsjahr gelöscht.');
        redirect(url('cycles'));
    }

    if ($action === 'activate') {
        Cycle::setActive($id);
        Audit::log('cycle.activate', 'Aktives Wettbewerbsjahr gesetzt (#' . $id . ')', 'cycle', $id);
        flash('success', 'Aktives Wettbewerbsjahr gesetzt.');
        redirect(url('cycles', ['cycle' => $id]));
    }

    if ($action === 'members') {
        if (!Cycle::find($id)) {
            flash('error', 'Wettbewerbsjahr nicht gefunden.');
            redirect(url('cycles'));
        }
        $jurors = array_map('intval', (array) input('jurors', []));
        $leads  = array_map('intval', (array) input('leads', []));
        $schools = array_map('intval', (array) input('schools', []));

        // Über dieses Formular werden nur Jury- und Projektleitungs-Zuordnungen (lead)
        // verwaltet. Zuordnungen von Admin-Konten (Eigentümer) bleiben unangetastet.
        $keep = [];
        foreach ($jurors as $uid) { $keep[$uid] = 'juror'; }
        foreach ($leads  as $uid) { $keep[$uid] = 'project_lead'; }

        $existing = array_map(
            static fn($r) => (int) $r['user_id'],
            Database::all(
                "SELECT cm.user_id FROM cycle_members cm JOIN users u ON u.id = cm.user_id
                 WHERE cm.cycle_id = ? AND u.role IN ('juror','lead')", [$id])
        );
        foreach ($existing as $uid) {
            if (!isset($keep[$uid])) {
                Cycle::removeMember($id, $uid);
            }
        }
        foreach ($keep as $uid => $roleInCycle) {
            Cycle::addMember($id, $uid, $roleInCycle);
        }
        Cycle::syncSchools($id, $schools);

        Audit::log('cycle.members', 'Zuordnungen gespeichert: ' . count($jurors) . ' Jury, ' . count($leads) . ' Leitung, ' . count($schools) . ' Schulen', 'cycle', $id);
        flash('success', 'Zuordnungen für das Wettbewerbsjahr gespeichert.');
        redirect(url('cycles', ['cycle' => $id]));
    }

    if ($action === 'milestone_delete') {
        $mlid = (int) input('milestone_id', 0);
        $mlbl = (string) Database::value('SELECT label FROM cycle_milestones WHERE id = ? AND cycle_id = ?', [$mlid, $id]);
        Database::run('DELETE FROM cycle_milestones WHERE id = ? AND cycle_id = ?', [$mlid, $id]);
        Audit::log('cycle.milestone_delete', 'Meilenstein gelöscht: ' . ($mlbl ?: ('#' . $mlid)), 'cycle', $id);
        flash('success', 'Meilenstein gelöscht.');
        redirect(url('cycles', ['cycle' => $id]));
    }

    if ($action === 'milestone_save') {
        if (!Cycle::find($id)) {
            flash('error', 'Wettbewerbsjahr nicht gefunden.');
            redirect(url('cycles'));
        }
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
            flash('error', 'Bitte einen Namen für den Meilenstein angeben.');
            redirect(url('cycles', ['cycle' => $id]));
        }
        if ($mid > 0) {
            Database::run(
                'UPDATE cycle_milestones SET label=?, date_from=?, date_to=?, period_label=?, status=?, sort_order=?
                 WHERE id=? AND cycle_id=?',
                [$label, $from ?: null, $to ?: null, $period ?: null, $status, $sort, $mid, $id]
            );
            Audit::log('cycle.milestone_update', 'Meilenstein bearbeitet: ' . $label, 'cycle', $id);
            flash('success', 'Meilenstein aktualisiert.');
        } else {
            Database::insert(
                'INSERT INTO cycle_milestones (cycle_id, label, date_from, date_to, period_label, status, sort_order)
                 VALUES (?,?,?,?,?,?,?)',
                [$id, $label, $from ?: null, $to ?: null, $period ?: null, $status, $sort]
            );
            Audit::log('cycle.milestone_create', 'Meilenstein hinzugefügt: ' . $label, 'cycle', $id);
            flash('success', 'Meilenstein hinzugefügt.');
        }
        redirect(url('cycles', ['cycle' => $id]));
    }

    // Anlegen / Bearbeiten eines Zyklus
    $year  = trim((string) input('year_label'));
    $title = trim((string) input('title'));
    $start = trim((string) input('starts_on'));
    $end   = trim((string) input('ends_on'));
    $note  = trim((string) input('note'));
    $makeActive = (bool) input('is_active');

    if ($year === '') {
        flash('error', 'Bitte ein Wettbewerbsjahr angeben (z. B. „2026/27“).');
        redirect(url('cycles'));
    }
    $dup = Database::value('SELECT id FROM competition_cycles WHERE year_label = ? AND id <> ?', [$year, $id]);
    if ($dup) {
        flash('error', 'Dieses Wettbewerbsjahr existiert bereits.');
        redirect(url('cycles'));
    }

    if ($id > 0) {
        Database::run(
            'UPDATE competition_cycles SET year_label=?, title=?, starts_on=?, ends_on=?, note=? WHERE id=?',
            [$year, $title ?: null, $start ?: null, $end ?: null, $note ?: null, $id]
        );
        Audit::log('cycle.update', 'Wettbewerbsjahr bearbeitet: ' . $year, 'cycle', $id);
        flash('success', 'Wettbewerbsjahr aktualisiert.');
    } else {
        $id = Database::insert(
            'INSERT INTO competition_cycles (year_label, title, starts_on, ends_on, note) VALUES (?,?,?,?,?)',
            [$year, $title ?: null, $start ?: null, $end ?: null, $note ?: null]
        );
        Audit::log('cycle.create', 'Wettbewerbsjahr angelegt: ' . $year, 'cycle', $id);
        flash('success', 'Wettbewerbsjahr angelegt.');
    }
    if ($makeActive) {
        Cycle::setActive($id);
    }
    redirect(url('cycles', ['cycle' => $id]));
}

$cycles = Cycle::all();
$active = Cycle::active();

// Ausgewähltes Jahr zur Zuordnung von Jury/Projektleitung/Schulen
$selId = (int) input('cycle', 0) ?: (int) ($active['id'] ?? 0);
$sel = $selId ? Cycle::find($selId) : null;

$memberCountsJ = Cycle::memberCounts('juror');
$memberCountsL = Cycle::memberCounts('project_lead');
$schoolCounts  = Cycle::schoolCounts();

// Daten für die Zuordnung
if ($sel) {
    $allJurors = Database::all("SELECT id, name, email, specialty FROM users u WHERE EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'juror') ORDER BY name");
    $allLeads  = Database::all("SELECT id, name, email FROM users u WHERE EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = 'lead') ORDER BY name");
    $allSchools = Database::all('SELECT id, name, short_name FROM schools ORDER BY name');
    $memberRole = [];
    foreach (Database::all('SELECT user_id, role_in_cycle FROM cycle_members WHERE cycle_id = ?', [$sel['id']]) as $r) {
        $memberRole[(int) $r['user_id']] = $r['role_in_cycle'];
    }
    $selSchools = Cycle::schoolIds((int) $sel['id']);
    $milestones = Cycle::milestones((int) $sel['id']);
}

$statusLabels = ['auto' => 'automatisch', 'done' => 'erledigt', 'active' => 'läuft', 'upcoming' => 'geplant'];

$fillM = fn(array $m) => e(json_encode([
    'milestone_id' => (int) $m['id'],
    'label'        => $m['label'],
    'date_from'    => $m['date_from'],
    'date_to'      => $m['date_to'],
    'period_label' => $m['period_label'],
    'status'       => $m['status'],
    'sort_order'   => (int) $m['sort_order'],
], JSON_UNESCAPED_UNICODE));

$fill = fn(array $c) => e(json_encode([
    'id' => (int) $c['id'], 'year_label' => $c['year_label'], 'title' => $c['title'],
    'starts_on' => $c['starts_on'], 'ends_on' => $c['ends_on'], 'note' => $c['note'],
    'is_active' => (int) $c['is_active'],
], JSON_UNESCAPED_UNICODE));
ob_start(); ?>
<div class="page-head">
  <h1>Wettbewerbsjahre</h1>
  <button type="button" class="btn btn--teal" data-modal-open="cycleModal">+ Neu</button>
</div>
<p class="muted" style="margin-top:-6px;max-width:680px">
  Ein Wettbewerbsjahr (Zyklus) ist das zentrale Objekt: Jury, Projektleitung und Schulen
  hängen daran. Genau ein Jahr ist <em>aktiv</em>; frühere Jahre bleiben als Historie erhalten.
</p>

<div class="card">
  <div class="card__head"><?= count($cycles) ?> Wettbewerbsjahre</div>
  <div class="table-wrap">
    <table class="data data--cards">
      <thead><tr><th>Jahr</th><th>Jury</th><th>Leitung</th><th>Schulen</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cycles as $c): $cid = (int) $c['id']; ?>
        <tr<?= $cid === $selId ? ' style="background:var(--bg-soft,#f4f7fc)"' : '' ?>>
          <td data-label="Jahr">
            <strong><?= e($c['year_label']) ?></strong>
            <?php if ($c['is_active']): ?><span class="pill teal">aktiv</span><?php endif; ?>
            <?php if ($c['title']): ?><br><span class="muted" style="font-size:13px"><?= e($c['title']) ?></span><?php endif; ?>
          </td>
          <td data-label="Jury"><?= (int) ($memberCountsJ[$cid] ?? 0) ?></td>
          <td data-label="Leitung"><?= (int) ($memberCountsL[$cid] ?? 0) ?></td>
          <td data-label="Schulen"><?= (int) ($schoolCounts[$cid] ?? 0) ?></td>
          <td class="row-actions" style="white-space:nowrap;text-align:right">
            <a href="<?= url('cycles', ['cycle' => $cid]) ?>" class="btn btn--ghost btn--sm">Zuordnen</a>
            <button type="button" class="btn btn--ghost btn--sm" data-modal-open="cycleModal" data-fill="<?= $fill($c) ?>">Bearbeiten</button>
            <?php if (!$c['is_active']): ?>
              <form method="post" action="<?= url('cycles') ?>" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn btn--teal btn--sm">Aktiv</button>
              </form>
              <form method="post" action="<?= url('cycles') ?>" style="display:inline" data-confirm="Wettbewerbsjahr „<?= e($c['year_label']) ?>“ und alle Zuordnungen löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cid ?>">
                <button class="btn btn--danger btn--sm">Löschen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cycles): ?><tr><td colspan="5" class="muted">Noch kein Wettbewerbsjahr angelegt.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="cycleModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="cycleModalTitle">
    <div class="modal__head">
      <h3 id="cycleModalTitle" data-modal-title data-title-new="Neues Wettbewerbsjahr" data-title-edit="Wettbewerbsjahr bearbeiten">Neues Wettbewerbsjahr</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('cycles') ?>" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="0">
      <div class="field"><label>Wettbewerbsjahr *</label><input type="text" name="year_label" required placeholder="z. B. 2026/27"></div>
      <div class="field"><label>Bezeichnung / Motto</label><input type="text" name="title" placeholder="optional"></div>
      <div class="grid cols-2">
        <div class="field"><label>Start</label><input type="date" name="starts_on"></div>
        <div class="field"><label>Ende</label><input type="date" name="ends_on"></div>
      </div>
      <div class="field"><label>Notiz</label><textarea name="note" rows="2"></textarea></div>
      <div class="field"><label style="font-weight:400"><input type="checkbox" name="is_active" value="1" checked> Als aktives Wettbewerbsjahr setzen</label></div>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button>
      </div>
    </form>
  </div>
</div>

<?php if ($sel): ?>
<div class="card">
  <div class="card__head">
    Zuordnung für <strong><?= e($sel['year_label']) ?></strong>
    <?php if ($sel['is_active']): ?><span class="pill teal">aktiv</span><?php endif; ?>
  </div>
  <div class="card__body">
    <form method="post" action="<?= url('cycles') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="members">
      <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
      <div class="grid cols-3">
        <div>
          <div class="field"><label>Jury</label></div>
          <?php if (!$allJurors): ?><p class="muted" style="font-size:13px">Noch keine Juror:innen angelegt (Menü „Jury &amp; Nutzer“).</p><?php endif; ?>
          <?php foreach ($allJurors as $j): ?>
            <label style="display:block;font-weight:400;margin-bottom:4px">
              <input type="checkbox" name="jurors[]" value="<?= (int) $j['id'] ?>" <?= ($memberRole[(int) $j['id']] ?? '') === 'juror' ? 'checked' : '' ?>>
              <?= e($j['name']) ?><?php if ($j['specialty']): ?> <span class="muted" style="font-size:12px">– <?= e($j['specialty']) ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div>
          <div class="field"><label>Projektleitung</label></div>
          <?php foreach ($allLeads as $l): ?>
            <label style="display:block;font-weight:400;margin-bottom:4px">
              <input type="checkbox" name="leads[]" value="<?= (int) $l['id'] ?>" <?= ($memberRole[(int) $l['id']] ?? '') === 'project_lead' ? 'checked' : '' ?>>
              <?= e($l['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div>
          <div class="field"><label>Teilnehmende Schulen</label></div>
          <?php if (!$allSchools): ?><p class="muted" style="font-size:13px">Noch keine Schulen angelegt.</p><?php endif; ?>
          <?php foreach ($allSchools as $s): ?>
            <label style="display:block;font-weight:400;margin-bottom:4px">
              <input type="checkbox" name="schools[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $selSchools, true) ? 'checked' : '' ?>>
              <?= e($s['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="mt"><button class="btn btn--primary">Zuordnung speichern</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head" style="display:flex;justify-content:space-between;align-items:center;gap:8px">
    <span>Projektablauf / Meilensteine für <strong><?= e($sel['year_label']) ?></strong></span>
    <button type="button" class="btn btn--teal btn--sm" data-modal-open="milestoneModal">+ Meilenstein</button>
  </div>
  <div class="card__body">
    <p class="muted" style="font-size:13px;margin-top:0">
      Diese Meilensteine erscheinen als Zeitleiste „Projektablauf" auf dem Dashboard. Jeder Meilenstein
      hat entweder ein konkretes Datum bzw. einen Zeitraum oder eine freie Zeitangabe (z. B. „8 Wochen").
      Bei Status <em>automatisch</em> wird erledigt/läuft/geplant aus dem Datum abgeleitet.
    </p>
    <div class="table-wrap">
      <table class="data data--cards">
        <thead><tr><th>Reihenfolge</th><th>Meilenstein</th><th>Zeitpunkt / Zeitraum</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($milestones as $m): $miid = (int) $m['id']; ?>
          <tr>
            <td data-label="Reihenfolge"><?= (int) $m['sort_order'] ?></td>
            <td data-label="Meilenstein"><strong><?= e($m['label']) ?></strong></td>
            <td data-label="Zeitpunkt / Zeitraum"><?= e(Cycle::milestoneDateLabel($m)) ?: '<span class="muted">–</span>' ?></td>
            <td data-label="Status"><?= e($statusLabels[$m['status']] ?? $m['status']) ?></td>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <button type="button" class="btn btn--ghost btn--sm" data-modal-open="milestoneModal" data-edit data-fill="<?= $fillM($m) ?>">Bearbeiten</button>
              <form method="post" action="<?= url('cycles') ?>" style="display:inline" data-confirm="Meilenstein „<?= e($m['label']) ?>“ löschen?">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="milestone_delete">
                <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
                <input type="hidden" name="milestone_id" value="<?= $miid ?>">
                <button class="btn btn--danger btn--sm">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$milestones): ?><tr><td colspan="5" class="muted">Noch keine Meilensteine – über „+ Meilenstein" hinzufügen.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="milestoneModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="milestoneModalTitle">
    <div class="modal__head">
      <h3 id="milestoneModalTitle" data-modal-title data-title-new="Neuer Meilenstein" data-title-edit="Meilenstein bearbeiten">Neuer Meilenstein</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('cycles') ?>" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="milestone_save">
      <input type="hidden" name="id" value="<?= (int) $sel['id'] ?>">
      <input type="hidden" name="milestone_id" value="0">
      <div class="field"><label>Meilenstein *</label><input type="text" name="label" required placeholder="z. B. Einsendeschluss"></div>
      <div class="grid cols-2">
        <div class="field"><label>Von (Datum)</label><input type="date" name="date_from"></div>
        <div class="field"><label>Bis (Datum, optional)</label><input type="date" name="date_to"></div>
      </div>
      <div class="field">
        <label>Freie Zeitangabe (statt Datum)</label>
        <input type="text" name="period_label" placeholder="z. B. „8 Wochen", „ab April", „KW21/Mai">
        <span class="muted" style="font-size:12px">Wenn gesetzt, wird diese Angabe statt des Datums angezeigt.</span>
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label>Status</label>
          <select name="status">
            <option value="auto">automatisch (aus Datum)</option>
            <option value="done">erledigt</option>
            <option value="active">läuft gerade</option>
            <option value="upcoming">geplant</option>
          </select>
        </div>
        <div class="field"><label>Reihenfolge</label><input type="number" name="sort_order" value="0" step="10"></div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Hinzufügen" data-label-edit="Speichern">Hinzufügen</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Wettbewerbsjahre';
require APP_PATH . '/pages/_layout.php';
