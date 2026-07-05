<?php
/** Bestätigt eine selbst angeforderte E-Mail-Änderung (öffentlich, per Token). */
declare(strict_types=1);

$token   = (string) input('token', '');
$applied = $token !== '' ? ContactChange::applyEmail($token) : null;
$ok      = $applied !== null;
$loggedIn = Auth::check();
?>
<!doctype html>
<html lang="de" data-base="<?= e(rtrim(cfg('base_path', ''), '/')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $ok ? 'E-Mail geändert' : 'Bestätigung' ?> – Unternehmen Plus</title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>">
<link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&family=Bitter:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?php require __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-hero">
    <div class="login-hero__circle"></div>
    <img class="login-hero__logo" src="<?= asset('img/logo.svg') ?>" alt="Unternehmen Plus">
    <span class="login-hero__bar">Unternehmen Plus</span>
    <h1>E-Mail-Bestätigung</h1>
    <p>Sichere, passwortlose Verwaltung der Wirtschaftsjunioren Forchheim.</p>
  </div>
  <div class="login-form">
    <div class="inner">
      <?php if ($ok): ?>
        <h2>E-Mail geändert ✓</h2>
        <p class="sub">Deine neue E-Mail-Adresse <strong><?= e($applied['new_value']) ?></strong> ist jetzt
           aktiv. Ab sofort gehen Login-Links an diese Adresse.</p>
        <a class="btn btn--primary" style="width:100%;justify-content:center"
           href="<?= url($loggedIn ? 'profile' : 'login') ?>"><?= $loggedIn ? 'Zum Profil' : 'Zur Anmeldung' ?></a>
      <?php else: ?>
        <h2>Link ungültig</h2>
        <p class="sub">Dieser Bestätigungslink ist ungültig, abgelaufen oder wurde bereits verwendet.
           Fordere die Änderung im Profil bitte erneut an.</p>
        <a class="btn btn--ghost" style="width:100%;justify-content:center"
           href="<?= url($loggedIn ? 'profile' : 'login') ?>"><?= $loggedIn ? 'Zum Profil' : 'Zur Anmeldung' ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
