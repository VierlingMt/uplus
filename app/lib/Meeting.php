<?php
/**
 * Projekttermine: Kick-Off und Project-Closing je Wettbewerbsjahr.
 *
 * Ein „Meeting" ist ein Datensatz in `project_meetings` (je Zyklus höchstens ein
 * Kick-Off und ein Project-Closing). Der Kick-Off dient dem Abstimmen und
 * Fixieren der Terminschiene (der Meilensteine des Zyklus); das Project-Closing
 * sammelt die Retrospektive-Notizen aller Beteiligten und deren KI-Cluster.
 */

declare(strict_types=1);

final class Meeting
{
    /** Gültige Termin-Typen. key => Anzeigename. */
    public const TYPES = [
        'kickoff' => 'Kick-Off',
        'closing' => 'Project-Closing',
    ];

    /** Retro-Kategorien: key => [Anzeigename, Pill-Farbe, Icon]. */
    public const RETRO_CATEGORIES = [
        'good'    => ['Was lief gut', 'teal', '👍'],
        'bad'     => ['Was lief schlecht', 'red', '👎'],
        'improve' => ['Was können wir verbessern', 'amber', '💡'],
    ];

    /** Standard-Titel je Typ. */
    public static function defaultTitle(string $type): string
    {
        return self::TYPES[$type] ?? 'Projekttermin';
    }

    /** Termin eines Zyklus laden (oder null, wenn noch nicht angelegt). */
    public static function forCycle(int $cycleId, string $type): ?array
    {
        if (!isset(self::TYPES[$type]) || $cycleId <= 0) {
            return null;
        }
        return Database::one(
            'SELECT * FROM project_meetings WHERE cycle_id = ? AND type = ?',
            [$cycleId, $type]
        );
    }

    /** Termin laden und – falls noch nicht vorhanden – anlegen. */
    public static function ensure(int $cycleId, string $type): array
    {
        $m = self::forCycle($cycleId, $type);
        if ($m) {
            return $m;
        }
        Database::insert(
            'INSERT INTO project_meetings (cycle_id, type, title) VALUES (?,?,?)',
            [$cycleId, $type, self::defaultTitle($type)]
        );
        return self::forCycle($cycleId, $type) ?? [];
    }

    // --- Retrospektive-Notizen (Project-Closing) --------------------------

    public static function validCategory(string $cat): bool
    {
        return isset(self::RETRO_CATEGORIES[$cat]);
    }

    public static function categoryLabel(string $cat): string
    {
        return self::RETRO_CATEGORIES[$cat][0] ?? $cat;
    }

    /** Alle Notizen eines Zyklus (mit Verfassername), neueste zuerst. */
    public static function notes(int $cycleId, ?string $category = null): array
    {
        $sql = 'SELECT n.*, u.name AS author
                FROM retro_notes n
                LEFT JOIN users u ON u.id = n.user_id
                WHERE n.cycle_id = ?';
        $params = [$cycleId];
        if ($category !== null && self::validCategory($category)) {
            $sql .= ' AND n.category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY n.created_at DESC, n.id DESC';
        return Database::all($sql, $params);
    }

    /** Notizen einer einzelnen Person in diesem Zyklus. */
    public static function notesByUser(int $cycleId, int $userId): array
    {
        return Database::all(
            'SELECT * FROM retro_notes WHERE cycle_id = ? AND user_id = ?
             ORDER BY FIELD(category, "good","bad","improve"), created_at DESC, id DESC',
            [$cycleId, $userId]
        );
    }

    /** Notiz-Kennzahlen eines Zyklus: gesamt, je Kategorie, Zahl der Beitragenden. */
    public static function noteStats(int $cycleId): array
    {
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(category='good')    AS good,
                    SUM(category='bad')     AS bad,
                    SUM(category='improve') AS improve,
                    COUNT(DISTINCT user_id) AS people
             FROM retro_notes WHERE cycle_id = ?",
            [$cycleId]
        ) ?: [];
        return [
            'total'   => (int) ($row['total'] ?? 0),
            'good'    => (int) ($row['good'] ?? 0),
            'bad'     => (int) ($row['bad'] ?? 0),
            'improve' => (int) ($row['improve'] ?? 0),
            'people'  => (int) ($row['people'] ?? 0),
        ];
    }

    /**
     * Retro-Notizen des Zyklus per KI clustern und zusammenfassen. Das Ergebnis
     * (Themen je Kategorie + konkrete Verbesserungen + Gesamtfazit) wird als JSON
     * am Project-Closing-Termin gespeichert. Spiegelt das Muster von AiEval.
     *
     * @return array{ok:bool, error?:string, themes?:int}
     */
    public static function runClustering(int $cycleId): array
    {
        $notes = self::notes($cycleId);
        if (!$notes) {
            return ['ok' => false, 'error' => 'Es liegen noch keine Notizen vor, die geclustert werden könnten.'];
        }

        $res = Claude::clusterRetro($notes);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'KI-Fehler'];
        }

        self::ensure($cycleId, 'closing');
        Database::run(
            'UPDATE project_meetings
                SET ai_summary = ?, ai_model = ?, ai_generated_at = NOW()
              WHERE cycle_id = ? AND type = "closing"',
            [json_encode($res['data'], JSON_UNESCAPED_UNICODE), $res['model'] ?? null, $cycleId]
        );
        return ['ok' => true, 'themes' => count($res['data']['themes'] ?? [])];
    }

    /** Gespeichertes KI-Cluster eines Zyklus dekodieren (oder null). */
    public static function clusterData(?array $meeting): ?array
    {
        if (!$meeting || empty($meeting['ai_summary'])) {
            return null;
        }
        $data = json_decode((string) $meeting['ai_summary'], true);
        return is_array($data) ? $data : null;
    }
}
