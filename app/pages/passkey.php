<?php
/**
 * Passkey-/WebAuthn-Endpunkte (JSON). Aufruf über ?r=passkey&action=…
 *
 *   register_options / register  – nur angemeldet (Passkey im Profil hinzufügen)
 *   login_options    / login     – öffentlich (Anmeldung per Passkey)
 *
 * CSRF wird über den Header X-CSRF-Token geprüft (JSON-Requests befüllen $_POST
 * nicht). Das eigentliche Credential kommt im JSON-Body.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$pk_json = static function ($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$pk_error = static function (string $msg, int $code = 400) use ($pk_json): void {
    $pk_json(['ok' => false, 'error' => $msg], $code);
};

if (!is_post()) {
    $pk_error('Nur POST.', 405);
}

// CSRF über Header (JSON-Body befüllt $_POST nicht).
if (!hash_equals(Csrf::token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    $pk_error('Sitzung abgelaufen. Bitte Seite neu laden.', 419);
}

$action = (string) input('action');
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

/** Gerätenamen grob aus dem User-Agent raten (nur als Vorschlag). */
$pk_label = static function (): string {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = 'Gerät';
    foreach ([
        'Windows' => 'Windows', 'Mac' => 'Mac', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
        'Android' => 'Android', 'Linux' => 'Linux',
    ] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $os = $name; break; }
    }
    $browser = '';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    return trim($os . ($browser ? ' · ' . $browser : ''));
};

switch ($action) {

    // ---------------------------------------------------------- Registrierung
    case 'register_options': {
        if (!Auth::check()) { $pk_error('Bitte zuerst anmelden.', 401); }
        $u = Auth::user();
        $challenge = WebAuthn::newChallenge();
        $existing = Database::all('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?', [(int) $u['id']]);
        $pk_json([
            'ok'        => true,
            'challenge' => $challenge,
            'rp'        => ['name' => WebAuthn::rpName(), 'id' => WebAuthn::rpId()],
            'user'      => [
                'id'          => WebAuthn::b64urlEncode('u' . (int) $u['id']),
                'name'        => (string) $u['email'],
                'displayName' => (string) $u['name'],
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],    // ES256
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'timeout'          => 60000,
            'attestation'      => 'none',
            'excludeCredentials' => array_map(
                static fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                $existing
            ),
        ]);
        break;
    }

    case 'register': {
        if (!Auth::check()) { $pk_error('Bitte zuerst anmelden.', 401); }
        $uid = (int) Auth::id();
        $challenge = WebAuthn::takeChallenge();
        if (!$challenge) { $pk_error('Keine gültige Anfrage. Bitte erneut versuchen.'); }
        $resp = $body['response'] ?? [];
        try {
            $r = WebAuthn::verifyRegistration(
                WebAuthn::b64urlDecode((string) ($resp['clientDataJSON'] ?? '')),
                WebAuthn::b64urlDecode((string) ($resp['attestationObject'] ?? '')),
                (string) $challenge
            );
        } catch (WebAuthnException $e) {
            $pk_error('Registrierung fehlgeschlagen: ' . $e->getMessage());
        }

        $dup = Database::one('SELECT id, user_id FROM webauthn_credentials WHERE credential_id = ?', [$r['credentialId']]);
        if ($dup) {
            if ((int) $dup['user_id'] !== $uid) {
                $pk_error('Dieser Passkey gehört bereits zu einem anderen Konto.', 409);
            }
            $pk_json(['ok' => true, 'already' => true]);
        }

        $label = trim((string) ($body['label'] ?? '')) ?: $pk_label();
        $transports = null;
        if (!empty($resp['transports']) && is_array($resp['transports'])) {
            $transports = substr(implode(',', array_map('strval', $resp['transports'])), 0, 255);
        }
        Database::insert(
            'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, transports, label)
             VALUES (?,?,?,?,?,?)',
            [$uid, $r['credentialId'], $r['publicKeyPem'], $r['signCount'], $transports, $label]
        );
        Audit::log('passkey.register', 'Passkey hinzugefügt: ' . $label, 'user', $uid);
        $pk_json(['ok' => true, 'label' => $label]);
        break;
    }

    // --------------------------------------------------------------- Anmeldung
    case 'login_options': {
        $challenge = WebAuthn::newChallenge();
        $pk_json([
            'ok'               => true,
            'challenge'        => $challenge,
            'rpId'             => WebAuthn::rpId(),
            'userVerification' => 'preferred',
            'timeout'          => 60000,
            'allowCredentials' => [], // discoverable credentials -> Authenticator wählt
        ]);
        break;
    }

    case 'login': {
        if (Auth::check()) { $pk_json(['ok' => true, 'redirect' => url('dashboard')]); }
        $challenge = WebAuthn::takeChallenge();
        if (!$challenge) { $pk_error('Keine gültige Anfrage. Bitte erneut versuchen.'); }

        $credId = (string) ($body['id'] ?? '');
        if ($credId === '') { $pk_error('Kein Passkey übermittelt.'); }
        $cred = Database::one('SELECT * FROM webauthn_credentials WHERE credential_id = ?', [$credId]);
        if (!$cred) { $pk_error('Dieser Passkey ist nicht hinterlegt. Bitte per Code anmelden.', 404); }

        $resp = $body['response'] ?? [];
        try {
            $newCount = WebAuthn::verifyAssertion(
                WebAuthn::b64urlDecode((string) ($resp['clientDataJSON'] ?? '')),
                WebAuthn::b64urlDecode((string) ($resp['authenticatorData'] ?? '')),
                WebAuthn::b64urlDecode((string) ($resp['signature'] ?? '')),
                (string) $cred['public_key'],
                (string) $challenge
            );
        } catch (WebAuthnException $e) {
            Audit::event('login.passkey_failed', 'Passkey-Anmeldung fehlgeschlagen: ' . $e->getMessage(),
                Database::one('SELECT id,name,email FROM users WHERE id=?', [(int) $cred['user_id']]) ?: null);
            $pk_error('Anmeldung fehlgeschlagen: ' . $e->getMessage());
        }

        $user = Database::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $cred['user_id']]);
        if (!$user) { $pk_error('Das Konto ist deaktiviert.', 403); }

        Database::run('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?', [$newCount, (int) $cred['id']]);
        Auth::login($user); // protokolliert login.success + regeneriert Session
        $pk_json(['ok' => true, 'redirect' => url('dashboard')]);
        break;
    }

    default:
        $pk_error('Unbekannte Aktion.', 404);
}
