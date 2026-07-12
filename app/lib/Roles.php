<?php
/**
 * Benutzerrollen – zentrale Definitionen für die Mehrfachrollen (n:m über die
 * Tabelle `user_roles`). Ein Nutzer kann mehrere Rollen zugleich haben, z. B.
 * Jury UND Projektleitung (und ggf. Admin).
 *
 *   admin   – Eigentümer/Super-Admin (dauerhaft, z. B. mv@vimatec.de)
 *   lead    – Projektleitung (volle Verwaltung, wechselt jährlich)
 *   teacher – Lehrkraft
 *   juror   – Jury
 *
 * `users.role` bleibt als denormalisierte „Hauptrolle" (höchste Berechtigung)
 * bestehen und wird beim Speichern synchron gehalten – so funktionieren Anzeige
 * und Altbestand weiter, während Berechtigungen die volle Rollenmenge nutzen.
 */

declare(strict_types=1);

final class Roles
{
    /** Alle bekannten Rollen (Auswahl-Reihenfolge). */
    public const ALL = ['admin', 'lead', 'juror', 'teacher'];

    /** Lesbare Bezeichnungen. */
    public const LABELS = [
        'admin'   => 'Admin',
        'lead'    => 'Projektleitung',
        'teacher' => 'Lehrkraft',
        'juror'   => 'Jury',
    ];

    /** Pill-/Chip-Farbklasse je Rolle. */
    public const PILL = [
        'admin'   => 'blue',
        'lead'    => 'blue',
        'juror'   => 'teal',
        'teacher' => 'amber',
    ];

    /** Berechtigungs-Priorität (höher = mächtiger) → bestimmt die Hauptrolle. */
    public const PRIORITY = [
        'admin'   => 4,
        'lead'    => 3,
        'juror'   => 2,
        'teacher' => 1,
    ];

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }

    public static function pill(string $role): string
    {
        return self::PILL[$role] ?? 'muted';
    }

    /** Nur gültige Rollen aus einer Eingabe herausfiltern. @param string[] $roles @return string[] */
    public static function sanitize(array $roles): array
    {
        $roles = array_values(array_unique(array_filter(
            array_map('strval', $roles),
            static fn($r) => isset(self::PRIORITY[$r])
        )));
        // In kanonischer Reihenfolge zurückgeben.
        return array_values(array_filter(self::ALL, static fn($r) => in_array($r, $roles, true)));
    }

    /** Höchste (mächtigste) Rolle einer Menge – die „Hauptrolle". */
    public static function primary(array $roles): ?string
    {
        $roles = self::sanitize($roles);
        if (!$roles) {
            return null;
        }
        usort($roles, static fn($a, $b) => self::PRIORITY[$b] <=> self::PRIORITY[$a]);
        return $roles[0];
    }

    /** Alle Rollen eines Nutzers. @return string[] */
    public static function forUser(int $userId): array
    {
        $rows = Database::all('SELECT role FROM user_roles WHERE user_id = ?', [$userId]);
        return self::sanitize(array_map(static fn($r) => (string) $r['role'], $rows));
    }

    /**
     * Rollen eines Nutzers setzen: `user_roles` abgleichen und die Hauptrolle in
     * `users.role` nachziehen. Erwartet eine bereits geprüfte Rollenmenge.
     *
     * @param string[] $roles
     */
    public static function setForUser(int $userId, array $roles): void
    {
        $roles = self::sanitize($roles);
        if (!$roles) {
            return; // Ein Nutzer ohne Rolle ist nicht vorgesehen.
        }
        $current = self::forUser($userId);
        foreach (array_diff($current, $roles) as $remove) {
            Database::run('DELETE FROM user_roles WHERE user_id = ? AND role = ?', [$userId, $remove]);
        }
        foreach (array_diff($roles, $current) as $add) {
            Database::run('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)', [$userId, $add]);
        }
        Database::run('UPDATE users SET role = ? WHERE id = ?', [self::primary($roles), $userId]);
    }

    /**
     * Abbildung der App-Rollen auf die Zyklus-Rolle (cycle_members kennt genau
     * eine Rolle je Nutzer/Jahr): Projektleitung geht vor Jury.
     */
    public static function cycleRole(array $roles): ?string
    {
        $roles = self::sanitize($roles);
        if (array_intersect(['admin', 'lead'], $roles)) {
            return 'project_lead';
        }
        if (in_array('juror', $roles, true)) {
            return 'juror';
        }
        if (in_array('teacher', $roles, true)) {
            return 'teacher';
        }
        return null;
    }

    /**
     * SQL-Teilausdruck „Nutzer <alias> hat Rolle ?" für WHERE-Klauseln.
     * Beispiel: Roles::hasSql('u') => "EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = ?)"
     * Der Parameter (Rolle) muss in der Reihenfolge an die Query gebunden werden.
     */
    public static function hasSql(string $alias = 'u'): string
    {
        return "EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = {$alias}.id AND ur.role = ?)";
    }
}
