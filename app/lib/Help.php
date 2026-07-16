<?php
/**
 * Interaktive Hilfe & geführte Tour – Inhalte und Kontext.
 *
 * WICHTIGE GRUNDLAGE (Vorgabe Martin Vierling):
 *   Hilfe UND Tour müssen sich bei Änderungen an der App IMMER automatisch
 *   mit aktualisieren. Deshalb sind beide bewusst *datengetrieben* und nicht als
 *   separate, von Hand gepflegte Kopie der Oberfläche angelegt:
 *
 *   1. Die TOUR wird zur Laufzeit im Browser aus dem DOM erzeugt (help.js). Jeder
 *      Baustein trägt seine Erklärung über `data-tour` bei sich (siehe
 *      helpers.php: tour_attrs()). Ändert sich eine Seite – neue Karte, neuer
 *      Bereich, andere Rolle –, ändert sich die Tour automatisch mit. Zusätzlich
 *      erkennt help.js sichtbare Bereiche (Karten-Überschriften) selbständig als
 *      Schritte, auch ohne Markierung.
 *
 *   2. Die KONTEXT-HILFE bezieht Routen-Liste und -Beschriftung aus der echten
 *      Navigation (_layout.php) und ergänzt live aus dem DOM, was auf der Seite
 *      gerade sichtbar ist. Neue Module tauchen also von allein auf.
 *
 *   3. Die hier hinterlegten Prosa-Texte sind eine *Ergänzung* (das „Warum" und
 *      die Zusammenhänge). Fehlt zu einer Route ein Text, greift automatisch der
 *      generierte Inhalt – die Hilfe bleibt also nie leer und nie „veraltet".
 *
 * Wer die Oberfläche erweitert, muss hier im Normalfall nichts nachtragen. Nur
 * wenn ein neuer Bereich eine erklärende Prosa verdient, kommt ein Eintrag in
 * ROUTE_HELP dazu.
 */

declare(strict_types=1);

final class Help
{
    /**
     * Übergreifende Themen – überall durchsuchbar, unabhängig von der Route.
     * Reihenfolge = Anzeigereihenfolge im „Allgemein"-Block.
     *
     * @var array<int, array{title:string, body:string, tags?:string}>
     */
    private const COMMON = [
        [
            'title' => 'Anmeldung ohne Passwort (Magic-Link)',
            'body'  => "Unternehmen Plus hat **kein Passwort**. Du meldest dich an, indem du deine "
                     . "E-Mail-Adresse einträgst und auf den zugeschickten **Anmelde-Link** klickst. "
                     . "Der Link ist nur kurze Zeit gültig und funktioniert einmalig.\n\n"
                     . "- Kein Link angekommen? Zuerst den Spam-Ordner prüfen, dann erneut anfordern.\n"
                     . "- Auf einem Gerät kannst du zusätzlich einen **Passkey** (Fingerabdruck/Gesichts-"
                     . "erkennung) einrichten – dann geht es beim nächsten Mal noch schneller.",
            'tags'  => 'login anmelden passwort magic link passkey e-mail email',
        ],
        [
            'title' => 'Rollen & Berechtigungen',
            'body'  => "Wer was sieht, hängt an der **Rolle**:\n\n"
                     . "- **Admin** – dauerhafter Eigentümer, darf alles.\n"
                     . "- **Projektleitung** – volle Verwaltung des laufenden Wettbewerbsjahres.\n"
                     . "- **Lehrkraft** – betreut die Teams der eigenen Schule.\n"
                     . "- **Jury** – bewertet Businesspläne und Pitches.\n\n"
                     . "Mehrfachrollen sind möglich (z. B. Jury **und** Projektleitung). Menüpunkte, "
                     . "die deine Rolle nicht sehen darf, erscheinen gar nicht erst.",
            'tags'  => 'rolle rollen rechte berechtigung admin lead projektleitung lehrkraft jury juror teacher',
        ],
        [
            'title' => 'Navigation & Menü',
            'body'  => "Links liegt die **Seitenleiste** mit allen Bereichen, nach Themen gruppiert "
                     . "(Für alle · Lehrkraft · Jury · Verwaltung). \n\n"
                     . "- Über den **Menü-Knopf** oben links klappst du die Leiste schmal (nur Symbole) "
                     . "bzw. auf dem Handy als Menü auf und zu.\n"
                     . "- Ganz unten stehen das aktive **Wettbewerbsjahr** und die **Version** (öffnet den "
                     . "Änderungsverlauf).",
            'tags'  => 'navigation menü menu seitenleiste sidebar bereiche',
        ],
        [
            'title' => 'Listen durchsuchen & sortieren',
            'body'  => "Größere Tabellen haben oben ein **Suchfeld**. Es sucht in **allen Spalten** und "
                     . "versteht **mehrere Begriffe** gleichzeitig – z. B. `gfs eingereicht` zeigt nur "
                     . "Einträge, die beide Wörter enthalten.\n\n"
                     . "- Ein Klick auf eine **Spaltenüberschrift** sortiert; ein zweiter Klick dreht die "
                     . "Richtung um. Datums- und Zahlenwerte werden korrekt (nicht als Text) sortiert.",
            'tags'  => 'suche suchen filter sortieren tabelle liste spalte',
        ],
        [
            'title' => 'Hilfe & geführte Tour',
            'body'  => "Diese Hilfe öffnest du jederzeit mit **F1** oder über das **?**-Symbol oben rechts. "
                     . "Sie zeigt immer zuerst die Themen zur **aktuellen Seite**; das Suchfeld findet "
                     . "über alle Themen hinweg und **hebt die Fundstellen hervor**.\n\n"
                     . "Mit **Tour starten** wirst du Schritt für Schritt durch die **gerade sichtbaren** "
                     . "Inhalte der Seite geführt und bekommst jeden Bereich erklärt. Weiter/Zurück per "
                     . "Knopf oder Pfeiltasten, Beenden mit Esc.\n\n"
                     . "Hilfe und Tour halten sich **automatisch** an die App: Was auf der Seite steht, "
                     . "wird erklärt – auch nach Änderungen.",
            'tags'  => 'hilfe help tour f1 suche onboarding einführung erklärung',
        ],
        [
            'title' => '„Ansehen als" (nur Admin)',
            'body'  => "Ein Admin kann über **„Ansehen als“** (bei Jury & Nutzer) die App aus der Sicht "
                     . "einer anderen Person betrachten – als **Nur-Lese-Ansicht**. Schreibende Aktionen "
                     . "sind dabei gesperrt. Ein gelber Balken oben zeigt die aktive Sicht; über **Sicht "
                     . "beenden** kehrst du zu deinem eigenen Konto zurück.",
            'tags'  => 'ansehen als impersonate nur lesen admin sicht',
        ],
    ];

    /**
     * Prosa-Erklärungen je Route (das „Warum" und die Zusammenhänge). Optional –
     * fehlt eine Route, erzeugt help.js aus Navigation und Seiteninhalt selbst
     * einen sinnvollen Kontext. Jeder Eintrag ist eine Liste von Themen.
     *
     * @var array<string, array<int, array{title:string, body:string}>>
     */
    private const ROUTE_HELP = [
        'dashboard' => [
            [
                'title' => 'Das Dashboard',
                'body'  => "Dein Startpunkt: Die **Kennzahl-Kacheln** oben fassen Schulen, Teams, "
                         . "eingereichte Pläne und Jury zusammen – ein Klick führt direkt in den "
                         . "jeweiligen Bereich (sofern deine Rolle ihn sehen darf).\n\n"
                         . "Der **Projektablauf** zeigt die Meilensteine des Wettbewerbsjahres; erledigte "
                         . "Schritte sind abgehakt. Darunter erscheinen – je nach Stand – der **PitchDay** "
                         . "und die **Partner & Sponsoren**.",
            ],
        ],
        'plans' => [
            [
                'title' => 'Businesspläne',
                'body'  => "Hier liegen die **Businessplan-PDFs** der Teams. Ein Klick öffnet die "
                         . "**Vorschau**. Lehrkräfte laden die Pläne der **eigenen Schule** hoch, die "
                         . "Verwaltung für alle.\n\n"
                         . "Die Verwaltung kann zusätzlich die **Struktur-Prüfung** und die "
                         . "**KI-Vorbewertung** stapelweise laufen lassen – ein Fortschrittsbalken zeigt "
                         . "den Verlauf. Diese Vorbewertung ist eine Hilfe, **kein** Ersatz für die "
                         . "Jury-Bewertung.",
            ],
        ],
        'materials' => [
            [
                'title' => 'Material & Vorlagen',
                'body'  => "Gesammelte **Vorlagen und Dokumente** (z. B. Businessplan-Vorlage, "
                         . "Bewertungsbögen, Leitfäden) zum Herunterladen. Die Verwaltung pflegt die "
                         . "Liste und kann Dateien ergänzen oder entfernen.",
            ],
        ],
        'teams' => [
            [
                'title' => 'Teams & Schüler',
                'body'  => "Verwalte die **Teams** und ihre **Mitglieder**. Jedes Team gehört zu einer "
                         . "Schule und hat einen Status (In Arbeit, Eingereicht, Pitch nominiert, "
                         . "Nachrücker, Ausgeschieden). Lehrkräfte sehen und pflegen die Teams ihrer "
                         . "eigenen Schule.",
            ],
        ],
        'ranking' => [
            [
                'title' => 'Bewertung & Ranking',
                'body'  => "Die **Rangliste** aller Teams nach Gesamtpunkten (Businessplan + Pitch). "
                         . "Jury-Mitglieder öffnen von hier ihre **Bewertung** je Team.\n\n"
                         . "Die Verwaltung sieht den **Bewertungsstand** (wer hat schon bewertet) und "
                         . "kann die **Pitch-Teilnehmer nominieren** – automatisch nach Punkten (mit "
                         . "fairer Verteilung je Schule) oder von Hand.",
            ],
        ],
        'jury_feedback' => [
            [
                'title' => 'Jury-Feedback',
                'body'  => "Gebündeltes, schriftliches **Feedback der Jury** an die Teams – als "
                         . "Rückmeldung zum Businessplan und/oder Pitch. So bekommt jedes Team eine "
                         . "nachvollziehbare Begründung.",
            ],
        ],
        'pitch' => [
            [
                'title' => 'PitchDay (Bewertung)',
                'body'  => "Am Wettbewerbstag bewertet die Jury die **Live-Pitches** der nominierten "
                         . "Teams. Oben siehst du, **wie viele Bühnen-Teams du selbst noch bewerten "
                         . "musst**; offene sind in der Liste links markiert. Eingaben werden **automatisch "
                         . "gespeichert**. „Pitch bewertet“ gilt erst, wenn alle Pitch-Kriterien Punkte "
                         . "haben.",
            ],
        ],
        'cycles' => [
            [
                'title' => 'Wettbewerbsjahre',
                'body'  => "Lege **Wettbewerbsjahre** an und pflege ihre **Meilensteine** (mit Datum oder "
                         . "Zeitraum) – daraus speist sich der Projektablauf auf dem Dashboard. Genau ein "
                         . "Jahr ist **aktiv**; auf dieses beziehen sich alle anderen Bereiche.",
            ],
        ],
        'event' => [
            [
                'title' => 'PitchDay-Orga',
                'body'  => "Die **Organisation des PitchDay**: Aufgaben mit Fälligkeit, Gäste-/Zusagen-"
                         . "Verwaltung, Aushänge, Urkunden und Ablaufplan. Statusänderungen werden ohne "
                         . "Seiten-Neuladen gespeichert, damit deine Position in der Liste erhalten bleibt.",
            ],
        ],
        'schools' => [
            [
                'title' => 'Schulen',
                'body'  => "Die **teilnehmenden Schulen** mit Kürzel, Logo und Ansprechpartnern. Teams "
                         . "und Lehrkräfte hängen an einer Schule.",
            ],
        ],
        'jurors' => [
            [
                'title' => 'Jury & Nutzer',
                'body'  => "Alle **Personen** mit Zugang: Rollen zuweisen, einladen, aktiv/inaktiv "
                         . "setzen. Von hier startet ein Admin auch die Ansicht **„Ansehen als“**.",
            ],
        ],
        'sponsors' => [
            [
                'title' => 'Sponsoren',
                'body'  => "Die **Partner & Sponsoren** des Wettbewerbs mit Logo und Leistung. Sie "
                         . "erscheinen u. a. auf dem Dashboard, in Aushängen und auf Urkunden.",
            ],
        ],
        'audit' => [
            [
                'title' => 'Audit-Log',
                'body'  => "Ein **nachvollziehbares Protokoll** wichtiger Aktionen (wer hat wann was "
                         . "geändert). Hilft bei Rückfragen und Transparenz.",
            ],
        ],
        'admin' => [
            [
                'title' => 'Admin-Einstellungen',
                'body'  => "Zentrale **Einstellungen**: Bewertungskriterien und -gewichte, Pitch-Plätze "
                         . "und Nachrücker, Freigaben (z. B. KI-Vorbewertung für die Jury) und weitere "
                         . "Grundeinstellungen der App.",
            ],
        ],
        'access' => [
            [
                'title' => 'Zugriffsmatrix',
                'body'  => "Feineinstellung der **Modul-Rechte je Rolle** (Lesen/Schreiben) – nur für den "
                         . "Admin. Damit lässt sich pro Bereich steuern, wer ihn sehen bzw. bearbeiten "
                         . "darf.",
            ],
        ],
        'profile' => [
            [
                'title' => 'Mein Profil',
                'body'  => "Deine **eigenen Daten**: Name, Foto, Kontaktangaben und – falls gewünscht – "
                         . "ein **Passkey** für die schnelle Anmeldung. Änderungen an der E-Mail werden "
                         . "zur Sicherheit per Bestätigungslink abgesichert.",
            ],
        ],
        'presentation' => [
            [
                'title' => 'Präsentation',
                'body'  => "Die **Foliensammlung** zum Wettbewerb – in der App ansehen oder als **PDF** "
                         . "exportieren, z. B. für Info-Veranstaltungen.",
            ],
        ],
        'moderation' => [
            [
                'title' => 'Moderationskärtchen',
                'body'  => "Die **Moderationskärtchen** für den Pitch Day (DIN A5 quer) – am Rednerpult "
                         . "digital durchblättern (auch im **Vollbild**) oder als **PDF** drucken. "
                         . "**Freie Textkarten** legst du selbst an; **Bausteinkarten** (Ehrengäste, "
                         . "Grußworte, Jury, Ablauf, Teams, Preise, Zahlen) füllen sich automatisch aus "
                         . "dem Wettbewerbsjahr. „Aus Vorlage erstellen“ spielt den kompletten Ablauf ein.",
            ],
        ],
        'contact' => [
            [
                'title' => 'Kontakt',
                'body'  => "Ansprechpartner und **Kontaktmöglichkeiten** rund um den Wettbewerb.",
            ],
        ],
        'changelog' => [
            [
                'title' => 'Änderungsverlauf',
                'body'  => "Was sich in welcher **Version** geändert hat – neue Funktionen, "
                         . "Verbesserungen und behobene Fehler.",
            ],
        ],
    ];

    /**
     * Kurzer Willkommenstext für den Start der Tour.
     */
    public const TOUR_INTRO = 'Diese kurze Tour führt dich durch die Bereiche, die auf dieser Seite '
        . 'gerade sichtbar sind, und erklärt sie. Mit „Weiter" geht es voran – jederzeit mit Esc beenden.';

    /**
     * Alle Themen als einheitliche Liste – die einzige Datenquelle für das
     * Hilfe-Panel (help.js filtert daraus die aktuelle Route heraus und
     * durchsucht bei Bedarf alle). Jedes Thema trägt seine Route (leer =
     * übergreifend) und ein lesbares Label des Fundorts.
     *
     * @param array<string, string> $routeLabels  Route => Anzeigename (aus der Navigation)
     * @return array<int, array{title:string, html:string, text:string, route:string, source:string}>
     */
    public static function all(array $routeLabels = []): array
    {
        $out = [];
        foreach (self::ROUTE_HELP as $route => $topics) {
            $label = $routeLabels[$route] ?? ucfirst($route);
            foreach ($topics as $t) {
                $out[] = self::topic($t['title'], $t['body'], $route, $label);
            }
        }
        foreach (self::COMMON as $t) {
            $out[] = self::topic($t['title'], $t['body'], '', 'Allgemein', $t['tags'] ?? '');
        }
        return $out;
    }

    /** Ein Thema in die einheitliche Struktur (HTML + reiner Text für die Suche) bringen. */
    private static function topic(string $title, string $body, string $route, string $source, string $tags = ''): array
    {
        $html = render_markdown($body);
        // Reiner Text für die Tokensuche (inkl. Titel und optionaler Tags).
        $text = trim($title . ' ' . strip_tags(str_replace('<', ' <', $html)) . ' ' . $tags);
        return [
            'title'  => $title,
            'html'   => $html,
            'text'   => preg_replace('/\s+/', ' ', $text),
            'route'  => $route,
            'source' => $source,
        ];
    }
}
