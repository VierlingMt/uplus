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
        Database::run('DELETE FROM competition_cycles WHERE id = ?', [$id]);
        flash('success', 'Wettbewerbsjahr gelöscht.');
        redirect(url('cycles'));
    }

    if ($action === 'activate') {
        Cycle::setActive($id);
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

        flash('success', 'Zuordnungen für das Wettbewerbsjahr gespeichert.');
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
        redirect(url('cycles', $id ? ['edit' => $id] : []));
    }
    $dup = Database::value('SELECT id FROM competition_cycles WHERE year_label = ? AND id <> ?', [$year, $id]);
    if ($dup) {
        flash('error', 'Dieses Wettbewerbsjahr existiert bereits.');
        redirect(url('cycles', $id ? ['edit' => $id] : []));
    }

    if ($id > 0) {
        Database::run(
            'UPDATE competition_cycles SET year_label=?, title=?, starts_on=?, ends_on=?, note=? WHERE id=?',
            [$year, $title ?: null, $start ?: null, $end ?: null, $note ?: null, $id]
        );
        flash('success', 'Wettbewerbsjahr aktualisiert.');
    } else {
        $id = Database::insert(
            'INSERT INTO competition_cycles (year_label, title, starts_on, ends_on, note) VALUES (?,?,?,?,?)',
            [$year, $title ?: null, $start ?: null, $end ?: null, $note ?: null]
        );
        flash('success', 'Wettbewerbsjahr angelegt.');
    }
    if ($makeActive) {
        Cycle::setActive($id);
    }
    redirect(url('cycles', ['cycle' => $id]));
}

$cycles = Cycle::all();
$active = Cycle::active();

$edit = null;
if ($eid = (int) input('edit', 0)) {
    $edit = Cycle::find($eid);
}

// Ausgewähltes Jahr zur Zuordnung von Jury/Projektleitung/Schulen
$selId = (int) input('cycle', 0) ?: (int) ($active['id'] ?? 0);
$sel = $selId ? Cycle::find($selId) : null;

$memberCountsJ = Cycle::memberCounts('juror');
$memberCountsL = Cycle::memberCounts('project_lead');
$schoolCounts  = Cycle::schoolCounts();

// Daten für die Zuordnung
if ($sel) {
    $allJurors = Database::all("SELECT id, name, email, specialty FROM users WHERE role = 'juror' ORDER BY name");
    $allLeads  = Database::all("SELECT id, name, email FROM users WHERE role = 'lead' ORDER BY name");
    $allSchools = Database::all('SELECT id, name, short_name FROM schools ORDER BY name');
    $memberRole = [];
    foreach (Database::all('SELECT user_id, role_in_cycle FROM cycle_members WHERE cycle_id = ?', [$sel['id']]) as $r) {
        $memberRole[(int) $r['user_id']] = $r['role_in_cycle'];
    }
    $selSchools = Cycle::schoolIds((int) $sel['id']);
}

ob_start(); ?>
<div class="page-head"><h1>Wettbewerbsjahre</h1></div>
<p class="muted" style="margin-top:-6px;max-width:680px">
  Ein Wettbewerbsjahr (Zyklus) ist das zentrale Objekt: Jury, Projektleitung und Schulen
  hängen daran. Genau ein Jahr ist <em>aktiv</em>; frühere Jahre bleiben als Historie erhalten.
</p>

<div class="grid cols-2">
  <div class="card">
    <div class="card__head"><?= $edit ? 'Wettbewerbsjahr bearbeiten' : 'Neues Wettbewerbsjahr anlegen' ?></div>
    <div class="card__body">
      <form method="post" action="<?= url('cycles') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div class="field"><label>Wettbewerbsjahr *</label>
          <input type="text" name="year_label" required placeholder="z. B. 2026/27" value="<?= e($edit['year_label'] ?? '') ?>">
        </div>
        <div class="field"><label>Bezeichnung / Motto</label>
          <input type="text" name="title" value="<?= e($edit['title'] ?? '') ?>" placeholder="optional">
        </div>
        <div class="grid cols-2">
          <div class="field"><label>Start</label><input type="date" name="starts_on" value="<?= e($edit['starts_on'] ?? '') ?>"></div>
          <div class="field"><label>Ende</label><input type="date" name="ends_on" value="<?= e($edit['ends_on'] ?? '') ?>"></div>
        </div>
        <div class="field"><label>Notiz</label><textarea name="note" rows="2"><?= e($edit['note'] ?? '') ?></textarea></div>
        <?php if (!$edit || !($edit['is_active'] ?? 0)): ?>
          <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= $edit ? '' : 'checked' ?>> Als aktives Wettbewerbsjahr setzen</label></div>
        <?php else: ?>
          <p class="muted" style="font-size:13px">Dies ist aktuell das aktive Wettbewerbsjahr.</p>
        <?php endif; ?>
        <button class="btn btn--primary"><?= $edit ? 'Speichern' : 'Anlegen' ?></button>
        <?php if ($edit): ?><a href="<?= url('cycles') ?>" class="btn btn--ghost">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><?= count($cycles) ?> Wettbewerbsjahre</div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Jahr</th><th>Jury</th><th>Leitung</th><th>Schulen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cycles as $c): $cid = (int) $c['id']; ?>
          <tr<?= $cid === $selId ? ' style="background:var(--bg-soft,#f4f7fc)"' : '' ?>>
            <td>
              <strong><?= e($c['year_label']) ?></strong>
              <?php if ($c['is_active']): ?><span class="pill teal">aktiv</span><?php endif; ?>
              <?php if ($c['title']): ?><br><span class="muted" style="font-size:13px"><?= e($c['title']) ?></span><?php endif; ?>
            </td>
            <td><?= (int) ($memberCountsJ[$cid] ?? 0) ?></td>
            <td><?= (int) ($memberCountsL[$cid] ?? 0) ?></td>
            <td><?= (int) ($schoolCounts[$cid] ?? 0) ?></td>
            <td style="white-space:nowrap;text-align:right">
              <a href="<?= url('cycles', ['cycle' => $cid]) ?>" class="btn btn--ghost btn--sm">Zuordnen</a>
              <a href="<?= url('cycles', ['edit' => $cid]) ?>" class="btn btn--ghost btn--sm">Bearbeiten</a>
              <?php if (!$c['is_active']): ?>
                <form method="post" action="<?= url('cycles') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= $cid ?>">
                  <button class="btn btn--teal btn--sm">Aktiv</button>
                </form>
                <form method="post" action="<?= url('cycles') ?>" style="display:inline" data-confirm="Wettbewerbsjahr „<?= e($c['year_label']) ?>“ und alle Zuordnungen löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $cid ?>">
                  <button class="btn btn--danger btn--sm">×</button>
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
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Wettbewerbsjahre';
require APP_PATH . '/pages/_layout.php';
