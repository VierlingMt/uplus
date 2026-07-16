<?php
/**
 * Moderationskärtchen für den PitchDay.
 *
 * Die Projektleitung moderiert den PitchDay und schreibt sich dafür bislang von
 * Hand Moderationskärtchen – vieles davon (Gäste, Redner, Jury, Ablauf, Preise,
 * Zahlen) steht bereits in der App und wird nur abgetippt. Dieses Modul bildet
 * genau diese Kärtchen ab, im Format DIN A5 quer:
 *
 *   1. Freie Textkarten – die Projektleitung legt beliebig viele eigene Karten
 *      an, ändert Titel/Untertitel/Text, sortiert sie um und löscht sie wieder.
 *      Der Text ist schlankes Markdown (siehe render_markdown()).
 *
 *   2. Bausteinkarten – feste Bausteine, die ihren Inhalt LIVE aus dem System
 *      ziehen (Ehrengäste, Grußworte & Keynote, Jury, Ablauf/Zeitplan,
 *      nominierte Teams, Preise, Zahlen & Fakten). Die Projektleitung kann
 *      zusätzlich eine eigene Moderations-Notiz (Titel/Untertitel/Text) davor
 *      setzen; die Daten selbst bleiben stets aktuell.
 *
 * Anders als die Präsentation (feste Foliensammlung) sind die Karten je
 * Wettbewerbsjahr frei verwaltbar (`moderation_cards`). Eine Vorlage (TEMPLATE)
 * spielt auf Knopfdruck den kompletten, bewährten Moderationsablauf ein –
 * genau wie bei den PitchDay-Aufgaben und der Standard-Agenda.
 *
 * Die eigentliche Karten-HTML entsteht in renderCard(); Bildschirm-Ansicht
 * (moderation.php) und Druck/PDF (moderation_print.php) teilen sich diese
 * Darstellung, damit beide identisch aussehen.
 */

declare(strict_types=1);

final class ModerationCards
{
    /**
     * Bausteinkarten: Kartentyp => Anzeigename. Diese Karten ziehen ihren Inhalt
     * live aus dem System (die Projektleitung pflegt die Daten dort, wo sie
     * ohnehin leben: PitchDay-Orga, Jury & Nutzer, Teams …).
     */
    public const BLOCKS = [
        'facts'    => 'Zahlen & Fakten',
        'guests'   => 'Ehrengäste',
        'speakers' => 'Grußworte & Keynote',
        'jury'     => 'Jury',
        'agenda'   => 'Ablauf / Zeitplan',
        'teams'    => 'Nominierte Teams',
        'prizes'   => 'Preise',
    ];

    /** Ist der Kartentyp eine Bausteinkarte (mit Live-Daten aus dem System)? */
    public static function isBlock(string $type): bool
    {
        return isset(self::BLOCKS[$type]);
    }

    /** Gültigen Kartentyp erzwingen (Fallback: freie Textkarte). */
    public static function validType(string $type): string
    {
        return $type === 'text' || self::isBlock($type) ? $type : 'text';
    }

    /** Lesbare Bezeichnung eines Kartentyps. */
    public static function typeLabel(string $type): string
    {
        return $type === 'text' ? 'Freier Text' : (self::BLOCKS[$type] ?? $type);
    }

    /**
     * Vorlage: der komplette, bewährte Moderationsablauf für den PitchDay
     * (aus den bisherigen Moderationskärtchen der Projektleitung). Wird auf
     * Knopfdruck je Wettbewerbsjahr eingespielt und ist danach frei anpassbar.
     * body ist schlankes Markdown: „- " für Aufzählungen, **fett**, Leerzeile =
     * neuer Absatz.
     *
     * @var array<int,array{type:string,title:string,subtitle:string,body:string}>
     */
    public const TEMPLATE = [
        [
            'type' => 'text', 'title' => 'Begrüßung', 'subtitle' => 'Einstieg',
            'body' => "- **Herzlich willkommen** zum Pitch Day – wieder in der Stadthalle!\n"
                . "- Nach dem großen Erfolg im letzten Jahr: **gleiches Format**.\n"
                . "- Wer mich nicht kennt: Martin – Vorstand, Projektleitung, Unternehmer.",
        ],
        [
            'type' => 'text', 'title' => 'Dank', 'subtitle' => 'Danke an …',
            'body' => "- Ehrengäste (inkl. Schulleitung) & Redner\n"
                . "- Sponsoren & Pressevertreter\n"
                . "- **Sparkasse Forchheim** – „offizieller Bildungssponsor“\n"
                . "- Projektlehrkräfte (alle drei Gymnasien)\n"
                . "- Jurymitglieder & Co-Projektleitung\n"
                . "- Und vor allem: **euch, liebe Schülerinnen und Schüler!**",
        ],
        [
            'type' => 'guests', 'title' => 'Ehrengäste & Redner', 'subtitle' => 'Kurz begrüßen',
            'body' => "- Ehrengäste namentlich begrüßen (Übersicht siehe unten).",
        ],
        [
            'type' => 'text', 'title' => 'Über „Unternehmen Plus“', 'subtitle' => 'Das Projekt',
            'body' => "- „Unternehmen Plus“ ergänzt den **LehrplanPLUS** perfekt.\n"
                . "- Die Schüler:innen erstellen **echte Businesspläne**.\n"
                . "- Wir liefern den **Praxisbezug**: Juryfeedback + Pitch Day.\n"
                . "- Dieses Jahr noch besser: Schulungsvideo, verbesserte Vorlagen und die eigene **uplus App**.",
        ],
        [
            'type' => 'facts', 'title' => 'Zahlen & Fakten', 'subtitle' => 'Das Projekt in Zahlen',
            'body' => "",
        ],
        [
            'type' => 'text', 'title' => 'Und jetzt auf …', 'subtitle' => 'Überleitung',
            'body' => "- Faszinierende Ideen\n"
                . "- Mutige Persönlichkeiten\n"
                . "- Einblicke in die Zukunft unserer Region\n\n"
                . "**Danke – und viel Spaß beim Pitch Day!**",
        ],
        [
            'type' => 'agenda', 'title' => 'Ablauf & Organisatorisches', 'subtitle' => 'Zeitplan',
            'body' => "- Buffet & Getränke: gerne ein Getränk schnappen, Essen in der Pause.\n"
                . "- Toiletten im Eingangsbereich.",
        ],
        [
            'type' => 'speakers', 'title' => 'Grußworte & Keynote', 'subtitle' => 'Reden',
            'body' => "- Redner nacheinander auf die Bühne bitten.\n"
                . "- Danach: kleines Dankeschön überreichen.",
        ],
        [
            'type' => 'jury', 'title' => 'Juroren auf die Bühne', 'subtitle' => 'Vorstellung',
            'body' => "- Jury aufrufen und einzeln auf die Bühne bitten.\n"
                . "- Aufgaben der Jury kurz erklären (siehe Handout).",
        ],
        [
            'type' => 'text', 'title' => 'Ablauf erklären', 'subtitle' => 'Spielregeln',
            'body' => "- ⏱️ **3 Min. Pitch** | **5 Min. Jury-Feedback** | **2 Min. Puffer**\n"
                . "- ⏰ Timekeeper benennen.\n"
                . "- Die nominierten Teams pitchen (Auswahl aus allen Gruppen!).\n"
                . "- Details siehe Handout.",
        ],
        [
            'type' => 'teams', 'title' => 'Nominierte Teams', 'subtitle' => 'Pitch-Reihenfolge',
            'body' => "- Teams in dieser Reihenfolge auf die Bühne aufrufen.",
        ],
        [
            'type' => 'prizes', 'title' => 'Preisverleihung', 'subtitle' => 'Prämierung',
            'body' => "- Sponsoren, Jury und Ehrengäste auf die Bühne bitten.\n"
                . "- 📸 Fotos bei Platz 1–3.\n"
                . "- 📸 Gemeinsames Bild mit ALLEN zum Abschluss.",
        ],
        [
            'type' => 'text', 'title' => 'Gemeinsamer Ausklang', 'subtitle' => 'Abschluss',
            'body' => "- **Vielen Dank** für die vielen guten Ideen – schade, dass wir nicht alle zeigen konnten.\n"
                . "- Nicht vergessen: Für viele war es das **erste Mal** bei so einem Thema.\n"
                . "- Learning: Nicht jede „Schnaps“-Idee ist eine gute Geschäftsidee!\n"
                . "- Wenn nur eine:r durch dieses Format gründet, hat sich alles gelohnt.\n"
                . "- Gute Gespräche bei Häppchen & Getränken – **um 17 Uhr fahren die Busse** nach Forchheim! 👏\n"
                . "- Danke an alle, die dieses Format möglich gemacht haben!",
        ],
    ];

    /** Alle Karten eines Wettbewerbsjahres in Anzeigereihenfolge. */
    public static function all(int $cycleId): array
    {
        return Database::all(
            'SELECT * FROM moderation_cards WHERE cycle_id = ? ORDER BY sort_order, id',
            [$cycleId]
        );
    }

    /** Eine Karte des Jahres finden (oder null). */
    public static function find(int $id, int $cycleId): ?array
    {
        return Database::one(
            'SELECT * FROM moderation_cards WHERE id = ? AND cycle_id = ?',
            [$id, $cycleId]
        );
    }

    /** Anzahl Karten eines Jahres. */
    public static function count(int $cycleId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM moderation_cards WHERE cycle_id = ?', [$cycleId]);
    }

    /**
     * Karte anlegen oder aktualisieren. Gibt die Karten-ID zurück.
     * Neue Karten landen ans Ende (größte sort_order + 10).
     */
    public static function save(int $cycleId, int $id, string $type, string $title, string $subtitle, string $body): int
    {
        $type = self::validType($type);
        if ($id) {
            Database::run(
                'UPDATE moderation_cards SET card_type = ?, title = ?, subtitle = ?, body = ?, updated_at = NOW()
                 WHERE id = ? AND cycle_id = ?',
                [$type, $title, $subtitle, $body, $id, $cycleId]
            );
            return $id;
        }
        $next = (int) Database::value(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM moderation_cards WHERE cycle_id = ?',
            [$cycleId]
        );
        return (int) Database::insert(
            'INSERT INTO moderation_cards (cycle_id, card_type, title, subtitle, body, sort_order)
             VALUES (?,?,?,?,?,?)',
            [$cycleId, $type, $title, $subtitle, $body, $next]
        );
    }

    /** Karte löschen. */
    public static function delete(int $id, int $cycleId): void
    {
        Database::run('DELETE FROM moderation_cards WHERE id = ? AND cycle_id = ?', [$id, $cycleId]);
    }

    /** Karte um eine Position nach oben/unten verschieben (↑/↓). */
    public static function move(int $cycleId, int $id, string $dir): void
    {
        $ids = array_map(
            static fn($r) => (int) $r['id'],
            Database::all('SELECT id FROM moderation_cards WHERE cycle_id = ? ORDER BY sort_order, id', [$cycleId])
        );
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return;
        }
        $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($swap < 0 || $swap >= count($ids)) {
            return;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $i => $cid) {
            Database::run('UPDATE moderation_cards SET sort_order = ? WHERE id = ? AND cycle_id = ?', [($i + 1) * 10, $cid, $cycleId]);
        }
    }

    /** Vorlage (kompletter Moderationsablauf) für ein Jahr einspielen. Anzahl Karten. */
    public static function seed(int $cycleId): int
    {
        $n = 0;
        foreach (self::TEMPLATE as $i => $c) {
            Database::insert(
                'INSERT INTO moderation_cards (cycle_id, card_type, title, subtitle, body, sort_order)
                 VALUES (?,?,?,?,?,?)',
                [$cycleId, self::validType($c['type']), $c['title'], $c['subtitle'], $c['body'], ($i + 1) * 10]
            );
            $n++;
        }
        return $n;
    }

    /**
     * Live-Daten für die Bausteinkarten eines Jahres einsammeln (aus PitchDay,
     * Jury & Nutzer, Teams …). Alles, was eine Bausteinkarte anzeigen kann.
     *
     * @return array<string,mixed>
     */
    public static function context(int $cycleId): array
    {
        $cycle = $cycleId ? Cycle::find($cycleId) : null;
        $event = $cycleId ? PitchDay::eventForCycle($cycleId) : null;

        $ctx = [
            'cycle_id'   => $cycleId,
            'year_label' => (string) ($cycle['year_label'] ?? ''),
            'event'      => $event,
            'guests'     => [],   // Ehrengäste (VIPs)
            'speakers'   => [],   // Grußworte & Keynote
            'jury'       => [],   // Jury
            'agenda'     => [],   // Ablaufplan
            'prizes'     => [],   // Preise
            'teams'      => [],   // nominierte Teams
            'fallback'   => [],   // Nachrücker
            'members'    => [],   // Teammitglieder je Team-ID
            'facts'      => [],   // [Label, Wert] – Zahlen & Fakten
        ];

        if ($event) {
            $eventId = (int) $event['id'];
            $guests  = Database::all(
                PitchDay::GUEST_SELECT . " WHERE g.event_id = ? ORDER BY COALESCE(NULLIF(u.name,''), g.name)",
                [$eventId]
            );
            // Absagen tauchen in den Moderationskarten nicht auf.
            $attending = array_values(array_filter($guests, static fn($g) => $g['status'] !== 'declined'));

            $ctx['guests'] = PitchDay::sortBySurname(array_filter($attending, static fn($g) => $g['category'] === 'vip'));
            $ctx['jury']   = PitchDay::sortBySurname(array_filter($attending, static fn($g) => $g['category'] === 'jury'));

            // Grußworte & Keynote in der manuell festgelegten Reihenfolge (sort_order),
            // sonst Grußworte vor Keynote, dann Nachname – wie im Handout.
            $speakers = array_values(array_filter(
                $attending,
                static fn($g) => (int) $g['greeting'] === 1 || (int) $g['keynote'] === 1
            ));
            usort($speakers, static fn($a, $b) =>
                [(int) $a['sort_order'], (int) $a['keynote'], PitchDay::surname(PitchDay::guestDisplay($a)['name'])]
                <=> [(int) $b['sort_order'], (int) $b['keynote'], PitchDay::surname(PitchDay::guestDisplay($b)['name'])]);
            $ctx['speakers'] = $speakers;

            $ctx['agenda'] = Database::all(
                'SELECT * FROM event_agenda WHERE event_id = ? ORDER BY sort_order, time_from, id',
                [$eventId]
            );
            $ctx['prizes'] = Database::all(
                "SELECT * FROM event_budget_items WHERE event_id = ? AND kind = 'prize'
                 ORDER BY place IS NULL, place, sort_order, id",
                [$eventId]
            );
        }

        // Nominierte Teams (+ Nachrücker) und ihre Mitglieder – wie im Handout.
        $pitchRows = Database::all(
            "SELECT t.id, t.name, t.idea_name, t.status, t.pitch_order, s.short_name, s.name AS school_name
             FROM teams t JOIN schools s ON s.id = t.school_id
             WHERE t.status IN ('nominated','fallback')
             ORDER BY FIELD(t.status,'nominated','fallback'), t.pitch_order IS NULL, t.pitch_order, s.short_name, t.name"
        );
        $ctx['teams']    = array_values(array_filter($pitchRows, static fn($t) => $t['status'] === 'nominated'));
        $ctx['fallback'] = array_values(array_filter($pitchRows, static fn($t) => $t['status'] === 'fallback'));
        if ($pitchRows) {
            $ids = array_map(static fn($t) => (int) $t['id'], $pitchRows);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT team_id, name FROM students WHERE team_id IN ($in) ORDER BY name", $ids) as $st) {
                $ctx['members'][(int) $st['team_id']][] = $st['name'];
            }
        }

        $ctx['facts'] = self::buildFacts($cycleId, $ctx);

        return $ctx;
    }

    /**
     * Zahlen & Fakten für die gleichnamige Bausteinkarte zusammenstellen. Zieht
     * live aus dem System; Werte von 0 werden weggelassen.
     *
     * @return array<int,array{0:string,1:string}>  Liste [Label, Wert]
     */
    private static function buildFacts(int $cycleId, array $ctx): array
    {
        // Teilnehmende Schulen des Jahres (Fallback: alle Schulen).
        $schools = $cycleId ? count(Cycle::schoolIds($cycleId)) : 0;
        if ($schools === 0) {
            $schools = (int) Database::value('SELECT COUNT(*) FROM schools');
        }
        $students  = (int) Database::value('SELECT COUNT(*) FROM students');
        $teams     = (int) Database::value('SELECT COUNT(*) FROM teams');
        // Jurymitglieder: bevorzugt die übernommenen Gäste, sonst die im Jahr
        // hinterlegten Juror:innen.
        $jury = count($ctx['jury']);
        if ($jury === 0 && $cycleId) {
            $jury = (int) Database::value(
                "SELECT COUNT(*) FROM cycle_members WHERE cycle_id = ? AND role_in_cycle = 'juror'",
                [$cycleId]
            );
        }
        $nominated = (int) Database::value("SELECT COUNT(*) FROM teams WHERE status = 'nominated'");

        $facts = [];
        $add = static function (string $label, int $n) use (&$facts) {
            if ($n > 0) {
                $facts[] = [$label, (string) $n];
            }
        };
        $add('Gymnasien im Landkreis', $schools);
        $add('Schülerinnen und Schüler', $students);
        $add('Teams & Businesspläne', $teams);
        $add('Jurymitglieder', $jury);
        $add('nominierte Teams für den Pitch', $nominated);

        return $facts;
    }

    /**
     * Inneres HTML einer Karte erzeugen (ohne Kartenrahmen). Screen- und
     * Druckansicht nutzen dieselbe Ausgabe.
     *
     * @param array $card Datensatz aus moderation_cards
     * @param array $ctx  Live-Daten aus context()
     */
    public static function renderCard(array $card, array $ctx, int $index, int $total): string
    {
        $type = self::validType((string) ($card['card_type'] ?? 'text'));
        ob_start();
        ?>
        <div class="mc-card__inner">
          <div class="mc-card__top">
            <span class="mc-kicker">Wirtschaftsjunioren Forchheim · PitchDay<?= $ctx['year_label'] !== '' ? ' · ' . e($ctx['year_label']) : '' ?></span>
            <span class="mc-step"><?= $index + 1 ?> / <?= $total ?></span>
          </div>
          <?php if (trim((string) $card['title']) !== ''): ?><h2 class="mc-h"><?= e((string) $card['title']) ?></h2><?php endif; ?>
          <?php if (trim((string) $card['subtitle']) !== ''): ?><div class="mc-sub"><?= e((string) $card['subtitle']) ?></div><?php endif; ?>
          <div class="mc-content">
            <?php if (trim((string) $card['body']) !== ''): ?>
              <div class="mc-body"><?= render_markdown((string) $card['body']) ?></div>
            <?php endif; ?>
            <?php if (self::isBlock($type)): ?>
              <?php self::renderBlock($type, $ctx); ?>
            <?php endif; ?>
          </div>
          <?php if (self::isBlock($type)): ?>
            <div class="mc-source">↻ Live aus dem System · <?= e(self::typeLabel($type)) ?></div>
          <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Live-Datenblock einer Bausteinkarte ausgeben. */
    private static function renderBlock(string $type, array $ctx): void
    {
        switch ($type) {
            case 'facts':    self::blockFacts($ctx);    break;
            case 'guests':   self::blockPeople($ctx['guests'], 'Noch keine Ehrengäste hinterlegt (PitchDay-Orga → Gäste & VIPs).'); break;
            case 'jury':     self::blockPeople($ctx['jury'], 'Noch keine Jury übernommen (PitchDay-Orga → Gäste & VIPs).'); break;
            case 'speakers': self::blockSpeakers($ctx); break;
            case 'agenda':   self::blockAgenda($ctx);   break;
            case 'teams':    self::blockTeams($ctx);    break;
            case 'prizes':   self::blockPrizes($ctx);   break;
        }
    }

    /** Hinweis, wenn ein Baustein (noch) keine Daten hat. */
    private static function empty(string $msg): void
    {
        echo '<p class="mc-empty">' . e($msg) . '</p>';
    }

    private static function blockFacts(array $ctx): void
    {
        $facts = $ctx['facts'];
        if (!$facts) {
            self::empty('Noch keine Zahlen verfügbar (Teams, Schüler:innen & Jury pflegen).');
            return;
        }
        echo '<ul class="mc-facts">';
        foreach ($facts as [$label, $value]) {
            echo '<li><span class="mc-facts__n">' . e($value) . '</span><span class="mc-facts__l">' . e($label) . '</span></li>';
        }
        echo '</ul>';
    }

    /**
     * Personenliste (Ehrengäste / Jury) mit Rolle und Vertretungshinweis.
     * @param array<int,array<string,mixed>> $people
     */
    private static function blockPeople(array $people, string $emptyMsg): void
    {
        $people = array_values($people);
        if (!$people) {
            self::empty($emptyMsg);
            return;
        }
        echo '<ul class="mc-people">';
        foreach ($people as $g) {
            $gd  = PitchDay::guestDisplay($g);
            $sub = trim(implode(' · ', array_filter([$gd['position'], $gd['org']])));
            echo '<li><strong>' . e($gd['name']) . '</strong>';
            if ($sub !== '') {
                echo '<span class="mc-role">' . e($sub) . '</span>';
            }
            if ($gd['subline']) {
                echo '<span class="mc-vertritt">↷ ' . e($gd['subline']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    private static function blockSpeakers(array $ctx): void
    {
        $speakers = $ctx['speakers'];
        if (!$speakers) {
            self::empty('Noch keine Grußworte/Keynote hinterlegt (PitchDay-Orga → Gäste & VIPs).');
            return;
        }
        echo '<ol class="mc-people mc-people--num">';
        foreach ($speakers as $g) {
            $gd  = PitchDay::guestDisplay($g);
            $tag = (int) $g['keynote'] === 1 ? 'Keynote' : 'Grußwort';
            $sub = trim(implode(' · ', array_filter([$gd['position'], $gd['org']])));
            echo '<li><span class="mc-tag">' . e($tag) . '</span><strong>' . e($gd['name']) . '</strong>';
            if ($sub !== '') {
                echo '<span class="mc-role">' . e($sub) . '</span>';
            }
            if (!empty($g['greeting_minutes'])) {
                echo '<span class="mc-role">ca. ' . (int) $g['greeting_minutes'] . ' Min</span>';
            }
            if ($gd['subline']) {
                echo '<span class="mc-vertritt">↷ ' . e($gd['subline']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol>';
    }

    private static function blockAgenda(array $ctx): void
    {
        $agenda = $ctx['agenda'];
        if (!$agenda) {
            self::empty('Noch kein Ablaufplan hinterlegt (PitchDay-Orga → Ablaufplan).');
            return;
        }
        $timeFmt = static fn(?string $t) => $t ? substr($t, 0, 5) : '';
        echo '<table class="mc-agenda">';
        foreach ($agenda as $a) {
            $tf = $timeFmt($a['time_from']);
            $tt = $timeFmt($a['time_to']);
            $time = $tf !== '' ? ($tt !== '' ? $tf . '–' . $tt : $tf) : '';
            echo '<tr><td class="mc-agenda__t">' . e($time) . '</td><td class="mc-agenda__x">'
                . e((string) $a['title'])
                . ($a['note'] ? ' <span class="mc-note">(' . e((string) $a['note']) . ')</span>' : '')
                . '</td></tr>';
        }
        echo '</table>';
    }

    private static function blockTeams(array $ctx): void
    {
        $teams    = $ctx['teams'];
        $fallback = $ctx['fallback'];
        if (!$teams && !$fallback) {
            self::empty('Noch keine nominierten Teams (werden in „Bewertung & Ranking“ festgelegt).');
            return;
        }
        if ($teams) {
            echo '<ol class="mc-teams">';
            foreach ($teams as $t) {
                echo '<li><strong>' . e((string) ($t['idea_name'] ?: $t['name'])) . '</strong>';
                echo '<span class="mc-role">' . e((string) ($t['short_name'] ?: $t['school_name'])) . '</span>';
                if (!empty($ctx['members'][(int) $t['id']])) {
                    echo '<div class="mc-members">' . e(implode(', ', $ctx['members'][(int) $t['id']])) . '</div>';
                }
                echo '</li>';
            }
            echo '</ol>';
        }
        if ($fallback) {
            echo '<div class="mc-subgroup">Nachrücker</div><ul class="mc-plain">';
            foreach ($fallback as $t) {
                echo '<li><strong>' . e((string) ($t['idea_name'] ?: $t['name'])) . '</strong>'
                    . '<span class="mc-role">' . e((string) ($t['short_name'] ?: $t['school_name'])) . '</span></li>';
            }
            echo '</ul>';
        }
    }

    private static function blockPrizes(array $ctx): void
    {
        $prizes = $ctx['prizes'];
        if (!$prizes) {
            self::empty('Noch keine Preise hinterlegt (PitchDay-Orga → Budget).');
            return;
        }
        $money = static fn($a) => number_format((float) $a, 2, ',', '.') . ' €';
        echo '<ul class="mc-prizes">';
        foreach ($prizes as $p) {
            echo '<li><strong>' . ($p['place'] ? (int) $p['place'] . '. Platz' : e((string) $p['label'])) . '</strong>';
            if ($p['place']) {
                echo ': ' . e((string) $p['label']);
            }
            if ($p['amount'] !== null) {
                echo '<span class="mc-amount">' . e($money($p['amount'])) . '</span>';
            }
            if ($p['note']) {
                echo '<span class="mc-note">' . e((string) $p['note']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
