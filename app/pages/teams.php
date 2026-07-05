<?php
/** Teams & Schüler verwalten (Projektleitung: alle; Lehrkraft: eigene Schule). */
declare(strict_types=1);

Auth::require('admin', 'lead', 'teacher');
$me = Auth::user();
$isAdmin = Auth::isManager(); // Admin oder Projektleitung = volle Verwaltung
$mySchool = $me['school_id'] ? (int) $me['school_id'] : null;

/** Zugriff auf ein Team pruefen (Lehrkraft nur eigene Schule). */
$canAccessTeam = function (array $team) use ($isAdmin, $mySchool): bool {
    return $isAdmin || ((int) $team['school_id'] === $mySchool);
};

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'save_team') {
        $id = (int) input('id', 0);
        $name = trim((string) input('name'));
        $idea = trim((string) input('idea_name'));
        $pitch = trim((string) input('idea_pitch'));
        $status = (string) input('status', 'draft');
        $school = $isAdmin ? ((int) input('school_id') ?: null) : $mySchool;
        $allowedStatus = ['draft','submitted','nominated','fallback','eliminated'];
        if (!in_array($status, $allowedStatus, true)) { $status = 'draft'; }

        if ($name === '' || !$school) {
            flash('error', 'Team-Name und Schule sind erforderlich.');
        } elseif ($id > 0) {
            $team = Database::one('SELECT * FROM teams WHERE id = ?', [$id]);
            if (!$team || !$canAccessTeam($team)) { flash('error', 'Kein Zugriff.'); redirect(url('teams')); }
            if (!$isAdmin) { $school = (int) $team['school_id']; } // Lehrkraft darf Schule nicht umhängen
            Database::run('UPDATE teams SET school_id=?, name=?, idea_name=?, idea_pitch=?, status=? WHERE id=?',
                [$school, $name, $idea ?: null, $pitch ?: null, $status, $id]);
            flash('success', 'Team gespeichert.');
            redirect(url('teams', ['edit' => $id]));
        } else {
            $nid = Database::insert('INSERT INTO teams (school_id,name,idea_name,idea_pitch,status) VALUES (?,?,?,?,?)',
                [$school, $name, $idea ?: null, $pitch ?: null, $status]);
            flash('success', 'Team angelegt.');
            redirect(url('teams', ['edit' => $nid]));
        }
        redirect(url('teams'));
    }

    if ($action === 'delete_team') {
        $team = Database::one('SELECT * FROM teams WHERE id = ?', [(int) input('id')]);
        if ($team && $canAccessTeam($team)) {
            Database::run('DELETE FROM teams WHERE id = ?', [(int) $team['id']]);
            flash('success', 'Team gelöscht.');
        }
        redirect(url('teams'));
    }

    if ($action === 'add_student') {
        $tid = (int) input('team_id');
        $team = Database::one('SELECT * FROM teams WHERE id = ?', [$tid]);
        if ($team && $canAccessTeam($team) && trim((string) input('sname')) !== '') {
            Database::run('INSERT INTO students (team_id,name,role_color) VALUES (?,?,?)',
                [$tid, trim((string) input('sname')), trim((string) input('role_color')) ?: null]);
        }
        redirect(url('teams', ['edit' => $tid]));
    }

    if ($action === 'del_student') {
        $sid = (int) input('id');
        $st = Database::one('SELECT s.*, t.school_id FROM students s JOIN teams t ON t.id=s.team_id WHERE s.id=?', [$sid]);
        if ($st && $canAccessTeam($st)) {
            Database::run('DELETE FROM students WHERE id = ?', [$sid]);
            redirect(url('teams', ['edit' => (int) $st['team_id']]));
        }
        redirect(url('teams'));
    }
}

// Detail-/Bearbeiten-Ansicht
$edit = null; $students = [];
if ($eid = (int) input('edit', 0)) {
    $edit = Database::one('SELECT * FROM teams WHERE id = ?', [$eid]);
    if ($edit && !$canAccessTeam($edit)) { $edit = null; }
    if ($edit) { $students = Database::all('SELECT * FROM students WHERE team_id = ? ORDER BY name', [$eid]); }
}

$schools = Database::all('SELECT id, name FROM schools ORDER BY name');
$where = $isAdmin ? '' : 'WHERE t.school_id = ' . (int) $mySchool;
$teams = Database::all(
    "SELECT t.*, s.name AS school_name,
            (SELECT COUNT(*) FROM students st WHERE st.team_id = t.id) AS members,
            (SELECT COUNT(*) FROM business_plans bp WHERE bp.team_id = t.id AND bp.is_current=1) AS has_plan
     FROM teams t JOIN schools s ON s.id = t.school_id $where ORDER BY s.name, t.name"
);
$statusList = ['draft'=>'In Arbeit','submitted'=>'Eingereicht','nominated'=>'Pitch nominiert','fallback'=>'Nachrücker','eliminated'=>'Ausgeschieden'];

ob_start(); ?>
<div class="page-head">
  <h1>Teams &amp; Schüler</h1>
  <?php if (!$edit): ?><a href="<?= url('teams', ['edit' => 'new']) ?>" class="btn btn--teal">+ Neues Team</a><?php endif; ?>
</div>

<?php if ($edit !== null || input('edit') === 'new'): ?>
  <?php $isNew = ($edit === null); ?>
  <div class="grid cols-2">
    <div class="card">
      <div class="card__head"><?= $isNew ? 'Neues Team' : 'Team bearbeiten' ?></div>
      <div class="card__body">
        <form method="post" action="<?= url('teams') ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="save_team">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="field"><label>Team-/Projektname *</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
          <?php if ($isAdmin): ?>
          <div class="field"><label>Schule *</label>
            <select name="school_id" required>
              <option value="">— wählen —</option>
              <?php foreach ($schools as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) ($edit['school_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="field"><label>Name der Geschäftsidee</label><input type="text" name="idea_name" value="<?= e($edit['idea_name'] ?? '') ?>"></div>
          <div class="field"><label>Kurzbeschreibung</label><textarea name="idea_pitch" rows="3"><?= e($edit['idea_pitch'] ?? '') ?></textarea></div>
          <div class="field"><label>Status</label>
            <select name="status">
              <?php foreach ($statusList as $sk => $sl): ?>
                <option value="<?= $sk ?>" <?= ($edit['status'] ?? 'draft') === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn--primary">Speichern</button>
          <a href="<?= url('teams') ?>" class="btn btn--ghost">Zurück</a>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card__head">Teammitglieder<?= $students ? ' (' . count($students) . ')' : '' ?></div>
      <div class="card__body">
        <?php if ($isNew): ?>
          <p class="muted">Speichere das Team zuerst, dann kannst du Mitglieder hinzufügen.</p>
        <?php else: ?>
          <table class="data" style="margin-bottom:14px">
            <tbody>
            <?php foreach ($students as $st): ?>
              <tr><td><?= e($st['name']) ?></td><td><?= $st['role_color'] ? '<span class="pill muted">'.e($st['role_color']).'</span>' : '' ?></td>
              <td style="text-align:right">
                <form method="post" action="<?= url('teams') ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="del_student"><input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                  <button class="btn btn--ghost btn--sm">×</button>
                </form></td></tr>
            <?php endforeach; ?>
            <?php if (!$students): ?><tr><td class="muted">Noch keine Mitglieder.</td></tr><?php endif; ?>
            </tbody>
          </table>
          <form method="post" action="<?= url('teams') ?>">
            <?= Csrf::field() ?><input type="hidden" name="action" value="add_student"><input type="hidden" name="team_id" value="<?= (int) $edit['id'] ?>">
            <div style="display:flex;gap:8px">
              <input type="text" name="sname" placeholder="Name" required>
              <input type="text" name="role_color" placeholder="Farbe/Typ" style="max-width:130px">
              <button class="btn btn--teal">+</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Team</th><?php if ($isAdmin): ?><th>Schule</th><?php endif; ?><th>Mitglieder</th><th>Businessplan</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teams as $t): [$sl,$sc] = status_label($t['status']); ?>
          <tr>
            <td><strong><?= e($t['name']) ?></strong><?php if ($t['idea_name']): ?><br><span class="muted" style="font-size:13px"><?= e($t['idea_name']) ?></span><?php endif; ?></td>
            <?php if ($isAdmin): ?><td><?= e($t['school_name']) ?></td><?php endif; ?>
            <td><?= (int) $t['members'] ?></td>
            <td><?= $t['has_plan'] ? '<span class="pill teal">vorhanden</span>' : '<span class="pill muted">—</span>' ?></td>
            <td><span class="pill <?= $sc ?>"><?= e($sl) ?></span></td>
            <td style="text-align:right"><a href="<?= url('teams', ['edit' => $t['id']]) ?>" class="btn btn--ghost btn--sm">Öffnen</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$teams): ?><tr><td colspan="6" class="muted">Noch keine Teams.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Teams & Schüler';
require APP_PATH . '/pages/_layout.php';
