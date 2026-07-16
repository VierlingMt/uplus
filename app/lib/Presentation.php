<?php
/**
 * Projektpräsentation „Unternehmen Plus".
 *
 * Bildet die WJ-Foliensammlung (Businessplanwettbewerb) in der App ab. Die
 * Folien sind zweierlei Art:
 *
 *   1. Dynamische Folien – ziehen ihre Inhalte live aus dem jeweiligen
 *      Wettbewerbsjahr (Zyklus): Titel/Jahr, Projektablauf (Meilensteine),
 *      Preise (PitchDay-Budget), Team/Projektleitung, Kontakt, Sponsoren.
 *      Diese Daten werden dort gepflegt, wo sie ohnehin schon leben
 *      (Wettbewerbsjahre, PitchDay-Orga, Jury & Nutzer, Sponsoren).
 *
 *   2. Textfolien – wiederkehrende Beschreibungstexte (Einleitung, Ablauf­
 *      phasen …). Sie werden in `presentation_slides` gepflegt: je Zyklus
 *      überschreibbar, mit einer globalen Vorlage (cycle_id = NULL) als
 *      Rückfallebene, sodass ein neues Jahr die Texte des Vorjahres erbt.
 *
 * Die eigentliche Folien-HTML entsteht in renderSlide(); Bildschirm-Ansicht
 * (presentation.php) und Druck/PDF (presentation_print.php) teilen sich diese
 * Darstellung, damit beide identisch aussehen.
 */

declare(strict_types=1);

final class Presentation
{
    /**
     * Foliensammlung in Anzeigereihenfolge.
     * type: title | text | timeline | pitchday | team | contact
     *   - „text"-Folien sind in der App pflegbar (presentation_slides).
     *   - „title" ist ebenfalls pflegbar (Untertitel/Autorzeile), zeigt aber das
     *     Wettbewerbsjahr dynamisch.
     * @var array<int,array{key:string,type:string,title:string}>
     */
    public const SLIDES = [
        ['key' => 'title',        'type' => 'title',    'title' => 'Businessplanwettbewerb'],
        ['key' => 'intro',        'type' => 'text',     'title' => 'Mehr Unternehmer braucht das Land'],
        ['key' => 'challenges',   'type' => 'text',     'title' => 'Der Businessplan'],
        ['key' => 'timeline',     'type' => 'timeline', 'title' => 'Projektablauf'],
        ['key' => 'kickoff',      'type' => 'text',     'title' => 'Kick-Off'],
        ['key' => 'teambuilding', 'type' => 'text',     'title' => 'Teambuilding'],
        ['key' => 'ideation',     'type' => 'text',     'title' => 'Ideenfindung'],
        ['key' => 'juryfeedback', 'type' => 'text',     'title' => 'Juryfeedback'],
        ['key' => 'businessplan', 'type' => 'text',     'title' => 'Businessplan erstellen'],
        ['key' => 'submission',   'type' => 'text',     'title' => 'Einsendeschluss'],
        ['key' => 'pitchday',     'type' => 'pitchday', 'title' => 'Pitch Day'],
        ['key' => 'closing',      'type' => 'text',     'title' => 'Project Closing'],
        ['key' => 'team',         'type' => 'team',     'title' => 'Unser Team'],
    ];

    /** Folientypen, deren Text in der App gepflegt wird (Titel-/Textteil der Folie). */
    private const EDITABLE_TYPES = ['title', 'text', 'pitchday'];

    /**
     * Vorbelegte Texte (globale Vorlage, cycle_id = NULL). Aus der ursprünglichen
     * WJ-Präsentation übernommen. body ist schlankes Markdown (siehe
     * render_markdown()): „- " für Aufzählungen, **fett**, [Text](URL).
     * @var array<string,array{title:string,subtitle:string,body:string}>
     */
    public const SEED = [
        'title' => [
            'title'    => 'Businessplanwettbewerb',
            'subtitle' => 'Ein Projekt der Wirtschaftsjunioren im Rahmen des „LehrplanPLUS" für bayerische Gymnasien.',
            'body'     => "Autor: Martin Vierling · martin.vierling@vierling.de",
        ],
        'intro' => [
            'title'    => 'Mehr Unternehmer braucht das Land',
            'subtitle' => 'Einleitung',
            'body'     => "Unternehmertum und Selbstständigkeit werden in den deutschen Ausbildungsstätten nur "
                . "unterdurchschnittlich repräsentiert. Mit dem „LehrplanPLUS“ der bayerischen Gymnasien sollen "
                . "Schüler auch im Bereich Unternehmertum wertvolle Inhalte vermittelt bekommen. Der Praxisbezug "
                . "zur Theorie kann in der Schule jedoch nicht in vollem Maße vermittelt werden.\n\n"
                . "Mit dem Businessplanwettbewerb „Unternehmen Plus“ haben die Wirtschaftsjunioren Forchheim ein "
                . "realitätsnahes Format entwickelt, das gymnasialen Schülerinnen und Schülern der 10. Klassen die "
                . "Möglichkeit gibt, ihr Wissen zu Selbstständigkeit und Unternehmensgründung zu vertiefen und "
                . "spielerisch wichtige Erfahrungen zu sammeln. Neben Tipps und Tricks der Jury werden der "
                . "schriftliche Businessplan sowie der vorgetragene Pitch mit tollen Preisen prämiert.",
        ],
        'challenges' => [
            'title'    => 'Der Businessplan',
            'subtitle' => 'Herausforderungen',
            'body'     => "**Der Businessplan** – Ein strukturierter Businessplan befasst sich mit sämtlichen "
                . "Aspekten einer Unternehmung: von der Idee über Markt, Vertrieb, Einnahmen und Ausgaben bis zu "
                . "Finanzierung und Unternehmensform.\n\n"
                . "**Teamwork** – Eine gute Unternehmung ist nur so gut wie ihre Protagonisten. Wichtig ist "
                . "herauszufinden, „welcher Typ“ man ist und mit welchem Team man erfolgreich einen Businessplan "
                . "entwerfen kann.\n\n"
                . "**Mentoren** – Wer in jungen Jahren gründen möchte, weiß vieles noch nicht. Erfahrene Mentoren "
                . "helfen dabei, worauf es bei der Geschäftsidee und möglichen Investoren ankommt.",
        ],
        'kickoff' => [
            'title'    => 'Kick-Off',
            'subtitle' => 'Großes beginnt oft klein',
            'body'     => "Treffen der verantwortlichen Lehrkräfte mit der WJ-Projektleitung – schulübergreifend, "
                . "für Austausch und Abstimmung zwischen den teilnehmenden Schulen.\n\n"
                . "- Wer sind die Ansprechpartner?\n"
                . "- Welche Klassen und wie viele Schüler:innen nehmen teil?\n"
                . "- Termine abstimmen und Fragen zum Ablauf klären\n"
                . "- Sponsoren und Preise vorstellen\n"
                . "- Beantragung des Innovationsfonds für Bildung der Region Forchheim",
        ],
        'teambuilding' => [
            'title'    => 'Teambuilding',
            'subtitle' => 'Nur im Team ist man stark',
            'body'     => "- Mit einem kleinen Persönlichkeitstest ermitteln die Schüler:innen ihre Stärken und "
                . "Schwächen (Vorlage der WJ Forchheim).\n"
                . "- Jeder Persönlichkeitstyp entspricht einer Farbe.\n"
                . "- Es werden Teams mit je etwa 5 Teilnehmer:innen zusammengestellt.\n"
                . "- Jedes Team sollte jede Charaktereigenschaft/Farbe mindestens einmal enthalten.",
        ],
        'ideation' => [
            'title'    => 'Ideenfindung',
            'subtitle' => 'Jedes gute Unternehmen startet mit einer guten Idee',
            'body'     => "Die Schüler:innen machen sich auf die Suche nach Geschäftsideen – aus vorhandenen Ideen "
                . "oder erarbeitet im Familien- und Freundeskreis sowie in der Schule.\n\n"
                . "Sie werden ermutigt, ihre Interessen und Leidenschaften einzubringen, um authentische und "
                . "praktisch umsetzbare Projekte zu gestalten. Die Lehrkräfte fördern eine selbstständige "
                . "Herangehensweise. Als Orientierung dient eine Vorlage der Wirtschaftsjunioren.",
        ],
        'juryfeedback' => [
            'title'    => 'Juryfeedback',
            'subtitle' => 'Wichtiges Feedback für die Geschäftsidee',
            'body'     => "- Die Teams stellen ihre Geschäftsidee der Jury vor.\n"
                . "- Die Jury gibt Feedback zu Machbarkeit und fehlenden Aspekten und Tipps für den Businessplan.\n"
                . "- Jedes Team erhält ein Kurzfeedback und notiert es auf der Vorlage.\n"
                . "- Alle erhalten eine Liste der Juroren mit Kontaktdaten und „Spezialgebieten“.\n"
                . "- Zeitdauer je Team: 15 Minuten.",
        ],
        'businessplan' => [
            'title'    => 'Businessplan erstellen',
            'subtitle' => 'Der Businessplan entsteht',
            'body'     => "Mit dem Juryfeedback machen sich die Schüler:innen an die Ausarbeitung des Businessplans. "
                . "Er sollte einheitlich anhand der Vorlage erstellt werden. Die Lehrkräfte stehen für Rückfragen "
                . "zur Seite; bei Bedarf können die Wirtschaftsjunioren hinzugezogen werden.\n\n"
                . "**Generative KI** (z. B. ChatGPT) ist ausdrücklich erlaubt. Bitte beachten:\n"
                . "- „Shit in = shit out“: Mühe in den Prompt stecken – nur mit guten Eingaben entstehen gute Ergebnisse.\n"
                . "- Auch KI macht Fehler! Jedes Ergebnis prüfen und in den eigenen Stil umformulieren.\n"
                . "- Kennzeichnungspflicht: Von der KI generierte Texte müssen als solche gekennzeichnet werden.",
        ],
        'submission' => [
            'title'    => 'Einsendeschluss',
            'subtitle' => 'Der fertige Businessplan steht',
            'body'     => "- Die Schüler:innen reichen ihre fertigen Businesspläne bei den Lehrkräften ein, die "
                . "vor- und aussortieren können.\n"
                . "- Die Jury sichtet die zugesandten Businesspläne und bewertet sie anhand eines strukturierten "
                . "Bewertungsbogens (Vorlage verfügbar).\n"
                . "- Die besten Businesspläne werden ermittelt.\n"
                . "- Die Teams erfahren erst am Pitch Day, ob sie aufgerufen werden – die Spannung bleibt bis zuletzt.",
        ],
        'pitchday' => [
            'title'    => 'Pitch Day',
            'subtitle' => 'Jetzt wird es ernst',
            'body'     => "Am Pitch Day entscheidet sich, wer seine Geschäftsidee vor allen vorstellen darf. Die "
                . "Moderation ruft die von der Jury nominierten Teams auf. Jedes Team hat 3 Minuten Zeit zu "
                . "pitchen – Präsentationsobjekte dürfen mitgebracht werden. Anschließend stellt die Jury ca. "
                . "5 Minuten Fragen und gibt Tipps.\n\n"
                . "Sind alle Pitches absolviert, zieht sich die Jury zur Bewertung zurück. Danach folgt die "
                . "Prämierung der besten Teams inkl. Übergabe der Preise. Es erfolgt eine intensive "
                . "Nachberichterstattung mit Pressemitteilung und Social-Media-Posts.",
        ],
        'closing' => [
            'title'    => 'Project Closing',
            'subtitle' => 'Feedback und Würdigung',
            'body'     => "Etwa eine Woche nach dem Pitch Day treffen sich alle verantwortlichen Lehrkräfte mit der "
                . "Projektleitung der Wirtschaftsjunioren zu einer Feedbackrunde:\n\n"
                . "- Was lief gut, was lief schlecht?\n"
                . "- Anregungen und Kritik\n"
                . "- Klärung der grundsätzlichen Teilnahme für das nächste Jahr",
        ],
    ];

    /** Ist der Folientyp in der App pflegbar (Textfolie)? */
    public static function isEditable(string $type): bool
    {
        return in_array($type, self::EDITABLE_TYPES, true);
    }

    /** Definition einer Folie über ihren Schlüssel. */
    public static function slideDef(string $key): ?array
    {
        foreach (self::SLIDES as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }
        return null;
    }

    /**
     * Text einer Folie mit Rückfall: zyklus-spezifisch → globale Vorlage
     * (cycle_id NULL) → fest hinterlegter SEED. Liefert stets title/subtitle/body.
     */
    public static function text(string $key, int $cycleId): array
    {
        $row = Database::one(
            'SELECT title, subtitle, body FROM presentation_slides WHERE slide_key = ? AND cycle_id = ?',
            [$key, $cycleId]
        );
        if (!$row) {
            $row = Database::one(
                'SELECT title, subtitle, body FROM presentation_slides WHERE slide_key = ? AND cycle_id IS NULL',
                [$key]
            );
        }
        $seed = self::SEED[$key] ?? ['title' => '', 'subtitle' => '', 'body' => ''];
        return [
            'title'    => (string) ($row['title'] ?? $seed['title']),
            'subtitle' => (string) ($row['subtitle'] ?? $seed['subtitle']),
            'body'     => (string) ($row['body'] ?? $seed['body']),
            // Gibt es für genau diesen Zyklus einen eigenen (überschreibenden) Datensatz?
            'is_override' => (bool) Database::value(
                'SELECT 1 FROM presentation_slides WHERE slide_key = ? AND cycle_id = ?',
                [$key, $cycleId]
            ),
        ];
    }

    /** Text einer Folie speichern (Upsert je Zyklus). */
    public static function saveText(string $key, int $cycleId, string $title, string $subtitle, string $body): void
    {
        Database::run(
            'INSERT INTO presentation_slides (cycle_id, slide_key, title, subtitle, body, updated_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), subtitle = VALUES(subtitle),
                                     body = VALUES(body), updated_at = NOW()',
            [$cycleId, $key, $title, $subtitle, $body]
        );
    }

    /** Zyklus-Override einer Folie entfernen (fällt zurück auf die globale Vorlage). */
    public static function resetText(string $key, int $cycleId): void
    {
        Database::run(
            'DELETE FROM presentation_slides WHERE slide_key = ? AND cycle_id = ?',
            [$key, $cycleId]
        );
    }

    /**
     * Sämtliche Daten für die dynamischen Folien eines Zyklus einsammeln.
     * @return array<string,mixed>
     */
    public static function context(int $cycleId): array
    {
        $cycle = Cycle::find($cycleId);
        $ctx = [
            'cycle_id'   => $cycleId,
            'year_label' => (string) ($cycle['year_label'] ?? ''),
            'timeline'   => $cycleId ? Cycle::milestoneTimeline($cycleId) : [],
            'prizes'     => [],
            'leads'      => [],
            'sponsors'   => [],
            'event'      => null,
        ];

        // Preise aus dem PitchDay-Budget (kind = prize).
        $event = $cycleId ? PitchDay::eventForCycle($cycleId) : null;
        $ctx['event'] = $event;
        if ($event) {
            $ctx['prizes'] = Database::all(
                "SELECT label, amount, place, note FROM event_budget_items
                 WHERE event_id = ? AND kind = 'prize'
                 ORDER BY place IS NULL, place, sort_order, id",
                [(int) $event['id']]
            );
        }

        // Projektleitung (Team + Kontakt) – wie im Menüpunkt „Kontakt".
        $ctx['leads'] = Database::all(
            'SELECT name, email, phone, specialty, org, position, photo_path FROM users u
             WHERE u.is_active = 1
               AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role = "lead")
             ORDER BY name'
        );

        // Sponsoren des Jahres (mit Logo).
        if ($cycleId) {
            $ctx['sponsors'] = Database::all(
                'SELECT s.name, s.logo_path FROM sponsor_contributions c JOIN sponsors s ON s.id = c.sponsor_id
                 WHERE c.cycle_id = ? GROUP BY s.id, s.name, s.logo_path ORDER BY s.name',
                [$cycleId]
            );
        }

        return $ctx;
    }

    /** Geldbetrag deutsch formatieren. */
    private static function money($a): string
    {
        return number_format((float) $a, 2, ',', '.') . ' €';
    }

    /**
     * Inneres HTML einer Folie erzeugen (ohne Folienrahmen). Screen- und
     * Druckansicht nutzen dieselbe Ausgabe.
     *
     * @param array $def  Folien-Definition (aus SLIDES)
     * @param array $ctx  Kontext aus context()
     * @param array $text Text aus text() (für Textfolien/Titel)
     */
    public static function renderSlide(array $def, array $ctx, array $text): string
    {
        $type = $def['type'];
        ob_start();
        switch ($type) {
            case 'title':    self::renderTitle($ctx, $text); break;
            case 'timeline': self::renderTimeline($ctx); break;
            case 'pitchday': self::renderPitchday($ctx, $text); break;
            case 'team':     self::renderTeam($ctx); break;
            case 'text':
            default:         self::renderText($text); break;
        }
        return (string) ob_get_clean();
    }

    /** Kicker-Zeile (WJ Forchheim) – oben auf jeder Folie. */
    private static function kicker(?string $year = null): string
    {
        $y = $year !== null && $year !== '' ? ' · ' . e($year) : '';
        return '<div class="ps-kicker">Wirtschaftsjunioren Forchheim · Unternehmen&nbsp;Plus' . $y . '</div>';
    }

    private static function renderTitle(array $ctx, array $text): void
    {
        $year = $ctx['year_label'];
        $socials = Social::links();
        ?>
        <div class="ps-slide__inner ps-title">
          <?php // WJ-CI (Design-Guide S. 13): Wort-Bildmarke oben links, Projektlogo oben rechts. ?>
          <div class="ps-title__logos">
            <img class="ps-title__wj" src="<?= asset('img/wj/wj-forchheim-color.svg') ?>" alt="Wirtschaftsjunioren Forchheim">
            <img class="ps-title__logo" src="<?= asset('img/logo.svg') ?>" alt="Unternehmen Plus">
          </div>
          <div class="ps-title__hero">
            <?php if ($year !== ''): ?><div class="ps-title__year"><?= e($year) ?></div><?php endif; ?>
            <h1 class="ps-title__h"><?= e($text['title']) ?></h1>
            <div class="ps-title__brand">Unternehmen&nbsp;Plus</div>
            <?php if ($text['subtitle'] !== ''): ?><p class="ps-title__sub"><?= e($text['subtitle']) ?></p><?php endif; ?>
            <?php if (trim($text['body']) !== ''): ?><div class="ps-title__meta"><?= render_markdown($text['body']) ?></div><?php endif; ?>
            <?php if ($socials): ?>
              <div class="ps-social">
                <span class="ps-social__lead">Folgt uns:</span>
                <?php foreach ($socials as $s): ?>
                  <a class="ps-social__link" href="<?= e($s['url']) ?>" target="_blank" rel="noopener">
                    <span class="ps-social__ic" aria-hidden="true"><?= $s['icon'] ?></span><?= e($s['label']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($ctx['sponsors'])): ?>
            <div class="ps-sponsors ps-sponsors--title">
              <div class="ps-sponsors__head">Mit Unterstützung von</div>
              <div class="ps-sponsors__row">
                <?php foreach ($ctx['sponsors'] as $s): ?>
                  <?php if (!empty($s['logo_path'])): ?>
                    <img src="<?= asset($s['logo_path']) ?>" alt="<?= e($s['name']) ?>">
                  <?php else: ?>
                    <span class="ps-sponsors__name"><?= e($s['name']) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function renderText(array $text): void
    {
        ?>
        <div class="ps-slide__inner">
          <?= self::kicker() ?>
          <h2 class="ps-h"><?= e($text['title']) ?></h2>
          <?php if ($text['subtitle'] !== ''): ?><div class="ps-sub"><?= e($text['subtitle']) ?></div><?php endif; ?>
          <div class="ps-body"><?= render_markdown($text['body']) ?></div>
        </div>
        <?php
    }

    private static function renderTimeline(array $ctx): void
    {
        $tl = $ctx['timeline'];
        ?>
        <div class="ps-slide__inner">
          <?= self::kicker($ctx['year_label']) ?>
          <h2 class="ps-h">Projektablauf<?= $ctx['year_label'] !== '' ? ' ' . e($ctx['year_label']) : '' ?></h2>
          <?php if ($tl): ?>
            <ol class="ps-timeline">
              <?php foreach ($tl as [$phase, $date, $state]): ?>
                <li class="ps-tl ps-tl--<?= e($state) ?>">
                  <span class="ps-tl__dot"><?= $state === 'done' ? '✓' : '' ?></span>
                  <span class="ps-tl__phase"><?= e($phase) ?></span>
                  <?php if ($date !== ''): ?><span class="ps-tl__date"><?= e($date) ?></span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <p class="ps-muted">Für dieses Wettbewerbsjahr sind noch keine Meilensteine hinterlegt
              (unter „Wettbewerbsjahre" pflegbar).</p>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function renderPitchday(array $ctx, array $text): void
    {
        $prizes = $ctx['prizes'];
        ?>
        <div class="ps-slide__inner">
          <?= self::kicker($ctx['year_label']) ?>
          <h2 class="ps-h"><?= e($text['title']) ?></h2>
          <?php if ($text['subtitle'] !== ''): ?><div class="ps-sub"><?= e($text['subtitle']) ?></div><?php endif; ?>
          <div class="ps-split">
            <div class="ps-body"><?= render_markdown($text['body']) ?></div>
            <?php if ($prizes): ?>
              <div class="ps-prizes">
                <div class="ps-prizes__head">Preise<?= $ctx['year_label'] !== '' ? ' ' . e($ctx['year_label']) : '' ?></div>
                <ul>
                  <?php foreach ($prizes as $p): ?>
                    <li>
                      <strong><?= $p['place'] ? (int) $p['place'] . '. Platz' : e($p['label']) ?></strong><?= $p['place'] ? ': ' . e($p['label']) : '' ?>
                      <?php if ($p['amount'] !== null): ?><span class="ps-amount"><?= e(self::money($p['amount'])) ?></span><?php endif; ?>
                      <?php if ($p['note']): ?><span class="ps-note"><?= e($p['note']) ?></span><?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php
    }

    private static function renderTeam(array $ctx): void
    {
        $leads = $ctx['leads'];
        ?>
        <div class="ps-slide__inner">
          <?= self::kicker($ctx['year_label']) ?>
          <h2 class="ps-h">Unser Team</h2>
          <div class="ps-sub">Wirtschaftsjunioren Forchheim · Ressort Bildung – enger Kontakt zu den Schulen und Projektmanagement.</div>
          <?php if ($leads): ?>
            <div class="ps-people">
              <?php foreach ($leads as $l): $sub = trim((string) ($l['position'] ?: $l['specialty'])); ?>
                <div class="ps-person">
                  <?php if (!empty($l['photo_path'])): ?>
                    <img class="ps-person__ph" src="<?= asset($l['photo_path']) ?>" alt="">
                  <?php else: ?>
                    <span class="ps-person__ph ps-person__ph--ph"><?= e(mb_strtoupper(mb_substr((string) $l['name'], 0, 1))) ?></span>
                  <?php endif; ?>
                  <div class="ps-person__name"><?= e($l['name']) ?></div>
                  <?php if ($sub !== ''): ?><div class="ps-person__role"><?= e($sub) ?></div><?php endif; ?>
                  <?php if (!empty($l['org'])): ?><div class="ps-person__org"><?= e($l['org']) ?></div><?php endif; ?>
                  <div class="ps-person__contact">
                    <?php if (!empty($l['phone'])): ?><span>☎ <?= e($l['phone']) ?></span><?php endif; ?>
                    <?php if (!empty($l['email'])): ?><span>✉ <a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a></span><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="ps-muted">Noch keine Projektleitung hinterlegt (unter „Jury &amp; Nutzer" pflegbar).</p>
          <?php endif; ?>
        </div>
        <?php
    }
}
