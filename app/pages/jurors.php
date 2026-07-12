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
            Audit::log('user.delete', 'Nutzer gelöscht: ' . ($target['email'] ?? ('#' . $id)), 'user', $id);
            flash('success', 'Nutzer gelöscht.');
        }
        redirect(url('jurors'));
    }

    // Anlegen / Bearbeiten
    $selRoles = Roles::sanitize((array) input('roles', []));
    $name  = trim((string) input('name'));
    $email = strtolower(trim((string) input('email')));
    $spec  = trim((string) input('specialty'));
    $phoneRaw = trim((string) input('phone'));
    // Immer im internationalen Format ohne Leerzeichen speichern (z. B. +491709009124).
    $phone = $phoneRaw === '' ? '' : (phone_normalize($phoneRaw) ?? $phoneRaw);
    $school = (int) input('school_id', 0) ?: null;
    $active = input('is_active') ? 1 : 0;
    // Schule nur für Lehrkräfte.
    if (!in_array('teacher', $selRoles, true)) { $school = null; }

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$selRoles) {
        flash('error', 'Bitte Name, gültige E-Mail und mindestens eine Rolle angeben.');
        redirect(url('jurors'));
    }
    // E-Mail-Eindeutigkeit
    $dup = Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]);
    if ($dup) {
        flash('error', 'Diese E-Mail wird bereits verwendet.');
        redirect(url('jurors'));
    }

    // Rollen-Hierarchie absichern: nur der Eigentümer/Admin darf die Admin-Rolle
    // vergeben oder Admin-Konten bearbeiten; das dauerhafte Eigentümer-Konto
    // bleibt immer Admin. (users.role trägt bei Admins immer „admin", da Admin
    // die höchste Priorität hat.)
    $target = $id > 0 ? Database::one('SELECT role, email FROM users WHERE id = ?', [$id]) : null;
    $targetIsAdmin = $target && $target['role'] === 'admin';
    if ($target && strtolower((string) $target['email']) === PERMANENT_OWNER
        && !in_array('admin', $selRoles, true)) {
        $selRoles[] = 'admin';
        $selRoles = Roles::sanitize($selRoles);
    }
    if (!$isOwner && (in_array('admin', $selRoles, true) || $targetIsAdmin)) {
        flash('error', 'Nur ein Admin kann Admin-Konten anlegen oder bearbeiten.');
        redirect(url('jurors'));
    }

    $primary = Roles::primary($selRoles);
    $photo = save_image('photo', 'usr', 'avatars');
    if ($id > 0) {
        Database::run('UPDATE users SET name=?, email=?, specialty=?, phone=?, school_id=?, is_active=? WHERE id=?',
            [$name, $email, $spec ?: null, $phone ?: null, $school, $active, $id]);
        if ($photo) { Database::run('UPDATE users SET photo_path=? WHERE id=?', [$photo, $id]); }
        Roles::setForUser($id, $selRoles); // pflegt user_roles + users.role (Hauptrolle)
        Audit::log('user.update', 'Nutzer bearbeitet: ' . $name . ' <' . $email . '> (' . implode(', ', $selRoles) . ')', 'user', $id);
        flash('success', 'Nutzer aktualisiert.');
    } else {
        $id = Database::insert('INSERT INTO users (role,name,email,specialty,phone,school_id,is_active,photo_path) VALUES (?,?,?,?,?,?,?,?)',
            [$primary, $name, $email, $spec ?: null, $phone ?: null, $school, $active, $photo]);
        Roles::setForUser($id, $selRoles);
        Audit::log('user.create', 'Nutzer angelegt: ' . $name . ' <' . $email . '> (' . implode(', ', $selRoles) . ')', 'user', $id);
        flash('success', 'Nutzer angelegt. Anmeldung erfolgt passwortlos per Login-Link an die E-Mail.');
    }

    // Wettbewerbsjahre zuordnen (nur Jury & Projektleitung; Lehrkräfte hängen an ihrer Schule).
    $roleInCycle = Roles::cycleRole($selRoles);
    if ($roleInCycle !== null) {
        $cycleIds = array_map('intval', (array) input('cycles', []));
        Cycle::syncUser($id, $cycleIds, $roleInCycle);
    } else {
        Cycle::syncUser($id, [], 'juror'); // nur Lehrkraft → keine Zyklus-Mitgliedschaft
    }
    redirect(url('jurors'));
}

$schools = Database::all('SELECT id, name FROM schools ORDER BY name');

// Wettbewerbsjahre (Zyklen) für Filter, Zuordnung im Formular und Übersicht.
$cycles = Cycle::all();

// Filter auf Wettbewerbsjahr – standardmäßig das aktive Jahr. „all" = alle Jahre.
$activeCycleId = Cycle::activeId();
$filterRaw = input('cycle', null);
if ($filterRaw === null) {
    $filterCycleId = $activeCycleId; // Standard: aktuelles Wettbewerbsjahr
} elseif ($filterRaw === 'all') {
    $filterCycleId = 0;
} else {
    $filterCycleId = (int) $filterRaw;
    if ($filterCycleId > 0 && Cycle::find($filterCycleId) === null) {
        $filterCycleId = $activeCycleId;
    }
}
$filterCycle = $filterCycleId ? Cycle::find($filterCycleId) : null;

if ($filterCycleId > 0) {
    // Zum Jahr gehören: Mitglieder des Zyklus (Jury/Projektleitung), die
    // Lehrkräfte der in dem Jahr teilnehmenden Schulen sowie – als
    // jahresübergreifende Servicerolle – die Admin-Konten.
    $users = Database::all(
        'SELECT u.*, s.name AS school_name FROM users u
         LEFT JOIN schools s ON s.id = u.school_id
         WHERE EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "admin")
            OR u.id IN (SELECT user_id FROM cycle_members WHERE cycle_id = ?)
            OR (EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "teacher")
                AND u.school_id IN (SELECT school_id FROM cycle_schools WHERE cycle_id = ?))
         ORDER BY FIELD(u.role,"admin","lead","juror","teacher"), u.name',
        [$filterCycleId, $filterCycleId]
    );
} else {
    $users = Database::all(
        'SELECT u.*, s.name AS school_name FROM users u LEFT JOIN schools s ON s.id = u.school_id
         ORDER BY FIELD(u.role,"admin","lead","juror","teacher"), u.name'
    );
}
// Jahres-Labels + IDs je Nutzer (Labels für die Liste, IDs zum Vorbelegen des Modals)
$userCycles = $userCycleIds = [];
foreach (Database::all(
    'SELECT cm.user_id, cm.cycle_id, c.year_label FROM cycle_members cm
     JOIN competition_cycles c ON c.id = cm.cycle_id ORDER BY c.year_label DESC') as $r) {
    $userCycles[(int) $r['user_id']][] = $r['year_label'];
    $userCycleIds[(int) $r['user_id']][] = (int) $r['cycle_id'];
}

// Rollen je Nutzer (Mehrfachrollen) für Anzeige + Modal-Vorbelegung.
$userRoles = [];
foreach (Database::all('SELECT user_id, role FROM user_roles') as $r) {
    $userRoles[(int) $r['user_id']][] = (string) $r['role'];
}
$rolesOf = fn(array $u) => Roles::sanitize($userRoles[(int) $u['id']] ?? [$u['role']]);

$fill = function (array $u) use ($userCycleIds, $rolesOf): string {
    return e(json_encode([
        'id' => (int) $u['id'], 'roles' => $rolesOf($u), 'name' => $u['name'], 'email' => $u['email'],
        'school_id' => (int) ($u['school_id'] ?? 0) ?: '', 'specialty' => $u['specialty'], 'phone' => $u['phone'],
        'is_active' => (int) $u['is_active'], 'cycles' => $userCycleIds[(int) $u['id']] ?? [],
    ], JSON_UNESCAPED_UNICODE));
};
$imgs = fn(array $u) => !empty($u['photo_path']) ? e(json_encode(['photo' => asset($u['photo_path'])], JSON_UNESCAPED_UNICODE)) : '';

ob_start(); ?>
<div class="page-head">
  <h1>Jury &amp; Nutzer</h1>
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <?php if ($cycles): ?>
      <form method="get" action="<?= url('jurors') ?>" style="display:flex;align-items:center;gap:6px;margin:0">
        <input type="hidden" name="r" value="jurors">
        <label for="cycleFilter" class="muted" style="font-size:14px">Wettbewerbsjahr</label>
        <select id="cycleFilter" name="cycle" onchange="this.form.submit()" style="min-width:130px">
          <?php foreach ($cycles as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $filterCycleId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['year_label']) ?><?= $c['is_active'] ? ' •' : '' ?></option>
          <?php endforeach; ?>
          <option value="all" <?= $filterCycleId === 0 ? 'selected' : '' ?>>Alle Jahre</option>
        </select>
      </form>
    <?php endif; ?>
    <button type="button" class="btn btn--teal" data-modal-open="userModal">+ Neu</button>
  </div>
</div>
<div class="card">
  <div class="card__head"><?= count($users) ?> Nutzer<?= $filterCycle ? ' · Wettbewerbsjahr ' . e($filterCycle['year_label']) : ' · alle Jahre' ?></div>
  <div class="table-wrap">
    <table class="data data--cards">
      <thead><tr><th>Name</th><th>Rolle</th><th>Login</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <?php if (!empty($u['photo_path'])): ?>
                  <img class="avatar" src="<?= asset($u['photo_path']) ?>" alt="">
                <?php else: ?>
                  <span class="avatar avatar--ph" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $u['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <div><strong><?= e($u['name']) ?></strong><br><span class="muted" style="font-size:13px"><?= e($u['email']) ?></span>
              <?php if ($u['school_name']): ?><br><span class="pill muted"><?= e($u['school_name']) ?></span><?php endif; ?>
              <?php if (!empty($userCycles[(int) $u['id']])): ?><br><span class="pill blue" title="Wettbewerbsjahre"><?= e(implode(', ', $userCycles[(int) $u['id']])) ?></span><?php endif; ?>
                </div>
              </div>
            </td>
            <td data-label="Rolle"><span style="display:inline-flex;flex-wrap:wrap;gap:4px">
              <?php foreach ($rolesOf($u) as $r): ?><span class="pill <?= Roles::pill($r) ?>"><?= e(Roles::label($r)) ?></span><?php endforeach; ?>
            </span></td>
            <td data-label="Login"><?php if (!$u['is_active']): ?><span class="pill muted">inaktiv</span><?php else: ?><span class="pill teal">aktiv</span><?php endif; ?></td>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <?php
                $isPermOwner = strtolower((string) $u['email']) === PERMANENT_OWNER;
                $canManageRow = $isOwner || $u['role'] !== 'admin'; // Admin-Konten nur durch Eigentümer
              ?>
              <?php if ($isOwner && $u['id'] !== Auth::id()): ?>
                <a href="<?= url('viewas', ['user' => $u['id']]) ?>" class="btn btn--ghost btn--sm btn--icon" title="App aus Sicht dieses Nutzers ansehen (nur Lesen)">👁</a>
              <?php endif; ?>
              <?php if ($canManageRow): ?>
                <button type="button" class="btn btn--ghost btn--sm" data-modal-open="userModal" data-fill="<?= $fill($u) ?>"<?= $imgs($u) ? ' data-images="' . $imgs($u) . '"' : '' ?>>Bearbeiten</button>
              <?php endif; ?>
              <?php if ($u['id'] !== Auth::id() && !$isPermOwner && $canManageRow): ?>
                <form method="post" action="<?= url('jurors') ?>" style="display:inline" data-confirm="„<?= e($u['name']) ?>“ löschen?">
                  <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="btn btn--danger btn--sm">Löschen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<div class="modal-overlay" id="userModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
    <div class="modal__head">
      <h3 id="userModalTitle" data-modal-title data-title-new="Neuer Nutzer" data-title-edit="Nutzer bearbeiten">Neuer Nutzer</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button>
    </div>
    <form method="post" action="<?= url('jurors') ?>" enctype="multipart/form-data" class="modal__body" data-modal-form>
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="0">
      <?= image_field('photo', null, [
          'label' => 'Porträtfoto', 'aspect' => 1, 'shape' => 'round', 'format' => 'jpeg',
          'hint' => 'Foto hierher ziehen oder klicken – quadratisch zuschneiden, zoomen, drehen.',
      ]) ?>
      <div class="field" id="rolesField"><label>Rollen * <span class="muted" style="font-weight:400">(Mehrfachauswahl)</span></label>
        <div class="role-chips" data-role-chips>
          <?php foreach (Roles::ALL as $rk): ?>
            <?php if ($rk === 'admin' && !$isOwner) { continue; } // Admin-Rolle nur für Eigentümer ?>
            <label class="role-chip">
              <input type="checkbox" name="roles[]" value="<?= e($rk) ?>" <?= $rk === 'juror' ? 'checked' : '' ?>>
              <span><?= e(Roles::label($rk)) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="help">Eine Person kann mehrere Rollen haben – z. B. Jury <em>und</em> Projektleitung.</div>
      </div>
      <div class="field"><label>Name *</label><input type="text" name="name" required></div>
      <div class="field"><label>E-Mail *</label><input type="email" name="email" required></div>
      <div class="field" id="schoolField"><label>Schule (für Lehrkräfte)</label>
        <select name="school_id">
          <option value="">—</option>
          <?php foreach ($schools as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Spezialgebiet (Jury)</label><input type="text" name="specialty" placeholder="z. B. Marketing, Finanzen"></div>
      <div class="field"><label>Handynummer</label><input type="text" name="phone" placeholder="z. B. 0170 9009124 – für SMS-/Handy-Login"><div class="help">Wird international gespeichert (+49…) und erlaubt Login per Handynummer.</div></div>
      <div class="field" id="cyclesField"><label>Wettbewerbsjahre (Teilnahme)</label>
        <?php if (!$cycles): ?>
          <div class="help">Noch kein Wettbewerbsjahr angelegt – zuerst unter „Wettbewerbsjahre“ eines anlegen.</div>
        <?php else: ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
            <?php foreach ($cycles as $c): ?>
              <label style="font-weight:400;white-space:nowrap">
                <input type="checkbox" name="cycles[]" value="<?= (int) $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                <?= e($c['year_label']) ?><?= $c['is_active'] ? ' •' : '' ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="help">Mehrfachauswahl möglich – auch mit Lücken zwischen den Jahren. Die Zuordnung bleibt als Historie erhalten.</div>
        <?php endif; ?>
      </div>
      <div class="field"><label style="font-weight:400"><input type="checkbox" name="is_active" value="1" checked> Aktiv (Login erlaubt)</label></div>
      <p class="muted" style="font-size:13px;margin:0">Anmeldung passwortlos per Login-Link an die E-Mail – kein Passwort nötig.</p>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary" data-label-new="Anlegen" data-label-edit="Speichern">Anlegen</button>
      </div>
    </form>
  </div>
</div>
<style>
  .role-chips{display:flex;flex-wrap:wrap;gap:8px}
  .role-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--line,#d8dee9);
    border-radius:999px;cursor:pointer;font-weight:500;user-select:none}
  .role-chip input{margin:0}
  .role-chip:has(input:checked){background:#003594;color:#fff;border-color:#003594}
</style>
<script>
(function(){
  var chips=document.querySelectorAll('#userModal input[name="roles[]"]');
  var sf=document.getElementById('schoolField'), cf=document.getElementById('cyclesField');
  function has(r){ for(var i=0;i<chips.length;i++){ if(chips[i].value===r && chips[i].checked) return true; } return false; }
  function upd(){
    if(sf) sf.style.display = has('teacher') ? '' : 'none';
    if(cf) cf.style.display = (has('admin')||has('lead')||has('juror')) ? '' : 'none';
  }
  chips.forEach(function(c){ c.addEventListener('change',upd); });
  upd();
})();
</script>
<?php
$content = ob_get_clean();
$title = 'Jury & Nutzer';
require APP_PATH . '/pages/_layout.php';
