<?php
/** Teams & Schüler verwalten (Projektleitung: alle; Lehrkraft: eigene Schule). */
declare(strict_types=1);

Auth::require('admin', 'lead', 'teacher');
$me = Auth::user();
$isAdmin = Auth::isManager(); // Admin oder Projektleitung = volle Verwaltung
$mySchool = $me['school_id'] ? (int) $me['school_id'] : null;
$noSchool = !$isAdmin && !$mySchool; // Lehrkraft ohne Schulzuordnung: kann nichts verwalten

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
            Audit::log('team.update', 'Team bearbeitet: ' . $name . ' (Status ' . $status . ')', 'team', $id);
            flash('success', 'Team gespeichert.');
            redirect(url('teams', ['edit' => $id]));
        } else {
            $nid = Database::insert('INSERT INTO teams (school_id,name,idea_name,idea_pitch,status) VALUES (?,?,?,?,?)',
                [$school, $name, $idea ?: null, $pitch ?: null, $status]);
            Audit::log('team.create', 'Team angelegt: ' . $name, 'team', $nid);
            flash('success', 'Team angelegt.');
            redirect(url('teams', ['edit' => $nid]));
        }
        redirect(url('teams'));
    }

    if ($action === 'delete_team') {
        $team = Database::one('SELECT * FROM teams WHERE id = ?', [(int) input('id')]);
        if ($team && $canAccessTeam($team)) {
            Database::run('DELETE FROM teams WHERE id = ?', [(int) $team['id']]);
            Audit::log('team.delete', 'Team gelöscht: ' . $team['name'], 'team', (int) $team['id']);
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
            Audit::log('student.add', 'Teammitglied hinzugefügt: ' . trim((string) input('sname')) . ' (Team ' . $team['name'] . ')', 'team', $tid);
        }
        redirect(url('teams', ['edit' => $tid]));
    }

    if ($action === 'del_student') {
        $sid = (int) input('id');
        $st = Database::one('SELECT s.*, t.school_id FROM students s JOIN teams t ON t.id=s.team_id WHERE s.id=?', [$sid]);
        if ($st && $canAccessTeam($st)) {
            Database::run('DELETE FROM students WHERE id = ?', [$sid]);
            Audit::log('student.delete', 'Teammitglied entfernt: ' . $st['name'], 'team', (int) $st['team_id']);
            redirect(url('teams', ['edit' => (int) $st['team_id']]));
        }
        redirect(url('teams'));
    }
}

// Detail-/Bearbeiten-Ansicht
$edit = null; $students = []; $editPlan = null;
if ($eid = (int) input('edit', 0)) {
    $edit = Database::one('SELECT * FROM teams WHERE id = ?', [$eid]);
    if ($edit && !$canAccessTeam($edit)) { $edit = null; }
    if ($edit) {
        $students = Database::all('SELECT * FROM students WHERE team_id = ? ORDER BY name', [$eid]);
        $editPlan = Database::one('SELECT * FROM business_plans WHERE team_id = ? AND is_current = 1', [$eid]);
    }
}

$schools = Database::all('SELECT id, name FROM schools ORDER BY name');
$where = $isAdmin ? '' : 'WHERE t.school_id = ' . (int) $mySchool;
$teams = Database::all(
    "SELECT t.*, s.name AS school_name,
            (SELECT COUNT(*) FROM students st WHERE st.team_id = t.id) AS members,
            (SELECT bp.id FROM business_plans bp WHERE bp.team_id = t.id AND bp.is_current=1 LIMIT 1) AS bp_id
     FROM teams t JOIN schools s ON s.id = t.school_id $where ORDER BY s.name, t.name"
);
$statusList = ['draft'=>'In Arbeit','submitted'=>'Eingereicht','nominated'=>'Pitch nominiert','fallback'=>'Nachrücker','eliminated'=>'Ausgeschieden'];

$teamFill = fn(array $t) => e(json_encode([
    'id' => (int) $t['id'], 'name' => $t['name'], 'school_id' => (int) ($t['school_id'] ?? 0) ?: '',
    'idea_name' => $t['idea_name'], 'idea_pitch' => $t['idea_pitch'], 'status' => $t['status'],
], JSON_UNESCAPED_UNICODE));
ob_start(); ?>
<div class="page-head">
  <h1>Teams &amp; Schüler</h1>
  <?php if (!$edit && !$noSchool): ?><button type="button" class="btn btn--teal" data-modal-open="teamModal">+ Neu</button><?php endif; ?>
</div>

<?php if ($noSchool): ?>
  <div class="flash info">Dir ist noch keine Schule zugeordnet. Bitte wende dich an die Projektleitung, damit du die Teams deiner Schule verwalten kannst.</div>
<?php endif; ?>

<?php if ($edit !== null): [$sl,$sc] = status_label($edit['status']); ?>
  <div style="margin-bottom:14px"><a href="<?= url('teams') ?>" class="btn btn--ghost btn--sm">← Zurück zur Übersicht</a></div>
  <div class="grid cols-2">
    <div class="card">
      <div class="card__head" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <span>Team</span>
        <button type="button" class="btn btn--ghost btn--sm" data-modal-open="teamModal" data-fill="<?= $teamFill($edit) ?>">Bearbeiten</button>
      </div>
      <div class="card__body">
        <h2 style="margin:0 0 4px;font-size:22px"><?= e($edit['name']) ?></h2>
        <p style="margin:0 0 14px"><span class="pill <?= $sc ?>"><?= e($sl) ?></span>
          <?php if ($isAdmin && !empty($edit['school_id'])): $sn = array_column($schools, 'name', 'id')[(int) $edit['school_id']] ?? null; ?>
            <?php if ($sn): ?><span class="pill muted"><?= e($sn) ?></span><?php endif; ?>
          <?php endif; ?>
        </p>
        <?php if ($edit['idea_name']): ?><div class="field" style="margin-bottom:12px"><label>Geschäftsidee</label><div><?= e($edit['idea_name']) ?></div></div><?php endif; ?>
        <?php if ($edit['idea_pitch']): ?><div class="field" style="margin-bottom:12px"><label>Kurzbeschreibung</label><div class="muted"><?= nl2br(e($edit['idea_pitch'])) ?></div></div><?php endif; ?>
        <?php if (!$edit['idea_name'] && !$edit['idea_pitch']): ?><p class="muted" style="margin:0 0 12px">Noch keine Geschäftsidee hinterlegt – über „Bearbeiten“ ergänzen.</p><?php endif; ?>
        <div class="field" style="margin:0">
          <label>Businessplan</label>
          <?php if ($editPlan): ?>
            <div>
              <a class="pdf-link" href="<?= url('bp_download', ['id' => $editPlan['id']]) ?>"
                 data-pdf-url="<?= url('bp_download', ['id' => $editPlan['id']]) ?>"
                 data-pdf-title="<?= e($edit['name'] . ($edit['idea_name'] ? ' – ' . $edit['idea_name'] : '')) ?>"
                 title="Businessplan-PDF ansehen"><span class="pill teal">Version <?= (int) $editPlan['version'] ?></span> PDF ansehen</a>
              <a href="<?= url('plans', ['team' => (int) $edit['id']]) ?>" class="btn btn--ghost btn--sm" style="margin-left:6px">Zum Businessplan →</a>
            </div>
          <?php else: ?>
            <div class="muted">Noch kein Businessplan eingereicht. <a href="<?= url('plans', ['team' => (int) $edit['id']]) ?>">Jetzt hochladen →</a></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card__head">Teammitglieder<?= $students ? ' (' . count($students) . ')' : '' ?></div>
      <div class="card__body">
        <table class="data" style="margin-bottom:14px">
          <tbody>
          <?php foreach ($students as $st): ?>
            <tr><td><?= e($st['name']) ?></td><td><?= $st['role_color'] ? '<span class="pill muted">'.e($st['role_color']).'</span>' : '' ?></td>
            <td style="text-align:right">
              <form method="post" action="<?= url('teams') ?>" style="display:inline" data-confirm="„<?= e($st['name']) ?>“ aus dem Team entfernen?">
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
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="table-wrap">
      <table class="data data--cards">
        <thead><tr><th>Team</th><?php if ($isAdmin): ?><th>Schule</th><?php endif; ?><th>Mitglieder</th><th>Businessplan</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teams as $t): [$sl,$sc] = status_label($t['status']); ?>
          <tr>
            <td data-label="Team"><strong><?= e($t['name']) ?></strong><?php if ($t['idea_name']): ?><br><span class="muted" style="font-size:13px"><?= e($t['idea_name']) ?></span><?php endif; ?></td>
            <?php if ($isAdmin): ?><td data-label="Schule"><?= e($t['school_name']) ?></td><?php endif; ?>
            <td data-label="Mitglieder"><?= (int) $t['members'] ?></td>
            <td data-label="Businessplan">
              <?php if ($t['bp_id']): ?>
                <a class="pdf-link" href="<?= url('bp_download', ['id' => $t['bp_id']]) ?>"
                   data-pdf-url="<?= url('bp_download', ['id' => $t['bp_id']]) ?>"
                   data-pdf-title="<?= e($t['name'] . ($t['idea_name'] ? ' – ' . $t['idea_name'] : '')) ?>"
                   title="Businessplan-PDF ansehen"><span class="pill teal">vorhanden</span></a>
              <?php else: ?><span class="pill muted">—</span><?php endif; ?>
            </td>
            <td data-label="Status"><span class="pill <?= $sc ?>"><?= e($sl) ?></span></td>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <a href="<?= url('teams', ['edit' => $t['id']]) ?>" class="btn btn--ghost btn--sm" title="Team öffnen: Mitglieder verwalten">👥 Mitglieder</a>
              <button type="button" class="btn btn--ghost btn--sm" data-modal-open="teamModal" data-fill="<?= $teamFill($t) ?>">Bearbeiten</button>
              <form method="post" action="<?= url('teams') ?>" style="display:inline" data-confirm="Team „<?= e($t['name']) ?>“ inkl. Mitglieder wirklich löschen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete_team"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn--danger btn--sm">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$teams): ?><tr><td colspan="6" class="muted">Noch keine Teams.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="modal-overlay" id="teamModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="teamModalTitle">
    <div class="modal__head">
      <h3 id="teamModalTitle" data-modal-title data-title-new="Neues Team" data-title-edit="Team bearbeiten">Neues Team</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('teams') ?>" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_team">
      <input type="hidden" name="id" value="0">
      <div class="field"><label>Team-/Projektname *</label><input type="text" name="name" required></div>
      <?php if ($isAdmin): ?>
      <div class="field"><label>Schule *</label>
        <select name="school_id" required>
          <option value="">— wählen —</option>
          <?php foreach ($schools as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field"><label>Name der Geschäftsidee</label><input type="text" name="idea_name"></div>
      <div class="field"><label>Kurzbeschreibung</label><textarea name="idea_pitch" rows="3"></textarea></div>
      <div class="field"><label>Status</label>
        <select name="status">
          <?php foreach ($statusList as $sk => $sl): ?>
            <option value="<?= $sk ?>"><?= e($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="muted" style="font-size:13px;margin:0">Teammitglieder werden nach dem Anlegen über „👥 Mitglieder“ verwaltet.</p>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Teams & Schüler';
require APP_PATH . '/pages/_layout.php';
