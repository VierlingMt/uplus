<?php
/**
 * Session-basierte Authentifizierung & Rollen.
 * Rollen: admin (Projektleitung), teacher (Lehrkraft), juror (Jury).
 */

declare(strict_types=1);

final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $user = Database::one(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [strtolower(trim($email))]
        );
        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        // Rehash bei Bedarf
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        session_regenerate_id(true);
        $_SESSION['uid']  = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['uid']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    private static ?array $cached = null;

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$cached === null) {
            self::$cached = Database::one('SELECT * FROM users WHERE id = ?', [self::id()]);
        }
        return self::$cached;
    }

    /** Zugriff erzwingen; leitet sonst zum Login (oder 403). */
    public static function require(string ...$roles): void
    {
        if (!self::check()) {
            redirect(url('login'));
        }
        if ($roles && !self::is(...$roles)) {
            http_response_code(403);
            render('error', ['title' => 'Kein Zugriff', 'message' => 'Für diesen Bereich fehlt dir die Berechtigung.']);
            exit;
        }
    }
}
