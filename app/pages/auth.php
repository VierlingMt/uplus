<?php
/**
 * Login-Seite (oeffentlich) – passwortlos, mit 6-stelligem Einmalcode.
 *
 * Einheitlicher Ablauf (ein Faktor, ein Button):
 *   1. Nutzer gibt E-Mail ODER Handynummer ein  (POST mode=auto)
 *      - E-Mail      -> 6-stelliger Code per E-Mail
 *      - Handynummer -> 6-stelliger Code per SMS (nur wenn seven.io-Key hinterlegt)
 *   2. Nutzer gibt den Code ein                  (POST mode=verify) -> Session
 *
 * Der Code lässt sich bequem kopieren/einfügen und wird – anders als ein
 * Magic-Link – nicht von E-Mail-Scannern „vorab geklickt" und dadurch entwertet.
 * Alt-Magic-Links (GET ?token=…) werden aus Kompatibilität weiterhin eingelöst.
 */
declare(strict_types=1);

if (Auth::check()) {
    redirect(url('dashboard'));
}

$error    = null;   // Fehlermeldung
$codeStep = false;  // true: Code-Eingabe anzeigen
$devCode  = null;   // ausserhalb der Produktion: Code direkt anzeigen
$sentTo   = null;   // Ziel (E-Mail bzw. Handynummer), an das gesendet wurde
$sentVia  = null;   // 'email' | 'sms' – bestimmt die Info-Formulierung
$typedEmail = (string) input('email', '');
$smsConfigured = Sms::isConfigured();

// --- Alt-Magic-Link einloesen (Rückwärtskompatibilität) ----------------------
$token = (string) input('token', '');
if ($token !== '') {
    $user = MagicLink::consume($token);
    if ($user) {
        Auth::login($user);
        redirect(url('dashboard'));
    }
    $error = 'Dieser Login-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Code an.';
    Audit::event('login.link_invalid', 'Ungültiger oder abgelaufener Login-Link aufgerufen');
}

// Eingabe kann E-Mail ODER Handynummer sein.
$typedId    = trim($typedEmail);
$looksEmail = $typedId !== '' && filter_var(strtolower($typedId), FILTER_VALIDATE_EMAIL) !== false;
$normPhone  = $looksEmail ? null : phone_normalize($typedId);
$validId    = $looksEmail || $normPhone !== null;
$resolveUser = static function () use ($looksEmail, $typedId, $normPhone): ?array {
    if ($looksEmail) {
        return Auth::findActiveByEmail(strtolower($typedId));
    }
    return $normPhone !== null ? Auth::findActiveByPhone($normPhone) : null;
};

/** 6-stelligen Code erzeugen und per E-Mail versenden. Gibt den Code zurück. */
$sendEmailCode = static function (array $user): string {
    $code = SmsCode::issue((int) $user['id']);
    $ttl  = SmsCode::ttlMinutes();
    $email = strtolower(trim((string) $user['email']));

    $subject = 'Dein Login-Code für Unternehmen Plus';
    $text =
        "Hallo " . $user['name'] . ",\n\n" .
        "dein Login-Code für Unternehmen Plus lautet:\n\n" .
        "    " . $code . "\n\n" .
        "Gib den Code auf der Anmeldeseite ein. Er ist " . $ttl . " Minuten gültig.\n\n" .
        "Wenn du diese Anmeldung nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n" .
        "Viele Grüße\nUnternehmen Plus – Wirtschaftsjunioren Forchheim\n";

    $introHtml =
        'Hallo ' . e($user['name']) . ',<br><br>'
        . 'dein Login-Code für <strong>Unternehmen Plus</strong> lautet:'
        . '<div style="margin:22px 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:34px;'
        . 'font-weight:bold;letter-spacing:10px;color:#003594;background:#eef2f7;'
        . 'border-radius:12px;padding:18px 12px;text-align:center">' . e($code) . '</div>';
    $footNote = 'Gib den Code auf der Anmeldeseite ein. Er ist ' . $ttl . ' Minuten gültig. '
        . 'Falls du diese Anmeldung nicht angefordert hast, ignoriere diese E-Mail einfach.';
    $html = Mailer::brandedHtml('Dein Login-Code', $introHtml, null, null, $footNote);

    Mailer::send($email, $subject, $text, $html);
    return $code;
};

if (is_post()) {
    Csrf::check();
    $mode = (string) input('mode', 'auto');

    // --- Code prüfen (E-Mail- oder SMS-Code) ---------------------------------
    if ($mode === 'verify') {
        $uid  = (int) ($_SESSION['login_uid'] ?? 0);
        $code = (string) input('code', '');
        $user = $uid ? SmsCode::verify($uid, $code) : null;
        if ($user) {
            unset($_SESSION['login_uid'], $_SESSION['login_via'], $_SESSION['login_to']);
            Auth::login($user); // protokolliert login.success
            redirect(url('dashboard'));
        }
        $error = 'Der Code ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
        Audit::event('login.code_failed', 'Login-Code falsch/abgelaufen', $uid ? Database::one('SELECT id,name,email FROM users WHERE id=?', [$uid]) : null);
        $codeStep = true;
        $sentVia  = $_SESSION['login_via'] ?? null;
        $sentTo   = $_SESSION['login_to'] ?? null;

    // --- Code anfordern: Kanal aus der Eingabe ableiten ----------------------
    // (mode=auto beim ersten Absenden wie auch beim „Neuen Code anfordern")
    } else {
        if (!$validId) {
            $error = 'Bitte gib eine gültige E-Mail-Adresse oder Handynummer ein.';

        // Handynummer eingegeben -> Einmalcode per SMS
        } elseif (!$looksEmail) {
            if (!$smsConfigured) {
                $error = 'Der SMS-Login ist derzeit nicht verfügbar. Bitte gib deine E-Mail-Adresse ein.';
            } else {
                // Neutral: Ergebnis unabhängig davon, ob es das Konto/die Nummer
                // gibt (kein User-Enumeration).
                unset($_SESSION['login_uid'], $_SESSION['login_via'], $_SESSION['login_to']);
                $user = $resolveUser();
                if ($user && trim((string) ($user['phone'] ?? '')) !== '') {
                    $code = SmsCode::issue((int) $user['id']);
                    $text = 'Unternehmen Plus: Dein Login-Code lautet ' . $code
                          . ' (' . SmsCode::ttlMinutes() . ' Min. gültig).';
                    if (Sms::send((string) $user['phone'], $text)) {
                        $_SESSION['login_uid'] = (int) $user['id'];
                        if (cfg('app_env') !== 'production') { $devCode = $code; }
                    }
                }
                Audit::event('login.code_requested', 'Login-Code (SMS) angefordert' . ($user ? '' : ' (kein Treffer)'), $user ?: null, ['eingabe' => $typedId]);
                $sentTo  = $normPhone;
                $sentVia = 'sms';
                $_SESSION['login_via'] = 'sms';
                $_SESSION['login_to']  = $sentTo;
                $codeStep = true;
            }

        // E-Mail eingegeben -> Einmalcode per E-Mail
        } else {
            unset($_SESSION['login_uid'], $_SESSION['login_via'], $_SESSION['login_to']);
            $user = $resolveUser();
            if ($user) {
                $code = $sendEmailCode($user);
                $_SESSION['login_uid'] = (int) $user['id'];
                if (cfg('app_env') !== 'production') { $devCode = $code; }
            }
            Audit::event('login.code_requested', 'Login-Code (E-Mail) angefordert' . ($user ? '' : ' (kein Treffer)'), $user ?: null, ['eingabe' => $typedId]);
            $sentTo  = strtolower($typedId);
            $sentVia = 'email';
            $_SESSION['login_via'] = 'email';
            $_SESSION['login_to']  = $sentTo;
            $codeStep = true;
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
       von der Einreichung über die Bewertung bis zum Pitch Day.</p>
  </div>
  <div class="login-form">
    <div class="inner">
      <?php if ($codeStep): ?>
        <?php $viaSms = $sentVia === 'sms'; ?>
        <h2>Code eingeben</h2>
        <p class="sub">Wenn dazu ein Konto<?= $viaSms ? ' mit hinterlegter Handynummer' : '' ?> besteht,
           haben wir dir einen 6-stelligen Login-Code
           <?= $viaSms ? 'per SMS' : 'per E-Mail' ?><?= $sentTo ? ' an <strong>' . e($sentTo) . '</strong>' : '' ?> geschickt.
           Gültig für <?= SmsCode::ttlMinutes() ?> Minuten.</p>
        <?php if ($devCode): ?>
          <div class="flash"><strong>Testmodus:</strong> Dein Code lautet <strong><?= e($devCode) ?></strong></div>
        <?php endif; ?>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= url('login') ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="mode" value="verify">
          <div class="field">
            <label for="code">Login-Code</label>
            <input type="text" id="code" name="code" required autofocus
                   inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   autocomplete="one-time-code" placeholder="6-stellig">
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Anmelden</button>
        </form>
        <form method="post" action="<?= url('login') ?>" style="margin-top:12px">
          <?= Csrf::field() ?>
          <input type="hidden" name="mode" value="auto">
          <input type="hidden" name="email" value="<?= e($typedEmail) ?>">
          <button type="submit" class="btn btn--ghost" style="width:100%;justify-content:center">Neuen Code anfordern</button>
        </form>
        <p style="margin-top:14px"><a href="<?= url('login') ?>">Zurück zur Anmeldung</a></p>

      <?php else: ?>
        <h2>Willkommen zurück</h2>
        <p class="sub">Melde dich passwortlos an – gib deine E-Mail-Adresse<?php if ($smsConfigured): ?> oder Handynummer<?php endif; ?> ein.</p>
        <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= url('login') ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="mode" value="auto">
          <div class="field">
            <label for="email">E-Mail<?php if ($smsConfigured): ?> oder Handynummer<?php endif; ?></label>
            <input type="text" id="email" name="email" required autofocus autocomplete="username"
                   placeholder="<?= $smsConfigured ? 'name@schule.de oder 0170 …' : 'name@schule.de' ?>" value="<?= e($typedEmail) ?>">
          </div>
          <button type="submit" class="btn btn--primary" style="width:100%;justify-content:center">Anmelden</button>
        </form>
        <div data-passkey-only hidden style="margin-top:16px">
          <div style="display:flex;align-items:center;gap:10px;color:var(--muted,#6b7785);font-size:12px;margin:0 0 12px">
            <span style="flex:1;height:1px;background:#e2e8f0"></span>oder<span style="flex:1;height:1px;background:#e2e8f0"></span>
          </div>
          <button type="button" class="btn btn--ghost" style="width:100%;justify-content:center"
                  data-passkey-login data-endpoint="<?= url('passkey') ?>" data-csrf="<?= e(Csrf::token()) ?>">🔑 Mit Passkey anmelden</button>
          <div class="flash" data-passkey-msg hidden style="margin-top:12px"></div>
        </div>
        <p class="sub" style="margin-top:16px;font-size:13px">
          Kein Passwort nötig. Wir senden dir einen 6-stelligen Login-Code
          <?php if ($smsConfigured): ?>per E-Mail bzw. – bei einer Handynummer – per SMS<?php else: ?>per E-Mail<?php endif; ?>
          (<?= SmsCode::ttlMinutes() ?> Min. gültig).
          Auf diesem Gerät kannst du dich künftig auch per <strong>Passkey</strong> (Fingerabdruck/Face-ID) anmelden –
          richte ihn nach der Anmeldung im Profil ein.
        </p>
      <?php endif; ?>
      <p class="sub" style="margin-top:22px;font-size:11px;line-height:1.6;color:var(--muted,#8194b5)">
        <?= e(copyright_notice(false)) ?>.<br>
        Idee und Konzept basieren auf dem Erstwettbewerb 2023/24 von Jehona Ahmeti.
      </p>
    </div>
  </div>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/webauthn.js') ?>"></script>
</body>
</html>
