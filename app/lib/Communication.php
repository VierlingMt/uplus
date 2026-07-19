<?php
/**
 * Kommunikation – KI-gestützte Öffentlichkeitsarbeit je Wettbewerbsjahr.
 *
 * Die Projektleitung (Verwaltung) lässt zu drei Anlässen Texte generieren und
 * verbessert sie iterativ per Feedback:
 *   - Social Media (Instagram) nach dem Jury-Feedback,
 *   - Social Media (Instagram) nach dem Pitch Day,
 *   - Pressemitteilung nach dem Pitch Day.
 *
 * Ein „Beitrag" (Zeile in `communication_items`) trägt das Briefing (die Fakten),
 * den aktuellen Text, ein optionales Bild aus der Mediengalerie sowie – nach der
 * Veröffentlichung – den Link zum echten Instagram-Post bzw. die PDF der
 * abgedruckten Pressemitteilung. Jede KI-Generierung wird als Revision
 * (`communication_revisions`) mit dem auslösenden Feedback festgehalten.
 *
 * Alle Beteiligten sehen die VERÖFFENTLICHTEN Beiträge (Nur-Lese); Erstellen,
 * Generieren und Veröffentlichen bleibt der Verwaltung vorbehalten.
 *
 * Hinweis zur Bild-/Mediengenerierung: Der angebundene KI-Dienst (Anthropic
 * Claude) erzeugt Text, keine Bilder. „Text mit Bild" entsteht daher, indem der
 * generierte Text mit einem Bild aus der Mediengalerie (Upload für alle) oder –
 * bei der Pressemitteilung – der abgedruckten PDF kombiniert wird.
 */

declare(strict_types=1);

final class Communication
{
    /**
     * Anlässe (Beitragstypen). key => [Anzeigename, Icon, Kurzbeschreibung, Art].
     * Art: 'social' (Instagram) oder 'press' (Pressemitteilung).
     * @var array<string,array{0:string,1:string,2:string,3:string}>
     */
    public const TYPES = [
        'social_jury' => [
            'Social Media – Jury-Feedback', '📷',
            'Instagram-Beitrag mit Bild rund um das Jury-Feedback.', 'social',
        ],
        'social_pitchday' => [
            'Social Media – Pitch Day', '🎉',
            'Instagram-Beitrag mit Bild zum Finale/Pitch Day.', 'social',
        ],
        'press_release' => [
            'Pressemitteilung – Pitch Day', '📰',
            'Pressemitteilung nach dem Pitch Day (als PDF veröffentlicht).', 'press',
        ],
    ];

    /** Status eines Beitrags: key => [Label, Pill-Klasse]. */
    public const STATUS = [
        'draft'     => ['Entwurf', 'amber'],
        'published' => ['Veröffentlicht', 'teal'],
    ];

    /**
     * Instagram-Blaupause (Stil-/Format-Vorlage). Dient der KI ausschließlich als
     * Tonalitäts- und Aufbau-Referenz – die Fakten kommen stets aus dem Briefing.
     */
    public const INSTAGRAM_BLUEPRINT = <<<TXT
🚀 Pitch Day 2026 – Was für ein Finale! 🎉
211 Schülerinnen und Schüler.
44 Geschäftsideen.
7 Finalteams.
1 großes Ziel: Unternehmertum erlebbar machen.

Beim großen Finale unseres Businessplanwettbewerbs UnternehmenPLUS wurde die Stadthalle @ebermannstadt.de zur Bühne für die Unternehmerinnen und Unternehmer von morgen. 💡

Mit starken Pitches, kreativen Ideen und jeder Menge Mut präsentierten die besten Teams ihre Businesspläne vor unserer Jury und rund 250 Gästen.
🏆 Herzlichen Glückwunsch an unser Siegerteam „Sprayser" vom Ehrenbürg-Gymnasium Forchheim! Auch die Teams „bay mi" @gfs.ebs (2. Platz) und „wor CO fé" EGF (3. Platz) haben mit ihren Ideen begeistert. 👏

Ein riesiges Dankeschön geht an: ❤️ unsere Projektlehrkräfte an den drei Gymnasien, ⚖️ unsere Jury, 🤝 alle Sponsoren – insbesondere die @sparkasse_forchheim als offizieller Bildungssponsor, 🎤 unsere Grußwortredner und Ehrengäste … und natürlich an alle Schülerinnen und Schüler, die bewiesen haben, wie viel Kreativität, Engagement und Unternehmergeist in unserer Region steckt.
Wir sind überzeugt: Wirtschaftliche Bildung lebt von der Praxis – und genau dafür steht UnternehmenPLUS.
#wj #wjforchheim #wirtschaftsjunioren #wjbayern #wjoberfranken #unternehmenplus #unternehmertum #bildung
TXT;

    /**
     * Pressemitteilungs-Blaupause (Struktur-/Stil-Vorlage). Repräsentativer
     * Aufbau einer echten PM: Überschrift, Unterzeile, Fließtext mit Zitat,
     * Dank, Kontakt und Boilerplate „Über die Wirtschaftsjunioren".
     */
    public const PRESS_BLUEPRINT = <<<TXT
Pressemitteilung

Große Bühne für junge Gründerinnen und Gründer: Pitch Day 2026 begeistert mit Ideen, Mut und Unternehmergeist

Mit innovativen Geschäftsideen, überzeugenden Präsentationen und viel unternehmerischem Engagement fand am Mittwoch der Pitch Day 2026 in der Stadthalle Ebermannstadt seinen Höhepunkt. Sieben Schülerteams aus dem Landkreis präsentierten im Finale des Businessplanwettbewerbs „UnternehmenPLUS" der Wirtschaftsjunioren Forchheim ihre Geschäftsideen vor einer hochkarätig besetzten Jury und rund 250 Gästen. […]

Den ersten Platz belegten [Namen] vom [Schule] mit der Geschäftsidee „[Idee]". Platz zwei ging an […], den dritten Platz erreichte […].

[Absätze zum Projekt, Ablauf, Grußworten und zur Jury.]

[Zitat der Projektleitung:] „Unternehmertum lässt sich nicht aus Büchern lernen. […]"

Ein besonderer Dank gilt den Jurymitgliedern, den Projektlehrkräften sowie den Sponsoren – insbesondere der Sparkasse Forchheim als offiziellem Bildungssponsor.

Kontakt für weitere Informationen:
[Name, Organisation, E-Mail, Telefon]

Über die Wirtschaftsjunioren:
Die Wirtschaftsjunioren Deutschland bilden mit mehr als 10.000 Mitgliedern aus allen Bereichen der Wirtschaft den größten deutschen Verband von Unternehmern und Führungskräften unter 40 Jahren.
TXT;

    // --- Typ-Helfer ------------------------------------------------------

    public static function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type][0] ?? $type;
    }

    public static function typeIcon(string $type): string
    {
        return self::TYPES[$type][1] ?? '📣';
    }

    /** Art des Anlasses: 'social' (Instagram) oder 'press' (Pressemitteilung). */
    public static function kindOf(string $type): string
    {
        return self::TYPES[$type][3] ?? 'social';
    }

    public static function statusLabel(string $status): array
    {
        return self::STATUS[$status] ?? [$status, 'muted'];
    }

    // --- Datenzugriff ----------------------------------------------------

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM communication_items WHERE id = ?', [$id]);
    }

    /**
     * Beiträge eines Zyklus (neueste zuerst). Optional nur veröffentlichte
     * (für die Nur-Lese-Ansicht der übrigen Beteiligten).
     */
    public static function forCycle(int $cycleId, bool $onlyPublished = false): array
    {
        $sql = 'SELECT ci.*, u.name AS author
                FROM communication_items ci
                LEFT JOIN users u ON u.id = ci.created_by
                WHERE ci.cycle_id = ?';
        if ($onlyPublished) {
            $sql .= " AND ci.status = 'published'";
        }
        $sql .= ' ORDER BY ci.status = "published" DESC, ci.updated_at DESC, ci.id DESC';
        return Database::all($sql, [$cycleId]);
    }

    /** Revisionen eines Beitrags (neueste zuerst). */
    public static function revisions(int $itemId): array
    {
        return Database::all(
            'SELECT r.*, u.name AS author
             FROM communication_revisions r
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.item_id = ?
             ORDER BY r.id DESC',
            [$itemId]
        );
    }

    /** Bilder der Mediengalerie eines Zyklus – für die Bildauswahl. */
    public static function galleryImages(int $cycleId): array
    {
        return Database::all(
            "SELECT id, title, original_name, stored_name
             FROM media_items
             WHERE cycle_id = ? AND kind = 'image'
             ORDER BY COALESCE(taken_at, created_at) DESC, id DESC",
            [$cycleId]
        );
    }

    // --- KI-Generierung --------------------------------------------------

    /**
     * Text eines Beitrags per KI (neu) generieren bzw. anhand von Feedback
     * verbessern. Speichert das Ergebnis als Revision und aktualisiert den
     * Beitrag. Spiegelt das Muster von Meeting::runClustering().
     *
     * @return array{ok:bool, error?:string}
     */
    public static function generate(int $itemId, ?string $feedback = null): array
    {
        $item = self::find($itemId);
        if (!$item) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden.'];
        }
        $type     = (string) $item['type'];
        $briefing = trim((string) ($item['briefing'] ?? ''));
        if ($briefing === '') {
            return ['ok' => false, 'error' => 'Bitte zuerst ein Briefing (Fakten/Stichpunkte) hinterlegen.'];
        }

        $previous = trim((string) ($item['body'] ?? '')) ?: null;
        $feedback = $feedback !== null ? trim($feedback) : null;
        if ($feedback === '') {
            $feedback = null;
        }
        // Ohne bestehenden Text ergibt Feedback keinen Sinn – dann Neu-Generierung.
        if ($previous === null) {
            $feedback = null;
        }

        $blueprint = self::kindOf($type) === 'press'
            ? self::PRESS_BLUEPRINT
            : self::INSTAGRAM_BLUEPRINT;
        $extra = trim((string) Settings::get('communication_guidance', ''));

        $res = Claude::generateCommunication(
            self::typeLabel($type),
            self::kindOf($type),
            $blueprint,
            $briefing,
            $previous,
            $feedback,
            $extra
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'KI-Fehler'];
        }

        $text  = (string) $res['text'];
        $model = $res['model'] ?? null;

        Database::insert(
            'INSERT INTO communication_revisions (item_id, body, feedback, ai_model, created_by)
             VALUES (?,?,?,?,?)',
            [$itemId, $text, $feedback, $model, Auth::id()]
        );
        Database::run(
            'UPDATE communication_items
                SET body = ?, ai_model = ?, ai_generated_at = NOW()
              WHERE id = ?',
            [$text, $model, $itemId]
        );
        return ['ok' => true];
    }

    // --- PDF (abgedruckte Pressemitteilung) ------------------------------

    /** Speicherort der veröffentlichten Pressemitteilungs-PDFs. */
    public static function pdfDir(): string
    {
        return UPLOAD_PATH . '/communication';
    }

    public static function ensurePdfDir(): bool
    {
        $dir = self::pdfDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    // --- Auto-Briefing: Fakten aus der App vorbefüllen -------------------

    /**
     * Aus dem aktuellen Datenbestand ein Fakten-Gerüst als Briefing-Vorschlag
     * bauen (Wettbewerbsjahr, Kennzahlen, Finalteams/Podium, Sponsoren, Instagram-
     * Handle). Die Projektleitung kann es anschließend anpassen/ergänzen.
     */
    public static function autoBriefing(int $cycleId, string $type): string
    {
        $cycle = Cycle::find($cycleId);
        $lines = [];

        $lines[] = 'Wettbewerb: UnternehmenPLUS der Wirtschaftsjunioren Forchheim';
        if ($cycle) {
            $lines[] = 'Wettbewerbsjahr: ' . (string) $cycle['year_label'];
        }

        // Kennzahlen (aktueller Datenbestand).
        $students = (int) Database::value('SELECT COUNT(*) FROM students');
        $teams    = (int) Database::value("SELECT COUNT(*) FROM teams WHERE status <> 'draft'");
        $finalists = (int) Database::value("SELECT COUNT(*) FROM teams WHERE status = 'nominated'");
        $schools  = count(Cycle::schoolIds($cycleId));
        if ($schools === 0) {
            $schools = (int) Database::value('SELECT COUNT(DISTINCT school_id) FROM teams');
        }
        if ($students > 0) {
            $lines[] = 'Teilnehmende Schülerinnen und Schüler: ' . $students;
        }
        if ($teams > 0) {
            $lines[] = 'Geschäftsideen/Teams: ' . $teams;
        }
        if ($finalists > 0) {
            $lines[] = 'Finalteams: ' . $finalists;
        }
        if ($schools > 0) {
            $lines[] = 'Beteiligte Schulen: ' . $schools;
        }

        // Schul-Bezeichnung inkl. Instagram-Handle (falls gepflegt) für Team-Zeilen.
        $schoolTag = static function (array $row): string {
            $name = trim((string) ($row['short_name'] ?: $row['school_name']));
            $h    = instagram_handle_normalize($row['instagram'] ?? null);
            if ($name === '') {
                return $h !== null ? '@' . $h : '';
            }
            return $name . ($h !== null ? ' @' . $h : '');
        };

        // Podium (Endplatzierung), sonst Finalteams.
        $podium = Database::all(
            "SELECT t.name, t.idea_name, t.final_rank, s.short_name, s.instagram, s.name AS school_name
               FROM teams t JOIN schools s ON s.id = t.school_id
              WHERE t.final_rank IN (1,2,3)
              ORDER BY t.final_rank",
            []
        );
        if ($podium) {
            $lines[] = '';
            $lines[] = 'Platzierungen:';
            foreach ($podium as $p) {
                $school = $schoolTag($p);
                $idea   = trim((string) $p['idea_name']);
                $lines[] = sprintf(
                    '- %d. Platz: Team „%s"%s%s',
                    (int) $p['final_rank'],
                    (string) $p['name'],
                    $idea !== '' ? ' – Idee „' . $idea . '"' : '',
                    $school !== '' ? ' (' . $school . ')' : ''
                );
            }
        } else {
            $fin = Database::all(
                "SELECT t.name, t.idea_name, s.short_name, s.instagram, s.name AS school_name
                   FROM teams t JOIN schools s ON s.id = t.school_id
                  WHERE t.status = 'nominated'
                  ORDER BY t.pitch_order, t.name",
                []
            );
            if ($fin) {
                $lines[] = '';
                $lines[] = 'Finalteams:';
                foreach ($fin as $t) {
                    $school = $schoolTag($t);
                    $idea   = trim((string) $t['idea_name']);
                    $lines[] = sprintf(
                        '- Team „%s"%s%s',
                        (string) $t['name'],
                        $idea !== '' ? ' – Idee „' . $idea . '"' : '',
                        $school !== '' ? ' (' . $school . ')' : ''
                    );
                }
            }
        }

        // Sponsoren des Zyklus (mit Instagram-Handle, falls gepflegt).
        $sponsors = Database::all(
            'SELECT DISTINCT s.name, s.instagram
               FROM sponsor_contributions c JOIN sponsors s ON s.id = c.sponsor_id
              WHERE c.cycle_id = ?
              ORDER BY s.name',
            [$cycleId]
        );
        if ($sponsors) {
            $names = array_map(static function ($r) {
                $h = instagram_handle_normalize($r['instagram'] ?? null);
                return (string) $r['name'] . ($h !== null ? ' (@' . $h . ')' : '');
            }, $sponsors);
            $lines[] = '';
            $lines[] = 'Sponsoren: ' . implode(', ', $names);
            $lines[] = 'Bildungssponsor: Sparkasse Forchheim';
        }

        // Instagram-Handles der Beteiligten (Jury/Projektleitung) – zum Taggen.
        $people = Database::all(
            "SELECT u.name, u.instagram
               FROM cycle_members cm JOIN users u ON u.id = cm.user_id
              WHERE cm.cycle_id = ? AND u.instagram IS NOT NULL AND u.instagram <> ''
              ORDER BY u.name",
            [$cycleId]
        );
        if ($people) {
            $tags = [];
            foreach ($people as $p) {
                $h = instagram_handle_normalize($p['instagram'] ?? null);
                if ($h !== null) {
                    $tags[] = (string) $p['name'] . ' (@' . $h . ')';
                }
            }
            if ($tags) {
                $lines[] = '';
                $lines[] = 'Instagram-Handles der Beteiligten: ' . implode(', ', $tags);
            }
        }

        // Eigener Instagram-Kanal (falls gepflegt).
        $insta = Social::links()['instagram']['url'] ?? '';
        if ($insta !== '') {
            $lines[] = '';
            $lines[] = 'Eigener Instagram-Kanal: ' . $insta;
        }

        // Anlass-spezifische Hinweise.
        $lines[] = '';
        if (self::kindOf($type) === 'press') {
            $lines[] = 'Hinweis: Bitte ein wörtliches Zitat der Projektleitung (Martin Vierling)';
            $lines[] = 'sowie Grußwortredner/Ehrengäste und den Veranstaltungsort ergänzen.';
        } else {
            $lines[] = 'Hinweis: Instagram-Handles (@…) der genannten Schulen, Sponsoren und Gäste ergänzen.';
        }

        return implode("\n", $lines);
    }
}
