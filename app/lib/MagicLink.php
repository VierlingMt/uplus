<?php
/**
 * Passwortloser Login per Magic-Link.
 *
 * Ablauf: Nutzer gibt seine E-Mail an -> es wird ein einmaliger, zeitlich
 * begrenzter Token erzeugt und per Mail als Login-Link verschickt. Beim
 * Aufruf des Links wird der Token geprueft, verbraucht und die Session
 * aufgebaut. In der Datenbank liegt nur der SHA-256-Hash des Tokens.
 */

declare(strict_types=1);

final class MagicLink
{
    /** Gueltigkeitsdauer eines Login-Links in Minuten. */
    private const TTL_MINUTES = 30;

    /**
     * Erzeugt einen Token fuer einen Nutzer, speichert dessen Hash und liefert
     * den Roh-Token (nur dieser gehoert in den Login-Link). Zuvor werden noch
     * offene Tokens des Nutzers entwertet.
     */
    public static function issue(int $userId): string
    {
        Database::run('DELETE FROM login_tokens WHERE user_id = ? AND used_at IS NULL', [$userId]);

        $raw = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO login_tokens (user_id, token_hash, expires_at, requested_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
            [$userId, hash('sha256', $raw), self::TTL_MINUTES, $_SERVER['REMOTE_ADDR'] ?? null]
        );
        return $raw;
    }

    /**
     * Prueft und verbraucht einen Token. Liefert bei Erfolg den aktiven
     * Nutzer-Datensatz, sonst null (unbekannt, abgelaufen, bereits benutzt
     * oder Konto deaktiviert).
     */
    public static function consume(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || !ctype_xdigit($raw)) {
            return null;
        }

        $row = Database::one(
            'SELECT * FROM login_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [hash('sha256', $raw)]
        );
        if (!$row) {
            return null;
        }

        // Token sofort entwerten (einmalige Nutzung), unabhaengig vom Nutzerstatus.
        Database::run('UPDATE login_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);

        $user = Database::one(
            'SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [$row['user_id']]
        );
        return $user ?: null;
    }

    public static function ttlMinutes(): int
    {
        return self::TTL_MINUTES;
    }
}
