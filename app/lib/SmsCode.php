<?php
/**
 * Passwortloser Login per SMS-Einmalcode (Alternative zum E-Mail-Magic-Link).
 *
 * Ablauf: Nutzer gibt E-Mail an -> ist eine Handynummer hinterlegt, wird ein
 * 6-stelliger Code an diese Nummer geschickt. Der Code wird nur als SHA-256-Hash
 * gespeichert, ist kurz gültig und gegen Erraten (Versuchszähler) geschützt.
 */

declare(strict_types=1);

final class SmsCode
{
    /** Gültigkeitsdauer des Codes in Minuten. */
    private const TTL_MINUTES = 10;

    /** Maximale Fehlversuche je Code, danach ist er verbraucht. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Erzeugt einen 6-stelligen Code für einen Nutzer, speichert dessen Hash und
     * liefert den Klartext-Code (nur dieser gehört in die SMS). Zuvor werden noch
     * offene Codes des Nutzers entwertet.
     */
    public static function issue(int $userId): string
    {
        Database::run('DELETE FROM login_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::run(
            'INSERT INTO login_codes (user_id, code_hash, expires_at, requested_ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
            [$userId, hash('sha256', $code), self::TTL_MINUTES, $_SERVER['REMOTE_ADDR'] ?? null]
        );
        return $code;
    }

    /**
     * Prüft einen Code für einen Nutzer. Liefert bei Erfolg den aktiven
     * Nutzer-Datensatz, sonst null (falsch, abgelaufen, zu viele Versuche oder
     * Konto deaktiviert). Fehlversuche werden gezählt.
     */
    public static function verify(int $userId, string $code): ?array
    {
        $code = trim($code);
        if ($userId <= 0 || !preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $row = Database::one(
            'SELECT * FROM login_codes
             WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if (!$row || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            Database::run('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return null;
        }

        Database::run('UPDATE login_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);

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
