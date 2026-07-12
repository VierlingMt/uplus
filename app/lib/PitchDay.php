<?php
/**
 * PitchDay-Eventplanung – Stammdaten, Vorlagen und Label-Logik.
 *
 * Der PitchDay ist die Abschlussveranstaltung eines Wettbewerbsjahres
 * (kleines Eventmanagement). Die Daten hängen an einem `events`-Datensatz,
 * der wiederum an einem Wettbewerbszyklus (`competition_cycles`) hängt –
 * so bekommt jeder Jahrgang seine eigene Instanz und die Historie bleibt.
 *
 * Diese Klasse bündelt die wiederverwendbaren Bausteine (Aufgaben-Playbook,
 * Standard-Agenda, Kategorien, Status-Labels), die sowohl der Migrator beim
 * Seed als auch die Seite `event.php` nutzen.
 */

declare(strict_types=1);

final class PitchDay
{
    public const EVENT_TYPE = 'pitchday';

    /** Aufgaben-Kategorien (Reihenfolge = Anzeige-Reihenfolge). */
    public const TASK_CATEGORIES = [
        'location' => 'Location & Technik',
        'catering' => 'Catering',
        'guests'   => 'VIPs & Einladungen',
        'press'    => 'Presse',
        'sponsors' => 'Sponsoren & Roll-Ups',
        'prizes'   => 'Preise & Urkunden',
        'prep'     => 'Tag-Vorbereitung',
        'general'  => 'Sonstiges',
    ];

    /** Aufgaben-Status → [Label, Pill-Klasse]. */
    public const TASK_STATUS = [
        'open'      => ['Offen', 'muted'],
        'requested' => ['Angefragt', 'amber'],
        'confirmed' => ['Zugesagt', 'blue'],
        'done'      => ['Erledigt', 'teal'],
    ];

    /** Gäste-Kategorien. */
    public const GUEST_CATEGORIES = [
        'jury'    => 'Jury',
        'vip'     => 'VIP / Ehrengast',
        'teacher' => 'Lehrkraft',
        'press'   => 'Presse',
        'sponsor' => 'Sponsor',
        'speaker' => 'Redner',
    ];

    /**
     * SELECT für Gäste, das bei verknüpften Gästen (user_id) Name, Organisation,
     * Position und E-Mail LIVE aus dem Nutzerkonto zieht (Kopie nur als Fallback).
     * Caller hängen ` WHERE g.event_id = ? ORDER BY …` an; in ORDER BY dürfen die
     * Alias-Spalten (name/org/position) verwendet werden.
     */
    public const GUEST_SELECT =
        "SELECT g.*,
                COALESCE(NULLIF(u.name, ''), g.name)         AS name,
                COALESCE(NULLIF(u.org, ''), g.org)           AS org,
                COALESCE(NULLIF(u.position, ''), g.position) AS position,
                COALESCE(NULLIF(u.email, ''), g.email)       AS email
         FROM event_guests g
         LEFT JOIN users u ON u.id = g.user_id";

    /** Gäste-/Einladungs-Status → [Label, Pill-Klasse]. */
    public const GUEST_STATUS = [
        'open'       => ['Offen', 'muted'],
        'requested'  => ['Angefragt', 'amber'],
        'confirmed'  => ['Zusage', 'teal'],
        'declined'   => ['Absage', 'red'],
        'substitute' => ['Vertretung', 'blue'],
    ];

    /**
     * Aufgaben-Playbook: die jährlich wiederkehrenden To-dos rund um den
     * PitchDay. `offset` = Tage relativ zum Veranstaltungstag (negativ = davor,
     * positiv = danach). Aus dem Event-Datum wird daraus automatisch die
     * Fälligkeit berechnet – so weiß die Projektleitung, wann was ansteht.
     *
     * @return array<int, array{category:string, title:string, offset:int}>
     */
    public const TEMPLATE_TASKS = [
        // Location & Technik
        ['location', 'Veranstaltungsort buchen (inkl. Bestuhlung & Saalplan)', -90],
        ['location', 'Bühne mit Sitzen für die Jury + Rednerpult organisieren', -21],
        ['location', 'Technik: festes Mikro am Pult + 2 Funkmikrofone', -14],
        ['location', 'Musikanlage für Musik in den Pausen bereitstellen', -14],
        // Catering
        ['catering', 'Catering (Essen & Getränke) anfragen', -56],
        ['catering', 'Catering-Teilnehmerzahl final bestätigen', -7],
        // VIPs & Einladungen
        ['guests', 'VIP-Liste erstellen & Einladungen versenden', -70],
        ['guests', 'Grußworte (ca. 3 Min) & Keynote (ca. 15 Min) klären', -42],
        ['guests', 'Rückmeldungen der VIPs nachfassen', -14],
        // Presse
        ['press', 'Veranstaltung in „WJ Forchheim“ anlegen', -60],
        ['press', 'Presseverteiler vorab über die Veranstaltung informieren', -28],
        ['press', 'Nachberichterstattung an die Presse versenden', 3],
        // Sponsoren & Roll-Ups
        ['sponsors', 'Sponsoren einladen', -56],
        ['sponsors', 'Roll-Ups (WJ + alle Sponsoren) organisieren', -21],
        ['sponsors', 'Roll-Ups nach der Veranstaltung zurückgeben', 5],
        // Preise & Urkunden
        ['prizes', 'Gutscheine für die prämierten Teams besorgen', -21],
        ['prizes', 'Urkunden gestalten, drucken und vorbereiten', -14],
        ['prizes', 'Schoki-Guddies als Dank für Redner & Teams besorgen', -14],
        // Tag-Vorbereitung
        ['prep', 'Bustransfer der Schüler:innen organisieren', -21],
        ['prep', 'Aushänge über Ablauf & Laufwege gestalten und drucken', -7],
        ['prep', 'Sitzreservierungen / Reserviert-Schilder für VIPs vorbereiten', -5],
        ['prep', 'Ablaufplan finalisieren und mit Moderation abstimmen', -5],
    ];

    /**
     * Standard-Agenda des PitchDays (Zeiten aus der Planungsdatei).
     *
     * @return array<int, array{from:?string, to:?string, title:string}>
     */
    public const AGENDA_TEMPLATE = [
        ['13:45', '14:00', 'Eintreffen der Gäste'],
        ['14:00', '14:30', 'Eröffnung & Grußworte'],
        ['14:30', '16:00', 'Pitches der besten Teams'],
        ['16:00', '16:20', 'Pause und Bewertung durch die Jury'],
        ['16:20', '16:40', 'Preisverleihung an die 3 besten Teams'],
        ['16:40', '17:00', 'Gemeinsamer Ausklang'],
        ['17:00', null, 'Bustransfer der Schüler:innen nach Forchheim'],
    ];

    /** [Label, Pill-Klasse] für einen Aufgaben-Status. */
    public static function taskStatus(string $status): array
    {
        return self::TASK_STATUS[$status] ?? [$status, 'muted'];
    }

    /** [Label, Pill-Klasse] für einen Gäste-Status. */
    public static function guestStatus(string $status): array
    {
        return self::GUEST_STATUS[$status] ?? [$status, 'muted'];
    }

    /** Lesbare Kategorie-Bezeichnung einer Aufgabe. */
    public static function taskCategory(string $key): string
    {
        return self::TASK_CATEGORIES[$key] ?? $key;
    }

    /** Lesbare Kategorie-Bezeichnung eines Gasts. */
    public static function guestCategory(string $key): string
    {
        return self::GUEST_CATEGORIES[$key] ?? $key;
    }

    /**
     * Nachname (für die Sortierung) aus einem vollen Namen ableiten: das letzte
     * Wort. Robust genug für „Vorname Nachname" und „Prof. Dr. Vorname Nachname".
     */
    public static function surname(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return $parts ? mb_strtolower((string) end($parts)) : '';
    }

    /**
     * Gästeliste klassisch nach Nachname sortieren (Anzeigename berücksichtigt,
     * also bei Vertretung nach der vertretenden Person). Sortiert in place-frei
     * über eine Kopie und gibt sie zurück.
     *
     * @param array<int,array<string,mixed>> $guests
     * @return array<int,array<string,mixed>>
     */
    public static function sortBySurname(array $guests): array
    {
        usort($guests, static function ($a, $b) {
            $na = self::guestDisplay($a)['name'];
            $nb = self::guestDisplay($b)['name'];
            return [self::surname($na), mb_strtolower($na)] <=> [self::surname($nb), mb_strtolower($nb)];
        });
        return $guests;
    }

    /**
     * Anzeige-Auflösung eines Gasts unter Berücksichtigung einer Vertretung.
     *
     * Sagt eine eingeladene Person ab und schickt eine Vertretung (Status
     * „substitute" mit hinterlegtem Vertreter-Namen), so wird die vertretende
     * Person zur Hauptperson – ihr Name erscheint auf dem Reserviert-Schild und
     * in der VIP-Übersicht. Die/der ursprünglich Eingeladene wird als
     * „vertritt …" ergänzt, damit klar bleibt, für wen die Person einspringt.
     *
     * @param array<string,mixed> $g Datensatz aus event_guests
     * @return array{name:string, subline:?string, org:?string, position:?string, is_substitute:bool}
     */
    public static function guestDisplay(array $g): array
    {
        $subName = trim((string) ($g['sub_name'] ?? ''));
        $isSub   = ($g['status'] ?? '') === 'substitute' && $subName !== '';

        if ($isSub) {
            // Rolle der/des ursprünglich Eingeladenen für den „vertritt …"-Hinweis.
            $origRole = trim(implode(' · ', array_filter([
                trim((string) ($g['position'] ?? '')),
                trim((string) ($g['org'] ?? '')),
            ])));
            $subline = 'vertritt ' . trim((string) $g['name'])
                . ($origRole !== '' ? ' (' . $origRole . ')' : '');
            return [
                'name'          => $subName,
                'subline'       => $subline,
                'org'           => trim((string) ($g['sub_org'] ?? '')) ?: null,
                'position'      => trim((string) ($g['sub_position'] ?? '')) ?: null,
                'is_substitute' => true,
            ];
        }

        return [
            'name'          => (string) ($g['name'] ?? ''),
            'subline'       => null,
            'org'           => trim((string) ($g['org'] ?? '')) ?: null,
            'position'      => trim((string) ($g['position'] ?? '')) ?: null,
            'is_substitute' => false,
        ];
    }

    /**
     * Fälligkeit aus Event-Datum + Offset (Tage) berechnen.
     * Liefert null, wenn kein Event-Datum bekannt ist.
     */
    public static function dueFromOffset(?string $eventDate, ?int $offsetDays): ?string
    {
        if ($eventDate === null || $eventDate === '' || $offsetDays === null) {
            return null;
        }
        $ts = strtotime($eventDate);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', strtotime(($offsetDays >= 0 ? '+' : '-') . abs($offsetDays) . ' days', $ts));
    }

    /**
     * Den PitchDay-Event eines Wettbewerbszyklus finden (oder null).
     */
    public static function eventForCycle(int $cycleId): ?array
    {
        return Database::one(
            "SELECT * FROM events WHERE cycle_id = ? AND type = ? ORDER BY id LIMIT 1",
            [$cycleId, self::EVENT_TYPE]
        );
    }

    /**
     * Standard-Aufgaben aus dem Playbook in ein Event einspielen (mit aus dem
     * Event-Datum berechneten Fälligkeiten). Gibt die Anzahl eingefügter
     * Aufgaben zurück.
     */
    public static function seedTasks(int $eventId, ?string $eventDate): int
    {
        $order = array_keys(self::TASK_CATEGORIES);
        $n = 0;
        foreach (self::TEMPLATE_TASKS as $i => [$cat, $title, $offset]) {
            $sort = (array_search($cat, $order, true) * 100) + $i;
            Database::run(
                'INSERT INTO event_tasks (event_id, category, title, status, offset_days, due_date, sort_order)
                 VALUES (?,?,?,?,?,?,?)',
                [$eventId, $cat, $title, 'open', $offset, self::dueFromOffset($eventDate, $offset), $sort]
            );
            $n++;
        }
        return $n;
    }

    /** Standard-Agenda in ein Event einspielen. Gibt die Anzahl Zeilen zurück. */
    public static function seedAgenda(int $eventId): int
    {
        $n = 0;
        foreach (self::AGENDA_TEMPLATE as $i => [$from, $to, $title]) {
            Database::run(
                'INSERT INTO event_agenda (event_id, time_from, time_to, title, sort_order)
                 VALUES (?,?,?,?,?)',
                [$eventId, $from, $to, $title, ($i + 1) * 10]
            );
            $n++;
        }
        return $n;
    }
}
