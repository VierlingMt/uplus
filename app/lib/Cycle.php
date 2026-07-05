<?php
/**
 * Wettbewerbszyklus (Wettbewerbsjahr) – zentrales Objekt, an dem Jury,
 * Projektleitung und Schulen hängen. Ein Zyklus ist „aktiv" (das laufende
 * Wettbewerbsjahr); die Historie früherer Jahre bleibt dauerhaft erhalten.
 */

declare(strict_types=1);

final class Cycle
{
    /** Rollen, mit denen eine Person an einem Zyklus teilnimmt. */
    public const ROLES = [
        'juror'        => 'Jury',
        'project_lead' => 'Projektleitung',
    ];

    /** Bildet die Benutzerrolle auf die Zyklus-Rolle ab (teacher = kein Mitglied). */
    public static function roleFor(string $userRole): ?string
    {
        return match ($userRole) {
            'admin', 'lead' => 'project_lead',
            'juror' => 'juror',
            default => null,
        };
    }

    /** Alle Zyklen, neuestes Jahr zuerst. */
    public static function all(): array
    {
        return Database::all('SELECT * FROM competition_cycles ORDER BY year_label DESC, id DESC');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM competition_cycles WHERE id = ?', [$id]);
    }

    /** Der aktive Zyklus (laufendes Wettbewerbsjahr) oder null. */
    public static function active(): ?array
    {
        $row = Database::one('SELECT * FROM competition_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        return $row ?: null;
    }

    public static function activeId(): int
    {
        return (int) (self::active()['id'] ?? 0);
    }

    /** Genau einen Zyklus als aktiv markieren, alle anderen deaktivieren. */
    public static function setActive(int $id): void
    {
        Database::run('UPDATE competition_cycles SET is_active = IF(id = ?, 1, 0)', [$id]);
        Settings::set('active_cycle_id', (string) $id);
    }

    /** IDs der Zyklen, an denen ein Nutzer teilnimmt. @return int[] */
    public static function forUser(int $userId): array
    {
        $rows = Database::all('SELECT cycle_id FROM cycle_members WHERE user_id = ?', [$userId]);
        return array_map(static fn($r) => (int) $r['cycle_id'], $rows);
    }

    /**
     * Mitglieder eines Zyklus (mit Nutzerdaten). Optional nach Zyklus-Rolle filtern.
     */
    public static function members(int $cycleId, ?string $roleInCycle = null): array
    {
        $sql = 'SELECT cm.*, u.name, u.email, u.specialty AS user_specialty, u.is_active
                FROM cycle_members cm JOIN users u ON u.id = cm.user_id
                WHERE cm.cycle_id = ?';
        $params = [$cycleId];
        if ($roleInCycle !== null) {
            $sql .= ' AND cm.role_in_cycle = ?';
            $params[] = $roleInCycle;
        }
        $sql .= ' ORDER BY u.name';
        return Database::all($sql, $params);
    }

    /** Zahl der Mitglieder je Zyklus (für Übersichten). @return array<int,int> */
    public static function memberCounts(?string $roleInCycle = null): array
    {
        $sql = 'SELECT cycle_id, COUNT(*) AS n FROM cycle_members';
        $params = [];
        if ($roleInCycle !== null) {
            $sql .= ' WHERE role_in_cycle = ?';
            $params[] = $roleInCycle;
        }
        $sql .= ' GROUP BY cycle_id';
        $out = [];
        foreach (Database::all($sql, $params) as $r) {
            $out[(int) $r['cycle_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Zyklus-Zugehörigkeit eines Nutzers setzen: fügt fehlende hinzu, entfernt
     * abgewählte, lässt bestehende (mit ihren Notizen) unangetastet. Die
     * Zyklus-Rolle richtet sich nach der aktuellen Benutzerrolle.
     *
     * @param int[] $cycleIds
     */
    public static function syncUser(int $userId, array $cycleIds, string $roleInCycle): void
    {
        $cycleIds = array_values(array_unique(array_map('intval', $cycleIds)));
        $current  = self::forUser($userId);

        foreach (array_diff($current, $cycleIds) as $remove) {
            Database::run('DELETE FROM cycle_members WHERE user_id = ? AND cycle_id = ?', [$userId, $remove]);
        }
        foreach (array_diff($cycleIds, $current) as $add) {
            Database::run(
                'INSERT INTO cycle_members (cycle_id, user_id, role_in_cycle) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE role_in_cycle = VALUES(role_in_cycle)',
                [$add, $userId, $roleInCycle]
            );
        }
    }

    /** Fügt einen Nutzer einem Zyklus hinzu (idempotent). */
    public static function addMember(int $cycleId, int $userId, string $roleInCycle): void
    {
        Database::run(
            'INSERT INTO cycle_members (cycle_id, user_id, role_in_cycle) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE role_in_cycle = VALUES(role_in_cycle)',
            [$cycleId, $userId, $roleInCycle]
        );
    }

    public static function removeMember(int $cycleId, int $userId): void
    {
        Database::run('DELETE FROM cycle_members WHERE cycle_id = ? AND user_id = ?', [$cycleId, $userId]);
    }

    /** IDs der an einem Zyklus teilnehmenden Schulen. @return int[] */
    public static function schoolIds(int $cycleId): array
    {
        $rows = Database::all('SELECT school_id FROM cycle_schools WHERE cycle_id = ?', [$cycleId]);
        return array_map(static fn($r) => (int) $r['school_id'], $rows);
    }

    /** Teilnehmende Schulen eines Zyklus auf die übergebene Menge setzen. @param int[] $schoolIds */
    public static function syncSchools(int $cycleId, array $schoolIds): void
    {
        $schoolIds = array_values(array_unique(array_map('intval', $schoolIds)));
        $current   = self::schoolIds($cycleId);

        foreach (array_diff($current, $schoolIds) as $remove) {
            Database::run('DELETE FROM cycle_schools WHERE cycle_id = ? AND school_id = ?', [$cycleId, $remove]);
        }
        foreach (array_diff($schoolIds, $current) as $add) {
            Database::run('INSERT IGNORE INTO cycle_schools (cycle_id, school_id) VALUES (?,?)', [$cycleId, $add]);
        }
    }

    public static function schoolCounts(): array
    {
        $out = [];
        foreach (Database::all('SELECT cycle_id, COUNT(*) AS n FROM cycle_schools GROUP BY cycle_id') as $r) {
            $out[(int) $r['cycle_id']] = (int) $r['n'];
        }
        return $out;
    }

    // --- Meilensteine (Projektablauf) ------------------------------------

    /** Erlaubte Status-Werte eines Meilensteins. */
    public const MILESTONE_STATUS = ['auto', 'done', 'active', 'upcoming'];

    /** Meilensteine eines Zyklus in Anzeigereihenfolge. */
    public static function milestones(int $cycleId): array
    {
        return Database::all(
            'SELECT * FROM cycle_milestones
             WHERE cycle_id = ?
             ORDER BY sort_order, COALESCE(date_from, "9999-12-31"), id',
            [$cycleId]
        );
    }

    public static function findMilestone(int $id): ?array
    {
        return Database::one('SELECT * FROM cycle_milestones WHERE id = ?', [$id]);
    }

    /**
     * Meilensteine für die Dashboard-Zeitleiste aufbereiten.
     * @return array<int,array{0:string,1:string,2:string}> [Name, Zeitpunkt/-raum, Status]
     */
    public static function milestoneTimeline(int $cycleId, ?string $today = null): array
    {
        $out = [];
        foreach (self::milestones($cycleId) as $m) {
            $out[] = [
                (string) $m['label'],
                self::milestoneDateLabel($m),
                self::milestoneState($m, $today),
            ];
        }
        return $out;
    }

    /** Anzeige-Text für Zeitpunkt bzw. Zeitraum eines Meilensteins. */
    public static function milestoneDateLabel(array $m): string
    {
        $period = trim((string) ($m['period_label'] ?? ''));
        if ($period !== '') {
            return $period;
        }
        $from = $m['date_from'] ?? null;
        $to   = $m['date_to'] ?? null;
        $fmt  = static fn($d) => date('d.m.', (int) strtotime((string) $d));
        if ($from && $to && $from !== $to) {
            return $fmt($from) . '–' . $fmt($to);
        }
        if ($from) {
            return $fmt($from);
        }
        return '';
    }

    /**
     * Zustand (done/active/upcoming) eines Meilensteins. Bei Status „auto"
     * wird er aus den Datumsangaben relativ zu heute abgeleitet.
     */
    public static function milestoneState(array $m, ?string $today = null): string
    {
        $status = (string) ($m['status'] ?? 'auto');
        if ($status !== '' && $status !== 'auto') {
            return $status;
        }
        $today = $today ?? date('Y-m-d');
        $from  = $m['date_from'] ?? null;
        $to    = ($m['date_to'] ?? null) ?: $from;
        if (!$from) {
            return 'upcoming';
        }
        if ($to < $today) {
            return 'done';
        }
        if ($from <= $today && $today <= $to) {
            return 'active';
        }
        return 'upcoming';
    }
}
