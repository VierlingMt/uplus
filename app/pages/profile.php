<?php
/** Eigenes Profil: Name/E-Mail anzeigen, Passwort aendern. */
declare(strict_types=1);

$u = Auth::user();
if (is_post()) {
    Csrf::check();
    $cur = (string) input('current', '');
    $new = (string) input('new', '');
    $rep = (string) input('repeat', '');
    if (!password_verify($cur, (string) $u['password_hash'])) {
        flash('error', 'Aktuelles Passwort ist falsch.');
    } elseif (strlen($new) < 8) {
        flash('error', 'Das neue Passwort muss mindestens 8 Zeichen haben.');
    } elseif ($new !== $rep) {
        flash('error', 'Die neuen Passwörter stimmen nicht überein.');
    } else {
        Database::run('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        flash('success', 'Passwort geändert.');
    }
    redirect(url('profile'));
}

ob_start(); ?>
<div class="page-head"><h1>Mein Profil</h1></div>
<div class="grid cols-2">
  <div class="card"><div class="card__body">
    <label>Name</label><p><?= e($u['name']) ?></p>
    <label class="mt">E-Mail</label><p><?= e($u['email']) ?></p>
    <label class="mt">Rolle</label>
    <p><span class="pill blue"><?= e(['admin'=>'Projektleitung','teacher'=>'Lehrkraft','juror'=>'Jury'][$u['role']] ?? $u['role']) ?></span></p>
  </div></div>
  <div class="card"><div class="card__body">
    <h3 style="margin-top:0">Passwort ändern</h3>
    <form method="post" action="<?= url('profile') ?>">
      <?= Csrf::field() ?>
      <div class="field"><label>Aktuelles Passwort</label><input type="password" name="current" required></div>
      <div class="field"><label>Neues Passwort</label><input type="password" name="new" required></div>
      <div class="field"><label>Neues Passwort wiederholen</label><input type="password" name="repeat" required></div>
      <button class="btn btn--primary">Speichern</button>
    </form>
  </div></div>
</div>
<?php
$content = ob_get_clean();
$title = 'Mein Profil';
require APP_PATH . '/pages/_layout.php';
