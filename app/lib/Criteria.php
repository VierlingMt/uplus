<?php
/**
 * Zentrale Definition der Bewertungskriterien laut Jury-Bewertungsbogen (Formular 06).
 *
 * Businessplan-Phase: 5 Kriterien à 0-10  -> max 50, fliesst 2-fach in die Gesamtwertung
 * Pitch-Phase:        4 Kriterien à 0-10  -> max 40, fliesst 1-fach in die Gesamtwertung
 * Gesamt = 2 * Summe(Businessplan) + 1 * Summe(Pitch)  -> max 140
 */

declare(strict_types=1);

final class Criteria
{
    public const BP_WEIGHT    = 2;
    public const PITCH_WEIGHT = 1;

    /** Businessplan-Kriterien (schriftlicher Plan). */
    public const BUSINESSPLAN = [
        'idea'       => [
            'title'  => 'Geschäftsidee',
            'points' => ['Was wird angeboten (Produkt/Dienstleistung)?',
                         'Welchen Nutzen hat das Angebot für die Kunden?',
                         'Was kann das Team besonders gut?'],
        ],
        'sales'      => [
            'title'  => 'Vertrieb & Wettbewerb',
            'points' => ['Wer sind die Kunden?',
                         'Wie werden Vertrieb und Kommunikation umgesetzt?',
                         'Wurde die Konkurrenz ermittelt und bewertet? Was unterscheidet die Idee?'],
        ],
        'team'       => [
            'title'  => 'Team & Partner',
            'points' => ['Hat das Team die nötigen Fähigkeiten oder weiß, was es noch lernen muss?',
                         'Hat man sich Gedanken zu Werten gemacht?',
                         'Gibt es sinnvolle Partner? Wurden diese ermittelt und bewertet?'],
        ],
        'foundation' => [
            'title'  => 'Unternehmensgründung',
            'points' => ['Wie erfolgt die Produktion bzw. Umsetzung des Angebots?',
                         'Welcher Standort wird gewählt?',
                         'Risikomanagement: Was könnte schiefgehen und was wird dagegen getan?'],
        ],
        'finance'    => [
            'title'  => 'Finanzen & Kosten',
            'points' => ['Ist grob klar, womit Geld verdient wird?',
                         'Wurde über die wichtigsten Kosten nachgedacht?',
                         'Braucht das Team Startkapital?'],
        ],
    ];

    /** Pitch-Day-Kriterien (nur fuer Teams, die pitchen). */
    public const PITCH = [
        'conviction'   => [
            'title'  => 'Überzeugungskraft & Klarheit',
            'points' => ['Wird die Geschäftsidee verständlich und auf den Punkt gebracht?',
                         'Sind die wichtigsten Fakten klar erklärt?'],
        ],
        'presentation' => [
            'title'  => 'Präsentationsstil & Körpersprache',
            'points' => ['Wirkt das Team selbstbewusst und professionell?',
                         'Ist der Vortrag spannend und gut strukturiert?',
                         'Wird Augenkontakt gehalten und frei gesprochen?'],
        ],
        'creativity'   => [
            'title'  => 'Kreativität & Begeisterung',
            'points' => ['Begeistert das Team mit seiner Präsentation?',
                         'Wird die Idee kreativ oder fesselnd vorgestellt?'],
        ],
        'answers'      => [
            'title'  => 'Antworten auf Jury-Fragen',
            'points' => ['Kann das Team kritische Fragen schlüssig beantworten?',
                         'Sind die Antworten kompetent und gut durchdacht?'],
        ],
    ];

    /** Punkteskala laut Bewertungsbogen. */
    public const SCALE = [
        10 => 'Herausragend – professionell durchdacht, keine Verbesserung nötig',
        8  => 'Sehr gut – kleine Details könnten optimiert werden',
        6  => 'Gut – solide Basis, einige Schwächen vorhanden',
        4  => 'Ausbaufähig – wesentliche Punkte fehlen oder sind nicht realistisch',
        1  => 'Schwach – Idee/Konzept nicht durchdacht, große Lücken',
        0  => 'Unbewertbar – keine relevanten Inhalte enthalten',
    ];

    public static function title(string $key): string
    {
        return self::BUSINESSPLAN[$key]['title'] ?? self::PITCH[$key]['title'] ?? $key;
    }

    public static function all(): array
    {
        return self::BUSINESSPLAN + self::PITCH;
    }

    /**
     * Abschnitts-Struktur der Businessplan-Vorlage (für den Struktur-/Mindeststandard-Check).
     * required=false → Abschnitt ist optional (Anhang).
     * @return array<int,array{key:string,title:string,required:bool,aspects:array}>
     */
    public static function templateSections(): array
    {
        $bp = self::BUSINESSPLAN;
        return [
            ['key' => 'summary', 'title' => 'Zusammenfassung', 'required' => true,
             'aspects' => ['Kurzer Gesamtüberblick der Geschäftsidee auf einen Blick']],
            ['key' => 'idea',    'title' => 'Geschäftsidee', 'required' => true, 'aspects' => $bp['idea']['points']],
            ['key' => 'sales',   'title' => 'Vertrieb & Wettbewerb', 'required' => true, 'aspects' => $bp['sales']['points']],
            ['key' => 'team',    'title' => 'Team & Partner', 'required' => true, 'aspects' => $bp['team']['points']],
            ['key' => 'company', 'title' => 'Dein Unternehmen (Umsetzung, Standort, Risiko)', 'required' => true, 'aspects' => $bp['foundation']['points']],
            ['key' => 'finance', 'title' => 'Finanzen & Kosten', 'required' => true, 'aspects' => $bp['finance']['points']],
            ['key' => 'appendix','title' => 'Anhang', 'required' => false, 'aspects' => ['Ergänzende Materialien (optional)']],
        ];
    }

    /** Gesamtpunktzahl nach Gewichtung (max 140). */
    public static function grandTotal(?float $bpTotal, ?float $pitchTotal): float
    {
        return self::BP_WEIGHT * (float) $bpTotal + self::PITCH_WEIGHT * (float) $pitchTotal;
    }
}
