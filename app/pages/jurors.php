<?php
/** Nutzer verwalten: Admin, Projektleitung, Lehrkräfte, Jury (Admin & Projektleitung). */
declare(strict_types=1);

Auth::requireManager();

$roles = ['admin' => 'Admin', 'lead' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'];

// Nur der Eigentümer/Super-Admin darf Admin-Konten vergeben, ändern oder löschen.
$isOwner = Auth::isAdmin();
// Dauerhaftes Eigentümer-Konto – vor Löschen/Herabstufen geschützt.
const PERMANENT_OWNER = 'mv@vimatec.de';

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');
    $id = (int) input('id', 0);

    if ($action === 'delete') {
        $target = Database::one('SELECT role, email FROM users WHERE id = ?', [$id]);
        if ($id === Auth::id()) {
            flash('error', 'Du kannst dich nicht selbst löschen.');
        } elseif ($target && strtolower((string) $target['email']) === PERMANENT_OWNER) {
            flash('error', 'Das dauerhafte Admin-Konto kann nicht gelöscht werden.');
        } elseif ($target && $target['role'] === 'admin' && !$isOwner) {
            flash('error', 'Nur ein Admin kann Admin-Konten löschen.');
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

    // Rollen-Hierarchie absichern: nur der Eigentümer/Admin darf die Admin-Rolle
    // vergeben oder Admin-Konten bearbeiten; das dauerhafte Eigentümer-Konto
    // bleibt immer Admin.
    $target = $id > 0 ? Database::one('SELECT role, email FROM users WHERE id = ?', [$id]) : null;
    if ($target && strtolower((string) $target['email']) === PERMANENT_OWNER) {
        $role = 'admin';
    }
    if (!$isOwner && ($role === 'admin' || ($target && $target['role'] === 'admin'))) {
        flash('error', 'Nur ein Admin kann Admin-Konten anlegen oder bearbeiten.');
        redirect(url('jurors', $id ? ['edit' => $id] : []));
    }

    if ($id > 0) {
        Database::run('UPDATE users SET role=?, name=?, email=?, specialty=?, phone=?, school_id=?, is_active=? WHERE id=?',
            [$role, $name, $email, $spec ?: null, $phone ?: null, $school, $active, $id]);
        flash('success', 'Nutzer aktualisiert.');
    } else {
        $id = Database::insert('INSERT INTO users (role,name,email,specialty,phone,school_id,is_active) VALUES (?,?,?,?,?,?,?)',
            [$role, $name, $email, $spec ?: null, $phone ?: null, $school, $active]);
        flash('success', 'Nutzer angelegt. Anmeldung erfolgt passwortlos per Login-Link an die E-Mail.');
    }

    // Wettbewerbsjahre zuordnen (nur Jury & Projektleitung; Lehrkräfte hängen an ihrer Schule).
    $roleInCycle = Cycle::roleFor($role);
    if ($roleInCycle !== null) {
        $cycleIds = array_map('intval', (array) input('cycles', []));
        Cycle::syncUser($id, $cycleIds, $roleInCycle);
    } else {
        Cycle::syncUser($id, [], 'juror'); // Rolle zu Lehrkraft geändert → Zyklen entfernen
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
     ORDER BY FIELD(u.role,"admin","lead","juror","teacher"), u.name'
);

// Wettbewerbsjahre (Zyklen) für die Zuordnung im Formular und die Übersicht
$cycles = Cycle::all();
$editCycleIds = $edit ? Cycle::forUser((int) $edit['id']) : [Cycle::activeId()];
$editCycleIds = array_filter($editCycleIds);
// Jahres-Labels je Nutzer für die Liste
$userCycles = [];
foreach (Database::all(
    'SELECT cm.user_id, c.year_label FROM cycle_members cm
     JOIN competition_cycles c ON c.id = cm.cycle_id ORDER BY c.year_label DESC') as $r) {
    $userCycles[(int) $r['user_id']][] = $r['year_label'];
}

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
              <?php if ($rk === 'admin' && !$isOwner) { continue; } // Admin-Rolle nur für Eigentümer ?>
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
        <div class="field" id="cyclesField"><label>Wettbewerbsjahre (Teilnahme)</label>
          <?php if (!$cycles): ?>
            <div class="help">Noch kein Wettbewerbsjahr angelegt – zuerst unter „Wettbewerbsjahre“ eines anlegen.</div>
          <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
              <?php foreach ($cycles as $c): ?>
                <label style="font-weight:400;white-space:nowrap">
                  <input type="checkbox" name="cycles[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $editCycleIds, true) ? 'checked' : '' ?>>
                  <?= e($c['year_label']) ?><?= $c['is_active'] ? ' •' : '' ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="help">Mehrfachauswahl möglich – auch mit Lücken zwischen den Jahren. Die Zuordnung bleibt als Historie erhalten.</div>
          <?php endif; ?>
        </div>
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
              <?php if ($u['school_name']): ?><br><span class="pill muted"><?= e($u['school_name']) ?></span><?php endif; ?>
              <?php if (!empty($userCycles[(int) $u['id']])): ?><br><span class="pill blue" title="Wettbewerbsjahre"><?= e(implode(', ', $userCycles[(int) $u['id']])) ?></span><?php endif; ?></td>
            <td><span class="pill <?= ['admin'=>'blue','lead'=>'blue','juror'=>'teal','teacher'=>'amber'][$u['role']] ?? 'muted' ?>"><?= e($roles[$u['role']] ?? $u['role']) ?></span></td>
            <td><?php if (!$u['is_active']): ?><span class="pill muted">inaktiv</span><?php else: ?><span class="pill teal">aktiv</span><?php endif; ?></td>
            <td style="white-space:nowrap;text-align:right">
              <?php
                $isPermOwner = strtolower((string) $u['email']) === PERMANENT_OWNER;
                $canManageRow = $isOwner || $u['role'] !== 'admin'; // Admin-Konten nur durch Eigentümer
              ?>
              <?php if ($canManageRow): ?>
                <a href="<?= url('jurors', ['edit' => $u['id']]) ?>" class="btn btn--ghost btn--sm">Bearbeiten</a>
              <?php endif; ?>
              <?php if ($u['id'] !== Auth::id() && !$isPermOwner && $canManageRow): ?>
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
  var sel=document.getElementById('roleSel'), sf=document.getElementById('schoolField'), cf=document.getElementById('cyclesField');
  function upd(){
    if(sf) sf.style.display = sel.value==='teacher' ? '' : 'none';
    if(cf) cf.style.display = sel.value==='teacher' ? 'none' : '';
  }
  if(sel){ sel.addEventListener('change',upd); upd(); }
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Jury & Nutzer';
require APP_PATH . '/pages/_layout.php';
