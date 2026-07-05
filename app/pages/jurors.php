<?php
/** Nutzer verwalten: Projektleitung, Lehrkräfte, Jury (nur Projektleitung). */
declare(strict_types=1);

Auth::require('admin');

$roles = ['admin' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'];

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');
    $id = (int) input('id', 0);

    if ($action === 'delete') {
        if ($id === Auth::id()) {
            flash('error', 'Du kannst dich nicht selbst löschen.');
        } else {
            Database::run('DELETE FROM users WHERE id = ?', [$id]);
            flash('success', 'Nutzer gelöscht.');
        }
        redirect(url('jurors'));
    }

    // Anlegen / Bearbeiten
    $role  = (string) input('role');
    $name  = trim((string) input('name'));
    $email = strtolower(trim((string) input('email')));
    $spec  = trim((string) input('specialty'));
    $phone = trim((string) input('phone'));
    $school = (int) input('school_id', 0) ?: null;
    $active = input('is_active') ? 1 : 0;
    if ($role !== 'teacher') { $school = null; }

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($roles[$role])) {
        flash('error', 'Bitte Name, gültige E-Mail und Rolle angeben.');
        redirect(url('jurors', $id ? ['edit' => $id] : []));
    }
    // E-Mail-Eindeutigkeit
    $dup = Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]);
    if ($dup) {
        flash('error', 'Diese E-Mail wird bereits verwendet.');
        redirect(url('jurors', $id ? ['edit' => $id] : []));
    }

    if ($id > 0) {
        Database::run('UPDATE users SET role=?, name=?, email=?, specialty=?, phone=?, school_id=?, is_active=? WHERE id=?',
            [$role, $name, $email, $spec ?: null, $phone ?: null, $school, $active, $id]);
        flash('success', 'Nutzer aktualisiert.');
    } else {
        Database::run('INSERT INTO users (role,name,email,specialty,phone,school_id,is_active) VALUES (?,?,?,?,?,?,?)',
            [$role, $name, $email, $spec ?: null, $phone ?: null, $school, $active]);
        flash('success', 'Nutzer angelegt. Anmeldung erfolgt passwortlos per Login-Link an die E-Mail.');
    }
    redirect(url('jurors'));
}

$edit = null;
if ($eid = (int) input('edit', 0)) {
    $edit = Database::one('SELECT * FROM users WHERE id = ?', [$eid]);
}
$schools = Database::all('SELECT id, name FROM schools ORDER BY name');
$users = Database::all(
    'SELECT u.*, s.name AS school_name FROM users u LEFT JOIN schools s ON s.id = u.school_id
     ORDER BY FIELD(u.role,"admin","juror","teacher"), u.name'
);

ob_start(); ?>
<div class="page-head"><h1>Jury &amp; Nutzer</h1></div>
<div class="grid cols-2">
  <div class="card">
    <div class="card__head"><?= $edit ? 'Nutzer bearbeiten' : 'Neuer Nutzer' ?></div>
    <div class="card__body">
      <form method="post" action="<?= url('jurors') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div class="field"><label>Rolle *</label>
          <select name="role" id="roleSel">
            <?php foreach ($roles as $rk => $rl): ?>
              <option value="<?= $rk ?>" <?= ($edit['role'] ?? 'juror') === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Name *</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
        <div class="field"><label>E-Mail *</label><input type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>"></div>
        <div class="field" id="schoolField"><label>Schule (für Lehrkräfte)</label>
          <select name="school_id">
            <option value="">—</option>
            <?php foreach ($schools as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) ($edit['school_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label>Spezialgebiet (Jury)</label><input type="text" name="specialty" value="<?= e($edit['specialty'] ?? '') ?>" placeholder="z. B. Marketing, Finanzen"></div>
        <div class="field"><label>Telefon</label><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
        <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Aktiv (Login erlaubt)</label></div>
        <p class="muted" style="font-size:13px;margin:0 0 12px">Anmeldung passwortlos per Login-Link an die E-Mail – kein Passwort nötig.</p>
        <button class="btn btn--primary"><?= $edit ? 'Speichern' : 'Anlegen' ?></button>
        <?php if ($edit): ?><a href="<?= url('jurors') ?>" class="btn btn--ghost">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><?= count($users) ?> Nutzer</div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Name</th><th>Rolle</th><th>Login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['name']) ?></strong><br><span class="muted" style="font-size:13px"><?= e($u['email']) ?></span>
              <?php if ($u['school_name']): ?><br><span class="pill muted"><?= e($u['school_name']) ?></span><?php endif; ?></td>
            <td><span class="pill <?= $u['role']==='admin'?'blue':($u['role']==='juror'?'teal':'amber') ?>"><?= e($roles[$u['role']] ?? $u['role']) ?></span></td>
            <td><?php if (!$u['is_active']): ?><span class="pill muted">inaktiv</span><?php else: ?><span class="pill teal">aktiv</span><?php endif; ?></td>
            <td style="white-space:nowrap;text-align:right">
              <a href="<?= url('jurors', ['edit' => $u['id']]) ?>" class="btn btn--ghost btn--sm">Bearbeiten</a>
              <?php if ($u['id'] !== Auth::id()): ?>
                <form method="post" action="<?= url('jurors') ?>" style="display:inline" data-confirm="„<?= e($u['name']) ?>“ löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn--danger btn--sm">×</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function(){
  var sel=document.getElementById('roleSel'), sf=document.getElementById('schoolField');
  function upd(){ sf.style.display = sel.value==='teacher' ? '' : 'none'; }
  if(sel){ sel.addEventListener('change',upd); upd(); }
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Jury & Nutzer';
require APP_PATH . '/pages/_layout.php';
