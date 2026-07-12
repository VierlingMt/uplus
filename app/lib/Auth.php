<?php
/**
 * Session-basierte Authentifizierung & Rollen.
 * Rollen (Hierarchie):
 *   admin   – Eigentümer/Super-Admin (dauerhaft, z. B. mv@vimatec.de)
 *   lead    – Projektleitung (volle Verwaltung, wechselt jährlich)
 *   teacher – Lehrkraft
 *   juror   – Jury
 * „Verwaltung" (Manager) = admin ODER lead.
 */

declare(strict_types=1);

final class Auth
{
    /**
     * Aktiven Nutzer zu einer E-Mail suchen (fuer den Magic-Link-Versand).
     */
    public static function findActiveByEmail(string $email): ?array
    {
        return Database::one(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [strtolower(trim($email))]
        );
    }

    /**
     * Aktiven Nutzer über die Handynummer finden – tolerant gegenüber der
     * Schreibweise (0170… vs. +49170…). Die Nummer wird vor dem Vergleich ins
     * internationale Format normalisiert; Bestandsnummern sind per Migration
     * bereits normalisiert gespeichert.
     */
    public static function findActiveByPhone(string $phone): ?array
    {
        $norm = phone_normalize($phone);
        if ($norm === null) {
            return null;
        }
        return Database::one(
            'SELECT * FROM users WHERE phone = ? AND is_active = 1 LIMIT 1',
            [$norm]
        );
    }

    /**
     * Session fuer einen bereits verifizierten Nutzer aufbauen (passwortloser
     * Login per Magic-Link). Erwartet einen Nutzer-Datensatz aus der DB.
     */
    public static function login(array $user): void
    {
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        session_regenerate_id(true);
        $_SESSION['uid']   = (int) $user['id'];
        $_SESSION['roles'] = self::loadRoles((int) $user['id'], $user['role'] ?? null);
        $_SESSION['role']  = Roles::primary($_SESSION['roles']) ?? ($user['role'] ?? null);
        $_SESSION['name']  = $user['name'];
        Audit::event('login.success', 'Erfolgreich angemeldet', $user);
    }

    /**
     * Rollenmenge eines Nutzers laden. Fällt – etwa unmittelbar nach der
     * Migration oder bei Altbestand – auf die Einzelrolle zurück, damit nie eine
     * leere Rollenmenge entsteht.
     *
     * @return string[]
     */
    private static function loadRoles(int $userId, ?string $fallback): array
    {
        $roles = Roles::forUser($userId);
        if (!$roles && $fallback !== null) {
            $roles = Roles::sanitize([$fallback]);
        }
        return $roles;
    }

    public static function logout(): void
    {
        Audit::log('login.logout', 'Abgemeldet');
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

    /** Hauptrolle (höchste Berechtigung) – für Anzeige/Badges. */
    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /** Alle Rollen des angemeldeten Nutzers. @return string[] */
    public static function roles(): array
    {
        if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
            return $_SESSION['roles'];
        }
        $r = self::role();
        return $r !== null ? [$r] : [];
    }

    /** Hat der Nutzer mindestens eine der genannten Rollen? */
    public static function is(string ...$roles): bool
    {
        return (bool) array_intersect($roles, self::roles());
    }

    /** Hat genau diese Rolle (Mehrfachrollen berücksichtigt). */
    public static function has(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }

    /** Eigentümer/Super-Admin. */
    public static function isAdmin(): bool
    {
        return self::has('admin');
    }

    /** Projektleitung. */
    public static function isLead(): bool
    {
        return self::has('lead');
    }

    /** Volle Verwaltung: Admin oder Projektleitung. */
    public static function isManager(): bool
    {
        return self::is('admin', 'lead');
    }

    // --- „Ansehen als" (View-as): Admin betrachtet die App aus Nutzersicht ---

    /** Ist gerade eine „Ansehen als"-Sicht aktiv? */
    public static function isImpersonating(): bool
    {
        return isset($_SESSION['impersonator']);
    }

    /** Reale Identität (Admin), während eine View-as-Sicht aktiv ist. */
    public static function impersonator(): ?array
    {
        return $_SESSION['impersonator'] ?? null;
    }

    /**
     * Startet die „Ansehen als"-Sicht auf einen Zielnutzer. Die reale Identität
     * (Admin) wird gesichert, damit sie später wiederhergestellt werden kann.
     */
    public static function startImpersonation(array $target): void
    {
        if (!isset($_SESSION['impersonator'])) {
            $_SESSION['impersonator'] = [
                'uid'   => (int) $_SESSION['uid'],
                'role'  => $_SESSION['role'] ?? null,
                'roles' => self::roles(),
                'name'  => $_SESSION['name'],
            ];
        }
        $_SESSION['uid']   = (int) $target['id'];
        $_SESSION['roles'] = self::loadRoles((int) $target['id'], $target['role'] ?? null);
        $_SESSION['role']  = Roles::primary($_SESSION['roles']) ?? ($target['role'] ?? null);
        $_SESSION['name']  = $target['name'];
        self::$cached = null;
    }

    /** Beendet die View-as-Sicht und stellt die Admin-Identität wieder her. */
    public static function stopImpersonation(): void
    {
        if (isset($_SESSION['impersonator'])) {
            $_SESSION['uid']   = (int) $_SESSION['impersonator']['uid'];
            $_SESSION['role']  = $_SESSION['impersonator']['role'] ?? null;
            $_SESSION['roles'] = $_SESSION['impersonator']['roles'] ?? ($_SESSION['role'] !== null ? [$_SESSION['role']] : []);
            $_SESSION['name']  = $_SESSION['impersonator']['name'];
            unset($_SESSION['impersonator']);
            self::$cached = null;
        }
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

    /** Zugriff auf Verwaltungsbereiche erzwingen (Admin oder Projektleitung). */
    public static function requireManager(): void
    {
        self::require('admin', 'lead');
    }
}
