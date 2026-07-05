<?php
/**
 * Login-Seite (oeffentlich) – passwortlos.
 *
 * Zwei gleichwertige Anmeldewege (je ein Faktor):
 *   A) Magic-Link per E-Mail:  POST mode=email      -> Link gemailt
 *                              GET  ?r=login&token= -> Session, Weiterleitung
 *   B) Einmalcode per SMS:     POST mode=sms        -> 6-stelliger Code an Handy
 *                              POST mode=verify_sms -> Code prüfen, Session
 *   Der SMS-Weg erscheint nur, wenn ein seven.io-API-Key hinterlegt ist.
 */
declare(strict_types=1);

if (Auth::check()) {
    redirect(url('dashboard'));
}

$error   = null;   // Fehlermeldung
$sent    = false;  // true, nachdem ein E-Mail-Link angefordert wurde
$devLink = null;   // ausserhalb der Produktion: Link direkt anzeigen
$smsStep = false;  // true: Code-Eingabe (SMS) anzeigen
$typedEmail = (string) input('email', '');
$smsConfigured = Sms::isConfigured();

// --- Magic-Link einloesen ----------------------------------------------------
$token = (string) input('token', '');
if ($token !== '') {
    $user = MagicLink::consume($token);
    if ($user) {
        Auth::login($user);
        redirect(url('dashboard'));
    }
    $error = 'Dieser Login-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
}

if (is_post()) {
    Csrf::check();
    $mode = (string) input('mode', 'email');

    // --- B2) SMS-Code prüfen -------------------------------------------------
    if ($mode === 'verify_sms') {
        $uid  = (int) ($_SESSION['sms_uid'] ?? 0);
        $code = (string) input('code', '');
        $user = $uid ? SmsCode::verify($uid, $code) : null;
        if ($user) {
            unset($_SESSION['sms_uid']);
            Auth::login($user);
            redirect(url('dashboard'));
        }
        $error = 'Der Code ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
        $smsStep = true;

    // --- B1) SMS-Code anfordern ----------------------------------------------
    } elseif ($mode === 'sms') {
        if (!filter_var(strtolower(trim($typedEmail)), FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte gib eine gültige E-Mail-Adresse ein.';
        } elseif (!$smsConfigured) {
            $error = 'Der SMS-Login ist derzeit nicht verfügbar. Bitte nutze den E-Mail-Link.';
        } else {
            // Neutral: Ergebnis nach aussen unabhängig davon, ob es das Konto
            // gibt oder eine Handynummer hinterlegt ist (kein User-Enumeration).
            unset($_SESSION['sms_uid']);
            $user = Auth::findActiveByEmail(strtolower(trim($typedEmail)));
            if ($user && trim((string) ($user['phone'] ?? '')) !== '') {
                $code = SmsCode::issue((int) $user['id']);
                $text = 'Unternehmen Plus: Dein Login-Code lautet ' . $code
                      . ' (' . SmsCode::ttlMinutes() . ' Min. gültig).';
                if (Sms::send((string) $user['phone'], $text)) {
                    $_SESSION['sms_uid'] = (int) $user['id'];
                }
            }
            $smsStep = true;
        }

    // --- A) Magic-Link per E-Mail anfordern ----------------------------------
    } else {
        $email = strtolower(trim($typedEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte gib eine gültige E-Mail-Adresse ein.';
        } else {
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

                if (cfg('app_env') !== 'production') {
                    $devLink = $link;
                }
            }
            $sent = true;
        }
    }
}
?>
<!doctype html>
<html lang="de" data-base="<?= e(rtrim(cfg('base_path', ''), '/')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden – Unternehmen Plus</title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chivo:wght@400;700;900&family=Bitter:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?php require __DIR__ . '/_pwa_head.php'; ?>
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
      <?php if ($smsStep): ?>
        <h2>Code eingeben</h2>
        <p class="sub">Wenn zu dieser Adresse ein Konto mit hinterlegter Handynummer
           besteht, haben wir dir einen 6-stelligen Code per SMS geschickt.</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= url('login') ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="mode" value="verify_sms">
          <div class="field">
            <label for="code">SMS-Code</label>
            <input type="text" id="code" name="code" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   autocomplete="one-time-code" placeholder="6-stellig">
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Anmelden</button>
        </form>
        <form method="post" action="<?= url('login') ?>" style="margin-top:12px">
          <?= Csrf::field() ?>
          <input type="hidden" name="mode" value="sms">
          <input type="hidden" name="email" value="<?= e($typedEmail) ?>">
          <button type="submit" class="btn btn--ghost" style="width:100%;justify-content:center">Neuen Code anfordern</button>
        </form>
        <p style="margin-top:14px"><a href="<?= url('login') ?>">Zurück zur Anmeldung</a></p>

      <?php elseif ($sent): ?>
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
        <p class="sub">Melde dich passwortlos an – wähle deinen Weg:</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= url('login') ?>">
          <?= Csrf::field() ?>
          <div class="field">
            <label for="email">E-Mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email"
                   inputmode="email" value="<?= e($typedEmail) ?>">
          </div>
          <button type="submit" name="mode" value="email" class="btn btn--primary" style="width:100%;justify-content:center">Login-Link per E-Mail</button>
          <?php if ($smsConfigured): ?>
            <button type="submit" name="mode" value="sms" class="btn btn--ghost" style="width:100%;justify-content:center;margin-top:10px">Code per SMS</button>
          <?php endif; ?>
        </form>
        <p class="sub" style="margin-top:16px;font-size:13px">
          Kein Passwort nötig. Der E-Mail-Link ist <?= MagicLink::ttlMinutes() ?> Min.<?php if ($smsConfigured): ?>,
          der SMS-Code <?= SmsCode::ttlMinutes() ?> Min.<?php endif; ?> gültig.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
