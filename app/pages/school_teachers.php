<?php
/** Projektlehrer einer Schule verwalten (Admin & Projektleitung).
 *  Lehrkräfte können sich anmelden und für ihre Schule Businesspläne hochladen,
 *  sehen aber keine Bewertungen. */
declare(strict_types=1);

Auth::requireManager();

$schoolId = (int) input('school', 0);
$school = $schoolId ? Database::one('SELECT * FROM schools WHERE id = ?', [$schoolId]) : null;
if (!$school) { flash('error', 'Schule nicht gefunden.'); redirect(url('schools')); }

if (is_post()) {
    Csrf::check();
    $action = (string) input('action');

    if ($action === 'add_teacher') {
        $name   = trim((string) input('name'));
        $email  = strtolower(trim((string) input('email')));
        $mobileRaw = trim((string) input('mobile'));
        // Immer im internationalen Format ohne Leerzeichen speichern.
        $mobile = $mobileRaw === '' ? '' : (phone_normalize($mobileRaw) ?? $mobileRaw);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte Name und gültige E-Mail angeben.');
        } elseif (Database::value('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', 'Diese E-Mail wird bereits verwendet.');
        } elseif ($mobile !== '' && Database::value('SELECT id FROM users WHERE phone = ?', [$mobile])) {
            flash('error', 'Diese Handynummer wird bereits verwendet.');
        } else {
            $tid = Database::insert(
                'INSERT INTO users (role, name, email, phone, school_id, is_active) VALUES (?,?,?,?,?,1)',
                ['teacher', $name, $email, $mobile ?: null, $schoolId]
            );
            Roles::setForUser($tid, ['teacher']); // Mehrfachrollen-Tabelle pflegen
            Audit::log('teacher.create', 'Projektlehrer angelegt: ' . $name . ' (' . $school['name'] . ')', 'user', $tid);
            flash('success', 'Projektlehrer angelegt. Anmeldung per Login-Link an die E-Mail möglich.');
        }
        redirect(url('school_teachers', ['school' => $schoolId]));
    }

    if ($action === 'update_teacher') {
        $id = (int) input('id');
        $t = Database::one('SELECT * FROM users WHERE id = ? AND school_id = ? AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = users.id AND ur.role = "teacher")', [$id, $schoolId]);
        if ($t) {
            $name   = trim((string) input('name'));
            $email  = strtolower(trim((string) input('email')));
            $mobileRaw = trim((string) input('mobile'));
        // Immer im internationalen Format ohne Leerzeichen speichern.
        $mobile = $mobileRaw === '' ? '' : (phone_normalize($mobileRaw) ?? $mobileRaw);
            $active = input('is_active') ? 1 : 0;
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Bitte Name und gültige E-Mail angeben.');
            } elseif (Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
                flash('error', 'Diese E-Mail wird bereits verwendet.');
            } elseif ($mobile !== '' && Database::value('SELECT id FROM users WHERE phone = ? AND id <> ?', [$mobile, $id])) {
                flash('error', 'Diese Handynummer wird bereits verwendet.');
            } else {
                Database::run('UPDATE users SET name=?, email=?, phone=?, is_active=? WHERE id=?',
                    [$name, $email, $mobile ?: null, $active, $id]);
                Audit::log('teacher.update', 'Projektlehrer bearbeitet: ' . $name, 'user', $id);
                flash('success', 'Projektlehrer aktualisiert.');
            }
        }
        redirect(url('school_teachers', ['school' => $schoolId]));
    }

    if ($action === 'delete_teacher') {
        $id = (int) input('id');
        $tn = (string) Database::value('SELECT name FROM users WHERE id = ? AND school_id = ? AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = users.id AND ur.role = "teacher")', [$id, $schoolId]);
        Database::run('DELETE FROM users WHERE id = ? AND school_id = ? AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = users.id AND ur.role = "teacher")', [$id, $schoolId]);
        Audit::log('teacher.delete', 'Projektlehrer entfernt: ' . ($tn ?: ('#' . $id)), 'user', $id);
        flash('success', 'Projektlehrer entfernt.');
        redirect(url('school_teachers', ['school' => $schoolId]));
    }
}

$teachers = Database::all(
    'SELECT * FROM users WHERE school_id = ? AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = users.id AND ur.role = "teacher") ORDER BY name',
    [$schoolId]
);

ob_start(); ?>
<div class="page-head">
  <h1>Projektlehrer – <?= e($school['name']) ?></h1>
  <a href="<?= url('schools') ?>" class="btn btn--ghost">← Schulen</a>
</div>

<div class="grid cols-2">
  <div class="card">
    <div class="card__head"><?= count($teachers) ?> Projektlehrer</div>
    <div class="table-wrap">
      <table class="data data--cards">
        <thead><tr><th>Name</th><th>E-Mail</th><th>Mobil</th><th>Login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teachers as $t): ?>
          <tr>
            <td data-label="Name"><strong><?= e($t['name']) ?></strong></td>
            <td data-label="E-Mail"><a href="mailto:<?= e($t['email']) ?>"><?= e($t['email']) ?></a></td>
            <td data-label="Mobil"><?= e($t['phone'] ?: '—') ?></td>
            <td data-label="Login"><?= $t['is_active'] ? '<span class="pill teal">aktiv</span>' : '<span class="pill muted">inaktiv</span>' ?></td>
            <td class="row-actions" style="white-space:nowrap;text-align:right">
              <button type="button" class="btn btn--ghost btn--sm"
                      data-modal-open="teacherModal"
                      data-fill='<?= e(json_encode(['id' => (int) $t['id'], 'name' => $t['name'], 'email' => $t['email'], 'mobile' => $t['phone'], 'is_active' => (int) $t['is_active']], JSON_UNESCAPED_UNICODE)) ?>'>Bearbeiten</button>
              <form method="post" action="<?= url('school_teachers', ['school' => $schoolId]) ?>" style="display:inline" data-confirm="„<?= e($t['name']) ?>“ entfernen?">
                <?= Csrf::field() ?><input type="hidden" name="action" value="delete_teacher"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn--danger btn--sm">Entfernen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$teachers): ?><tr><td colspan="5" class="muted">Noch keine Projektlehrer.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card__head">Neuer Projektlehrer</div>
    <div class="card__body">
      <form method="post" action="<?= url('school_teachers', ['school' => $schoolId]) ?>">
        <?= Csrf::field() ?><input type="hidden" name="action" value="add_teacher">
        <div class="field"><label>Name *</label><input type="text" name="name" required></div>
        <div class="field"><label>E-Mail *</label><input type="email" name="email" required></div>
        <div class="field"><label>Mobilnummer</label><input type="text" name="mobile" placeholder="+49 …"></div>
        <button class="btn btn--primary">Anlegen</button>
      </form>
      <p class="muted mt" style="font-size:13px">Projektlehrer können sich per Login-Link (E-Mail) anmelden,
        für ihre Schule Businesspläne hochladen und Teams/Schüler pflegen – sie sehen <strong>keine Bewertungen</strong>.</p>
    </div>
  </div>
</div>

<!-- Bearbeiten-Modal -->
<div class="modal-overlay" id="teacherModal" hidden>
  <div class="modal modal--form" role="dialog" aria-modal="true">
    <div class="modal__head"><h3>Projektlehrer bearbeiten</h3>
      <button type="button" class="modal__close" data-modal-close aria-label="Schließen">&times;</button></div>
    <form method="post" action="<?= url('school_teachers', ['school' => $schoolId]) ?>" class="modal__body" data-modal-form>
      <?= Csrf::field() ?><input type="hidden" name="action" value="update_teacher"><input type="hidden" name="id" value="0">
      <div class="field"><label>Name *</label><input type="text" name="name" required></div>
      <div class="field"><label>E-Mail *</label><input type="email" name="email" required></div>
      <div class="field"><label>Mobilnummer</label><input type="text" name="mobile"></div>
      <div class="field"><label><input type="checkbox" name="is_active" value="1"> Aktiv (Login erlaubt)</label></div>
      <div class="modal__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Abbrechen</button>
        <button class="btn btn--primary">Speichern</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$title = 'Projektlehrer';
require APP_PATH . '/pages/_layout.php';
