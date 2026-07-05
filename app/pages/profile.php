<?php
/** Eigenes Profil: Name/E-Mail/Rolle anzeigen. Login erfolgt passwortlos per Magic-Link. */
declare(strict_types=1);

$u = Auth::user();

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
    <h3 style="margin-top:0">Anmeldung</h3>
    <p class="muted">Die Anmeldung erfolgt passwortlos: Du gibst auf der Login-Seite
       deine E-Mail-Adresse ein und erhältst einen einmaligen Login-Link.
       Es ist kein Passwort nötig.</p>
    <p class="muted" style="margin-top:10px">Login-Links gehen an
       <strong><?= e($u['email']) ?></strong>. Ist die Adresse nicht korrekt,
       wende dich an die Projektleitung.</p>
  </div></div>
</div>
<?php
$content = ob_get_clean();
$title = 'Mein Profil';
require APP_PATH . '/pages/_layout.php';
