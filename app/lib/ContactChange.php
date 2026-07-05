<?php
/**
 * Selbstverwaltete Änderung von E-Mail-Adresse und Handynummer – immer mit
 * Bestätigung des NEUEN Kontakts, bevor die Änderung greift:
 *   - E-Mail:  Bestätigungslink an die neue Adresse (Token wie Magic-Link).
 *   - Handy:   6-stelliger SMS-Code an die neue Nummer (wie Login-Code).
 * In der DB liegt nur der SHA-256-Hash des Tokens/Codes.
 */

declare(strict_types=1);

final class ContactChange
{
    private const EMAIL_TTL   = 60; // Minuten
    private const PHONE_TTL   = 10; // Minuten
    private const MAX_ATTEMPTS = 5;

    /** Offene (unbestätigte) Änderungen einer Art für den Nutzer verwerfen. */
    private static function clear(int $userId, string $kind): void
    {
        Database::run(
            'DELETE FROM contact_changes WHERE user_id = ? AND kind = ? AND used_at IS NULL',
            [$userId, $kind]
        );
    }

    /** Neue E-Mail vormerken; liefert den Roh-Token für den Bestätigungslink. */
    public static function issueEmail(int $userId, string $newEmail): string
    {
        self::clear($userId, 'email');
        $raw = bin2hex(random_bytes(32));
        Database::run(
            'INSERT INTO contact_changes (user_id, kind, new_value, secret_hash, expires_at, requested_ip)
             VALUES (?, "email", ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
            [$userId, strtolower(trim($newEmail)), hash('sha256', $raw), self::EMAIL_TTL, $_SERVER['REMOTE_ADDR'] ?? null]
        );
        return $raw;
    }

    /**
     * Bestätigungslink einlösen: prüft den Token, übernimmt die neue E-Mail und
     * entwertet den Token. Liefert bei Erfolg ['user_id','new_value'], sonst null.
     */
    public static function applyEmail(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || !ctype_xdigit($raw)) {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM contact_changes
             WHERE kind = "email" AND secret_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [hash('sha256', $raw)]
        );
        if (!$row) {
            return null;
        }
        // Token immer entwerten (einmalige Nutzung).
        Database::run('UPDATE contact_changes SET used_at = NOW() WHERE id = ?', [$row['id']]);

        // Adresse zwischenzeitlich vergeben? Dann nicht übernehmen.
        $dup = Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$row['new_value'], (int) $row['user_id']]);
        if ($dup) {
            return null;
        }
        Database::run('UPDATE users SET email = ? WHERE id = ?', [$row['new_value'], (int) $row['user_id']]);
        return ['user_id' => (int) $row['user_id'], 'new_value' => (string) $row['new_value']];
    }

    /** Neue Handynummer vormerken; liefert den 6-stelligen SMS-Code. */
    public static function issuePhone(int $userId, string $newPhone): string
    {
        self::clear($userId, 'phone');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::run(
            'INSERT INTO contact_changes (user_id, kind, new_value, secret_hash, expires_at, requested_ip)
             VALUES (?, "phone", ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)',
            [$userId, $newPhone, hash('sha256', $code), self::PHONE_TTL, $_SERVER['REMOTE_ADDR'] ?? null]
        );
        return $code;
    }

    /** Offene, vorgemerkte neue Handynummer des Nutzers (für die Code-Eingabe-Ansicht). */
    public static function pendingPhone(int $userId): ?string
    {
        $row = Database::one(
            'SELECT new_value FROM contact_changes
             WHERE kind = "phone" AND user_id = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        return $row['new_value'] ?? null;
    }

    /**
     * SMS-Code prüfen; bei Erfolg neue Nummer übernehmen. Liefert die neue Nummer
     * oder null (falsch, abgelaufen, zu viele Versuche). Fehlversuche werden gezählt.
     */
    public static function verifyPhone(int $userId, string $code): ?string
    {
        $code = trim($code);
        if ($userId <= 0 || !preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM contact_changes
             WHERE kind = "phone" AND user_id = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if (!$row || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return null;
        }
        if (!hash_equals((string) $row['secret_hash'], hash('sha256', $code))) {
            Database::run('UPDATE contact_changes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            return null;
        }
        Database::run('UPDATE contact_changes SET used_at = NOW() WHERE id = ?', [$row['id']]);
        Database::run('UPDATE users SET phone = ? WHERE id = ?', [$row['new_value'], $userId]);
        return (string) $row['new_value'];
    }

    public static function emailTtlMinutes(): int { return self::EMAIL_TTL; }
    public static function phoneTtlMinutes(): int { return self::PHONE_TTL; }
}
