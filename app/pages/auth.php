<?php
/** Login-Seite (oeffentlich). */
declare(strict_types=1);

if (Auth::check()) {
    redirect(url('dashboard'));
}

$error = null;
if (is_post()) {
    Csrf::check();
    $email = (string) input('email', '');
    $pass  = (string) input('password', '');
    if (Auth::attempt($email, $pass)) {
        redirect(url('dashboard'));
    }
    $error = 'E-Mail oder Passwort ist falsch.';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden – Unternehmen Plus</title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&family=Bitter:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-hero">
    <div class="login-hero__circle"></div>
    <img class="login-hero__logo" src="<?= asset('img/logo.svg') ?>" alt="Unternehmen Plus">
    <span class="login-hero__bar">Businessplanwettbewerb 2025/2026</span>
    <h1>Unternehmen Plus</h1>
    <p>Verwaltungs- und Bewertungsplattform der Wirtschaftsjunioren Forchheim –
       von der Einreichung über die KI-Vorbewertung bis zum Pitch Day.</p>
  </div>
  <div class="login-form">
    <div class="inner">
      <h2>Willkommen zurück</h2>
      <p class="sub">Bitte mit deinen Zugangsdaten anmelden.</p>
      <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= url('login') ?>">
        <?= Csrf::field() ?>
        <div class="field">
          <label for="email">E-Mail</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="username" value="<?= e((string) input('email', '')) ?>">
        </div>
        <div class="field">
          <label for="password">Passwort</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Anmelden</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
