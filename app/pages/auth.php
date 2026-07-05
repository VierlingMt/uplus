<?php
/**
 * Login-Seite (oeffentlich) – passwortlos per Magic-Link.
 *
 * Ablauf:
 *   1. Nutzer gibt seine E-Mail ein  (POST)         -> Login-Link wird gemailt
 *   2. Nutzer klickt den Link (GET ?r=login&token=) -> Session, Weiterleitung
 */
declare(strict_types=1);

if (Auth::check()) {
    redirect(url('dashboard'));
}

$error = null;   // Fehlermeldung (z. B. ungueltiger/abgelaufener Link)
$sent  = false;  // true, nachdem ein Link angefordert wurde
$devLink = null; // ausserhalb der Produktion: Link direkt anzeigen (kein Mailserver noetig)

// --- 1) Magic-Link einloesen -------------------------------------------------
$token = (string) input('token', '');
if ($token !== '') {
    $user = MagicLink::consume($token);
    if ($user) {
        Auth::login($user);
        redirect(url('dashboard'));
    }
    $error = 'Dieser Login-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
}

// --- 2) Magic-Link anfordern -------------------------------------------------
if (is_post()) {
    Csrf::check();
    $email = strtolower(trim((string) input('email', '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Bitte gib eine gültige E-Mail-Adresse ein.';
    } else {
        // Nutzer nur intern nachschlagen. Nach aussen immer die gleiche
        // Bestätigung – so lässt sich nicht herausfinden, welche Adressen
        // ein Konto haben (kein User-Enumeration).
        $user = Auth::findActiveByEmail($email);
        if ($user) {
            $raw  = MagicLink::issue((int) $user['id']);
            $link = abs_url('login', ['token' => $raw]);

            $subject = 'Dein Login-Link für Unternehmen Plus';
            $body =
                "Hallo " . $user['name'] . ",\n\n" .
                "hier ist dein persönlicher Login-Link für Unternehmen Plus:\n\n" .
                $link . "\n\n" .
                "Der Link ist " . MagicLink::ttlMinutes() . " Minuten gültig und kann nur einmal verwendet werden.\n\n" .
                "Wenn du diese Anmeldung nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n" .
                "Viele Grüße\nUnternehmen Plus – Wirtschaftsjunioren Forchheim\n";

            Mailer::send($email, $subject, $body);

            // Zum lokalen Testen (ohne Mailserver) den Link direkt zeigen.
            if (cfg('app_env') !== 'production') {
                $devLink = $link;
            }
        }
        $sent = true;
    }
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
      <?php if ($sent): ?>
        <h2>E-Mail unterwegs</h2>
        <p class="sub">Wenn zu dieser Adresse ein Konto besteht, haben wir dir einen
           Login-Link geschickt. Bitte schau in dein Postfach.</p>
        <?php if ($devLink): ?>
          <div class="flash" style="word-break:break-all">
            <strong>Testmodus:</strong> <a href="<?= e($devLink) ?>">Jetzt anmelden</a>
          </div>
        <?php endif; ?>
        <p style="margin-top:18px"><a href="<?= url('login') ?>">Zurück zur Anmeldung</a></p>
      <?php else: ?>
        <h2>Willkommen zurück</h2>
        <p class="sub">Melde dich passwortlos an: Wir schicken dir einen Login-Link per E-Mail.</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= url('login') ?>">
          <?= Csrf::field() ?>
          <div class="field">
            <label for="email">E-Mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email"
                   inputmode="email" value="<?= e((string) input('email', '')) ?>">
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Login-Link senden</button>
        </form>
        <p class="sub" style="margin-top:16px;font-size:13px">
          Kein Passwort nötig. Der Link ist <?= MagicLink::ttlMinutes() ?> Minuten gültig.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
