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
        'press'   => 'Presse',
        'sponsor' => 'Sponsor',
        'speaker' => 'Redner',
    ];

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
