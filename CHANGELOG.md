# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.44.2] - 2026-07-12
### Geändert
- **Handout „Fragen & Kontakt" nutzt dieselbe Logik wie der Menüpunkt „Kontakt":**
  angezeigt wird die Projektleitung (Rolle „lead", aktive Konten) – das
  technische Admin-/Super-Admin-Konto erscheint nicht.

## [0.44.1] - 2026-07-12
### Geändert
- **Handout-Feinschliff:** In „Grußworte & Keynote" steht die Keynote jetzt
  immer am Ende (erst die Grußworte). Bei „Teilnehmende" werden nur noch die
  Zahlen genannt (z. B. „8 Ehrengäste") ohne Schul-Zusatz, da Gäste auch von
  anderswo kommen. „Fragen & Kontakt" zeigt einen festen WJ-Ansprechpartner und
  niemals Admin-/Nutzerkonten.

## [0.44.0] - 2026-07-12
### Hinzugefügt
- **PitchDay-Gäste: Vertretung hinterlegen.** Sagt eine eingeladene Person ab und
  schickt eine Vertretung, lässt sich beim Status „Vertretung" jetzt eintragen,
  **wer** vertritt (Name, Position, Organisation). Auf dem Reserviert-Schild und in
  der VIP-/Gäste-Übersicht erscheint dann automatisch die vertretende Person – mit
  dem Hinweis „vertritt …", für wen sie einspringt.
- **Reserviert-Schilder als PDF (DIN A4).** Neue Funktion im PitchDay unter
  „Gäste & VIPs": für die ausgewählten Gäste (Jury, VIP, Presse …) je ein
  Reserviert-Schild pro A4-Seite zum Ausdrucken bzw. „Als PDF speichern". Die
  Auswahl ist mit „Sitzplatz reserviert" vorbelegt und pro Gast anhakbar.
- **Ablaufplan / Handout als PDF.** Ein komplettes Handout zum PitchDay (Eckdaten,
  Ehrengäste, Jury, Ablauf, Grußworte, Preise, Presse, Sponsoren, Infos & Kontakt)
  – zusammengesetzt aus den in der App gepflegten Daten, wie das bisher manuell
  gepflegte PDF der Vorjahre. Beides ohne zusätzliche PDF-Bibliothek über die
  Druckfunktion des Browsers.

### Geändert
- **PitchDay-Formulare schließen nicht mehr bei Klick daneben.** Die Dialoge
  (Veranstaltung, Aufgabe, Gast, Programmpunkt, Budget) lassen sich nur noch über
  „Abbrechen", „×" oder ESC schließen – so gehen begonnene Eingaben nicht mehr
  durch einen versehentlichen Klick neben das Fenster verloren.

## [0.43.0] - 2026-07-10
### Geändert
- **E-Mail-Login jetzt mit 6-stelligem Code statt Magic-Link.** Der bisherige
  Magic-Link konnte von E-Mail-Sicherheitsscannern „vorab geklickt" und dadurch
  entwertet werden („Login-Link ungültig oder abgelaufen", obwohl gerade erhalten).
  Stattdessen bekommt man jetzt – wie beim SMS-Login – einen **6-stelligen
  Login-Code** per E-Mail, den man bequem kopieren/einfügen kann. E-Mail- und
  SMS-Weg nutzen dieselbe Code-Eingabe; der Code wird auf einem eigenen Feld
  eingegeben und daher nie als Telefonnummer fehlinterpretiert. Angezeigt wird,
  wohin der Code geschickt wurde. Alte Magic-Links bleiben aus Kompatibilität bis
  zum Ablauf weiterhin gültig.
### Geändert
- **Login-Seite:** Der Untertitel nennt nicht mehr die „KI-Vorbewertung", sondern
  neutral „Bewertung" („… von der Einreichung über die Bewertung bis zum Pitch Day.").

## [0.42.3] - 2026-07-10
### Behoben
- **Tabellen-Sortierung mit Kommazahlen korrigiert (grundlegend).** Spalten mit
  einem maschinellen `data-sort`-Wert (z. B. „Jury" Ø-Punkte, KI-Bewertung,
  Struktur-Check in der Businessplan-Übersicht) wurden fälschlich durch die
  Deutsch-Zahl-Heuristik interpretiert: Ein Wert wie `34.333333` galt als
  Tausenderzahl und wurde zu `34333` – Dezimalwerte landeten dadurch beim
  Sortieren ganz oben. `data-sort` wird jetzt als reiner Maschinenwert behandelt
  (Punkt = Dezimaltrenner, numerisch), Nicht-Zahlen (z. B. ISO-Zeitstempel im
  Audit-Log) als Text – letzteres sortiert nun korrekt chronologisch. Die
  Anzeige-Formatierung (deutsche Kommazahlen) bleibt unverändert.
### Geändert
- **Admin zählt nicht mehr als Jurymitglied.** Die Rolle `admin` ist eine reine
  Servicerolle und muss keine Businesspläne bewerten. Der Admin wird daher nicht
  mehr als Bewertende:r gezählt: weder in der Gesamtzahl der Bewertenden noch im
  Bewertungsstand (Coverage) im Modul „Bewertung & Ranking". Etwaige Bewertungen
  eines Admin-Kontos fließen nicht mehr in die Jury-Mittelwerte und -Zählungen ein
  (Ranking, Businessplan-Übersicht, Plan-Detailansicht). Projektleitung (`lead`) und
  Jury (`juror`) bleiben unverändert Teil der Jury.
### Geändert
- **Dashboard – Sponsoren farbig & einheitlich groß.** Die Partner-/Sponsoren-Logos
  werden jetzt in **Farbe** angezeigt (kein Graustufen-Filter mehr) und auf eine
  einheitliche Maximalgröße begrenzt (max. Breite 150 px, max. Höhe 48 px) – das
  Seitenverhältnis bleibt erhalten.

## [0.42.0] - 2026-07-10
### Geändert
- **Login mit nur einem Button.** Statt getrennter Schaltflächen für E-Mail-Link und
  SMS-Code gibt es jetzt ein einziges Feld und einen „Anmelden"-Button. Anhand der
  Eingabe entscheidet die App selbst: Eine **E-Mail-Adresse** bekommt den Magic-Link
  per E-Mail, eine **Handynummer** einen Einmalcode per SMS. Auf der Bestätigungsseite
  wird jetzt angezeigt, **wohin** der Link bzw. der Code geschickt wurde.
- **PitchDay ohne Seitensprung.** Aktionen im PitchDay-Modul (Status einer Aufgabe
  ändern, Aufgabe/Gast/Programmpunkt/Budget bearbeiten, löschen, Vorlagen einfügen)
  werden jetzt per AJAX gespeichert. Die Seite lädt nicht mehr komplett neu und
  springt nicht mehr nach oben – die Scroll-Position bleibt erhalten.

## [0.41.1] - 2026-07-06
### Geändert
- Businessplan-Übersicht: Die Ergebnis-Spalte der KI-Vorbewertung heißt in der
  Anzeige (Spalte, Sortierung, Handy-Info-Zeile) jetzt einfach **„Bewertung"**.
  Die Admin-Schaltflächen, die den KI-Lauf auslösen, bleiben als „KI-Vorbewertung".

## [0.41.0] - 2026-07-06
### Hinzugefügt
- **PitchDay-Eventplanung (neues Modul „PitchDay" für die Verwaltung).** Der PitchDay
  – die Abschlussveranstaltung eines Wettbewerbsjahres – lässt sich jetzt wie ein kleines
  Eventmanagement in der App abbilden. Alles hängt am Wettbewerbsjahr, sodass jeder
  Jahrgang seine eigene Instanz bekommt und die Historie erhalten bleibt. Vier Bereiche:
  - **Aufgaben & Checkliste:** ein wiederverwendbares Playbook der jährlich wiederkehrenden
    To-dos (Location & Technik, Catering, VIPs & Einladungen, Presse, Sponsoren & Roll-Ups,
    Preise & Urkunden, Tag-Vorbereitung). Jede Vorlagen-Aufgabe trägt einen Offset zum
    Veranstaltungstag – trägt die Projektleitung das Event-Datum ein, werden die
    **Fälligkeiten automatisch berechnet** („X Tage vorher"). Status je Aufgabe (offen,
    angefragt, zugesagt, erledigt), verantwortliche Person und Kommentar. Überfällige und
    bald fällige Aufgaben werden hervorgehoben.
  - **Gäste & VIPs:** Einladungsmanagement für Jury, VIPs, Presse, Sponsoren und Redner mit
    Status (angefragt/Zusage/Absage/Vertretung), Grußwort-/Keynote-Kennzeichnung inkl.
    Redezeit, Sitzreservierung und Bemerkung. Die Jury des Wettbewerbsjahres lässt sich per
    Klick übernehmen; eine Redner-Übersicht bündelt Grußworte und Keynote.
  - **Ablaufplan:** die Agenda des Tages (Zeiten und Programmpunkte), inkl. einfügbarer
    Standard-Agenda.
  - **Budget:** Kosten- und Preisgeld-Positionen; die Einnahmen werden aus den
    Sponsoren-Beiträgen des Jahres übernommen, Saldo automatisch berechnet.
- **Dashboard-Kachel „PitchDay"** für die Verwaltung: Countdown bis zur Veranstaltung sowie
  Anzahl offener, überfälliger und bald fälliger Aufgaben – mit direktem Sprung ins Modul.

## [0.40.2] - 2026-07-05
### Hinzugefügt
- **Null-Punkte-Markierung in der Businessplan-Übersicht:** Hat die KI-Vorbewertung
  mindestens einem Kriterium 0 Punkte gegeben, erscheint neben der KI-Note ein rotes
  Sternchen (`*`) mit Hinweis-Tooltip – als Signal, den Plan von Hand zu prüfen bzw. die
  Bewertung ggf. erneut auszuführen. Auch in der kompakten Handy-Ansicht sichtbar.

## [0.40.1] - 2026-07-05
### Behoben
- **KI-Vorbewertung: „Finanzen 0 Punkte" trotz ausführlichem Inhalt.** Das Token-Limit
  der KI-Antwort war zu knapp (2000). Bei ausführlichen Plänen riss das Modell das Limit,
  bevor es das letzte Kriterium (Finanzen) geschrieben hatte – der fehlende Wert wurde
  dann still als 0 gespeichert. Jetzt: höheres Limit (4096) und bei abgeschnittener bzw.
  unvollständiger Antwort wird ein **Fehler** gemeldet (erneut bewerten) statt einer
  falschen 0-Bewertung. Betroffene Pläne einfach „Neu bewerten". Der Struktur-Check ist
  analog abgesichert (Limit 3000, Fehler statt Teilergebnis bei Abschneiden).

## [0.40.0] - 2026-07-05
### Hinzugefügt
- **Businessplan-Übersicht (Verwaltung): KI- und Jury-Bewertung auf einen Blick.**
  Für Admin und Projektleitung zeigt die Übersicht jetzt zusätzlich zur Struktur-Note
  die **KI-Vorbewertung** (…/50) und die **Jury-Bewertung über alle Juror:innen**
  (Durchschnitt …/50 samt Anzahl abgegebener Bewertungen) – als eigene Spalten am
  Desktop und kompakt in der grauen Info-Zeile am Handy.
- **Sortierung** in der Businessplan-Übersicht: nach Name (A–Z) sowie – höchste zuerst –
  nach Struktur-Check, KI-Vorbewertung und Jury-Bewertung. Funktioniert auch mobil in
  der Karten-Ansicht; die Auswahl wird pro Gerät gemerkt.

## [0.39.0] - 2026-07-05
### Hinzugefügt
- **Vollständiges Audit-Log – wer hat wann was geändert.** Neuer Menüpunkt „Audit-Log"
  (nur Verwaltung) mit einer filter- und sortierbaren Tabelle über alle relevanten
  Vorgänge:
  - **Anmeldungen und Anmelde-Versuche:** erfolgreicher Login, angeforderte Login-Links
    und SMS-Codes, ungültige/fehlgeschlagene Versuche sowie Abmeldungen – jeweils mit
    Zeitpunkt, Akteur und IP-Adresse.
  - **Änderungen an Stammdaten und Inhalten:** Schulen, Teams & Schüler, Jury & Nutzer,
    Projektlehrer, Sponsoren (inkl. Beiträge), Wettbewerbsjahre & Meilensteine, Material,
    Businesspläne (Upload/Löschung/Struktur-Override), Bewertungen, Einstellungen sowie
    selbst angestoßene Profil-Änderungen (E-Mail/Handynummer/Foto).
  - **„Ansehen als"** (Start/Ende der Nutzersicht) wird ebenfalls protokolliert.
  - Filter nach Freitext (Akteur/Beschreibung/Aktion), Bereich und Zeitraum, mit
    Paginierung (50 pro Seite) und klickbarer Spaltensortierung.
  - Das Protokollieren ist bewusst „fire and forget": ein Log-Fehler bricht nie den
    eigentlichen Vorgang ab.

## [0.38.0] - 2026-07-05
### Hinzugefügt
- **Bewertungsstand: Wer hat welchen Plan noch nicht bewertet?** Unter „Bewertung &
  Ranking" zeigt eine neue, einklappbare Übersicht (nur Verwaltung) je Bewertende:r den
  Fortschritt (z. B. „2/12") und – rot hervorgehoben – die Namen der noch offenen
  Businesspläne; wer fertig ist, erscheint als „✓ alle bewertet". Sortiert nach den
  meisten offenen zuerst, damit man gezielt nachfassen kann.
- **Filter „Nur unvollständig bewertete"** in der Ranking-Tabelle: blendet Teams aus,
  die bereits von allen Bewertenden bewertet wurden (Auswahl wird pro Gerät gemerkt).

## [0.37.0] - 2026-07-05
### Hinzugefügt
- **Eigene E-Mail-Adresse und Handynummer selbst ändern – immer mit Bestätigung.**
  Unter „Mein Profil" können Nutzer:innen ihre Anmeldedaten jetzt selbst pflegen:
  - **E-Mail:** Ein Bestätigungslink geht an die **neue** Adresse; erst nach dem Klick
    wird sie übernommen. Die bisherige Adresse erhält zusätzlich eine Sicherheits-Info.
  - **Handynummer:** Ein 6-stelliger Code geht per SMS an die **neue** Nummer und wird
    im Profil bestätigt; gespeichert wird international (+49…).
  Beides prüft auf Eindeutigkeit (Adresse/Nummer nicht bereits vergeben). Token bzw.
  Code liegen nur als Hash in der DB, sind zeitlich begrenzt und einmalig nutzbar.

## [0.36.0] - 2026-07-05
### Hinzugefügt
- **Login per E-Mail oder Handynummer.** Auf der Anmeldeseite kann jetzt wahlweise die
  E-Mail-Adresse **oder die Handynummer** eingegeben werden – beides führt zum Login-Link
  per E-Mail bzw. zum Einmalcode per SMS. Die Handynummer wird dabei robust erkannt,
  unabhängig von der Schreibweise (`+491709009124` und `01709009124` gelten als dieselbe
  Nummer; Leerzeichen/Bindestriche werden ignoriert).
### Geändert
- **Handynummern werden immer im internationalen Format ohne Leerzeichen gespeichert**
  (z. B. `+491709009124`). Neu erfasste Nummern (Jury/Nutzer, Projektlehrer) werden beim
  Speichern normalisiert; eine Migration bringt bestehende Nummern automatisch ins
  gleiche Format.

## [0.35.7] - 2026-07-05
### Behoben
- **Businessplan-Einzelsicht lief mobil noch über den Rand.** Ursachen behoben:
  langer Datei­name bricht jetzt hart im Wort um, die Datei-Auswahl (Upload) schrumpft
  und bricht um, und der „Team verwalten"-Knopf im Kopf darf umbrechen. Zusätzlich ein
  globales Sicherheitsnetz gegen versehentlichen horizontalen Seiten-Scroll.
### Geändert
- **Businessplan-Übersicht: klarere, kompakte Zeilen.** Fett steht jetzt nur noch der
  Plan-/Team-Name (keine Dopplung mehr mit der Idee). Die graue zweite Zeile zeigt –
  auch auf dem Handy – kurz **Schule (Kürzel) · Struktur-Check · eigene Bewertung**
  (z. B. „EGF · Struktur 7/10 · ● offen").
### Hinzugefügt
- **Filter „Schwache Struktur ausblenden"** in der Businessplan-Übersicht: blendet Pläne
  unter dem Mindeststandard aus (Auswahl wird pro Gerät gemerkt).

## [0.35.6] - 2026-07-05
### Hinzugefügt
- **Automatisches Speichern der Jury-Bewertung.** In der Businessplan-Bewertung
  werden Punkte und Notizen jetzt automatisch gespeichert – kurz nach der Eingabe und
  spätestens beim Verlassen des Feldes. Als Rückmeldung leuchtet der Rahmen des
  Kriteriums kurz grün auf („✓ gespeichert"), und im Fuß erscheint „✓ Automatisch
  gespeichert". So kann nichts mehr verloren gehen; der „Speichern"-Knopf bleibt als
  bewusstes Speichern erhalten. Bei Verbindungsproblemen erscheint ein roter Hinweis.

## [0.35.5] - 2026-07-05
### Behoben
- **Businessplan-Einzelsicht: kein horizontaler Scroll mehr auf dem Handy.** Die
  Tabellen von Struktur-Check und KI-Vorbewertung liefen über den Rand hinaus; sie
  werden auf schmalen Bildschirmen jetzt als gestapelte Karten dargestellt.
- **PDF-Vorschau auf Handy/Tablet:** Businessplan-PDFs öffnen dort jetzt direkt
  (nativer Viewer/Download) statt im Vorschau-Modal – mobile Browser können PDFs
  nicht im eingebetteten Rahmen anzeigen und zeigten stattdessen nur einen
  Download-Platzhalter. Am Desktop bleibt die eingebettete Vorschau.
### Geändert
- **Businessplan-Übersicht mobil viel kompakter:** Eine dichte Zeile je Plan
  (Team-/Projektname + Status „✓ / offen" + „Öffnen"); Detailspalten (Schule,
  Version, Struktur-Check, KI) sind auf dem Handy ausgeblendet. So passen deutlich
  mehr Pläne auf den Bildschirm; die Suchleiste steht oben.

## [0.35.4] - 2026-07-05
### Behoben
- **Mobiles Menü scrollt jetzt selbst statt des Hintergrunds.** Bei geöffnetem
  Menü-Drawer wurde die dahinterliegende Seite gescrollt, während die (auf dem Handy
  längere) Navigation unten auslief – „Sponsoren"/„Admin" waren kaum erreichbar. Jetzt
  scrollt der Navigationsbereich intern (Logo oben, Fußzeile unten fixiert) und der
  Hintergrund ist gesperrt, solange das Menü offen ist.

## [0.35.3] - 2026-07-05
### Geändert
- **KI-Vorbewertung standardmäßig nur für Verwaltung sichtbar.** Die inhaltliche
  KI-Note (/50) sehen jetzt nur noch Admin und Projektleitung – nicht mehr die normale
  Jury. Betrifft Businessplan-Detail, Businessplan-Übersicht und das Ranking (KI-Spalte).
### Hinzugefügt
- Neue Admin-Einstellung unter KI-Integration: „KI-Vorbewertung auch für Juror:innen
  sichtbar" – bei Bedarf schaltet die Verwaltung die KI-Note wieder für die Jury frei.

## [0.35.2] - 2026-07-05
### Geändert
- **Teams-Verwaltung für Lehrkräfte ohne Schulzuordnung:** Ist einer Lehrkraft keine
  Schule zugeordnet, erscheint unter „Teams & Schüler" jetzt ein klarer Hinweis (statt
  einer stumm leeren, funktionslosen Ansicht) und der „+ Neu"-Button wird ausgeblendet.

## [0.35.1] - 2026-07-05
### Behoben
- **Kontakt-Seite zeigt nur noch die Projektleitung.** Bisher erschien dort auch das
  Admin/Super-Admin-Konto – fälschlich als „Projektleitung" beschriftet – und führte
  bei doppelt geführten Personen zu Dubletten. Die Seite listet jetzt ausschließlich
  Konten mit der Rolle „lead".

## [0.35.0] - 2026-07-05
### Geändert
- **Sponsoren-Übersicht: Logo in der ersten Spalte.** In der Verwaltungsliste
  steht das Sponsorenlogo jetzt direkt neben dem Namen in der ersten Spalte
  (statt in einer separaten, leicht übersehbaren Mini-Spalte). Ist kein Logo
  hinterlegt, erscheint ein neutraler Platzhalter mit dem Anfangsbuchstaben.

### Behoben
- **Fehlende Logos bekannter Sponsoren nachgetragen:** Eine Migration setzt für
  bekannte Sponsoren (Sparkasse, VIERLING, Medical Valley, Bildungsregion,
  Stadt/Stadtwerke Ebermannstadt, WJ Bayern) den vorhandenen Logo-Pfad, falls
  dieser in der Datenbank noch leer war. Selbst gepflegte Logos bleiben
  unverändert.

## [0.34.0] - 2026-07-05
### Hinzugefügt
- **Kontakt-Seite im Menü (für alle sichtbar):** Neuer Menüpunkt „Kontakt" in der
  Gruppe „Für alle". Er zeigt die Projektleitung (Admin & Projektleitung) als
  Karten mit Porträtfoto, Rolle, Spezialgebiet sowie anklickbaren Kontaktdaten
  (E-Mail per `mailto:`, Telefon per `tel:`). So finden Lehrkräfte und Jury schnell
  die richtigen Ansprechpartner.

## [0.33.0] - 2026-07-05
### Hinzugefügt
- **Businessplan direkt aus dem Ranking öffnen:** Unter „Bewertung & Ranking" öffnet ein
  Klick auf den Team-Namen (sofern ein Plan vorliegt) das Businessplan-PDF im Vorschau-
  Modal – wie bereits in der Businessplan-Liste.

## [0.32.0] - 2026-07-05
### Geändert
- **Struktur-Check misst jetzt Eigentext statt Struktur.** Der Check hat Pläne
  fälschlich durchgewinkt, weil er die von uns vorgegebenen Überschriften/Leitfragen
  („Vertrieb & Kommunikation – Wie machst du auf dich aufmerksam?") als bearbeiteten
  Inhalt gewertet hat. Jetzt wird die Vorlage explizit abgezogen: gemessen wird nur der
  **selbst geschriebene Text** der Schüler:innen. Das Modell schätzt zusätzlich die
  eigenen Sätze je Abschnitt und die Gesamt-Eigentext-Wortzahl (ohne Überschriften,
  Leitfragen, Platzhalter, Deckblatt, Bildunterschriften).
- **Härtere Ausschlusskriterien.** Ein Plan gilt unabhängig vom Substanz-Score als
  „unter Mindeststandard", wenn der geschätzte Eigentext unter dem Mindestwert liegt
  (neu, Default 200 Wörter) oder weniger als N Kernabschnitte wirklich ausgearbeitet
  sind (neu, Default 2). Fängt „1-Seiten"-Pläne und reine Stichpunktlisten zuverlässig.
### Hinzugefügt
- **Manueller Override für die Projektleitung.** Verwaltung (Admin/Lead) kann das
  Struktur-Check-Ergebnis je Plan von Hand auf „bestanden" oder „aussortiert" setzen
  (mit Begründung) oder den Override wieder aufheben. Der Override wird am Plan
  gespeichert, übersteht ein erneutes „alle neu prüfen" und ist in Übersicht und Detail
  als „✋ Override" gekennzeichnet.
- Zwei neue Admin-Einstellungen unter KI-Integration: Mindest-Eigentext (Wörter) und
  Mindestzahl wirklich ausgearbeiteter Kernabschnitte (jeweils mit 0 = Regel aus).

## [0.31.0] - 2026-07-05
### Hinzugefügt
- **Teams und Businesspläne wechselseitig verlinkt:** In der Team-Verwaltung öffnet
  der Businessplan-Eintrag (Liste wie Detailansicht) direkt das PDF; die Detailansicht
  verlinkt zusätzlich per „Zum Businessplan →" auf die Plan-Seite. Umgekehrt führt die
  Team-Karte des Businessplans über „👥 Team verwalten" zurück in die Team-/Mitglieder-
  Verwaltung (nur für Verwaltung bzw. Lehrkraft der eigenen Schule – die Jury sieht den
  Link nicht). Zusätzlich wird dort die Mitgliederzahl angezeigt.

## [0.30.0] - 2026-07-05
### Hinzugefügt
- **Teammitglieder aus den Businessplänen importiert:** Die in den eingereichten
  Businessplan-PDFs (`storage/seed_plans/`) genannten Schüler:innen werden per
  Migration als Teammitglieder angelegt und dem jeweiligen Team über den
  aktuellen Businessplan zugeordnet (205 Mitglieder aus 42 Teams). Zwei Pläne
  (`9b_4youcafe`, `9b_Heimatbox`) nennen keine Namen und bleiben ohne Mitglieder.
  Der Import ist idempotent – Teams, die bereits gepflegte Mitglieder haben,
  werden nicht angetastet.

## [0.29.1] - 2026-07-05
### Geändert
- Businessplan-Detail: Jury-Bewertung, Struktur-Check und KI-Vorbewertung als
  standardmäßig **eingeklappte** Karten (Kurzinfo neben der Überschrift). Jury-Bewertung
  steht **oben**; „Selbst bewerten" ist auch eingeklappt immer sichtbar.

### Hinzugefügt
- Businesspläne-Liste: Kopf-Schalter **„Bereits bewertete Pläne ausblenden"** (standardmäßig
  an) – blendet Pläne aus, die der/die angemeldete Bewertende bereits bewertet hat
  (pro Gerät gemerkt).

## [0.29.0] - 2026-07-05
### Hinzugefügt
- **Material & Vorlagen: Einträge bearbeitbar.** Downloads & Links lassen sich jetzt über
  ein Modal **bearbeiten** (Titel, Beschreibung, Link, Sichtbarkeit) – nicht mehr nur
  anlegen und löschen. Eine hinterlegte Datei kann ersetzt oder entfernt werden; ohne
  neue Datei bleibt die bestehende erhalten. Anlegen und Bearbeiten laufen über dasselbe
  Formular-Fenster (wie bei Schulen, Teams etc.).

## [0.28.0] - 2026-07-05
### Hinzugefügt
- **Material & Vorlagen: editierbarer Eingangstext (Markdown).** Über der Download-Liste
  lässt sich jetzt ein Einführungstext pflegen – mit Überschriften, Listen, **fett** und
  Links. Bearbeitbar für **Admin und Projektleitung**; für alle anderen wird der Text
  nur angezeigt (falls gepflegt).
- **Downloads & Links sortierbar.** Die Verwaltung kann Einträge per Hoch-/Runter-Pfeil
  in die gewünschte Reihenfolge bringen; die Sortierung wird dauerhaft gespeichert.

## [0.27.0] - 2026-07-05
### Hinzugefügt
- **Konfigurierbare Meilensteine (Projektablauf) je Wettbewerbsjahr:** Der bisher
  fest hinterlegte Projektablauf auf dem Dashboard lässt sich nun unter
  **Wettbewerbsjahre → (Jahr wählen)** frei pflegen. Je Meilenstein können ein
  Name, ein **konkretes Datum oder ein Zeitraum** (Von/Bis) oder alternativ eine
  **freie Zeitangabe** (z. B. „8 Wochen", „ab April") sowie die Reihenfolge
  hinterlegt werden.
- Der **Status** (erledigt / läuft / geplant) je Meilenstein kann fest gesetzt
  oder auf **automatisch** gestellt werden – dann leitet ihn die App aus dem
  Datum relativ zum heutigen Tag ab.

### Geändert
- Die Dashboard-Zeitleiste „Projektablauf" zeigt jetzt die Meilensteine des
  **aktiven** Wettbewerbsjahres samt dessen Jahresbezeichnung; sind für ein Jahr
  noch keine Meilensteine hinterlegt, erscheint ein Hinweis mit Link zur Pflege.

## [0.26.0] - 2026-07-05
### Geändert
- **Mobile-First auch für die restlichen Tabellen:** „Bewertung & Ranking" und
  „Businesspläne" sowie die Bewertungs-Detailtabelle, „Material & Vorlagen" und die
  Sponsoren-Beiträge werden auf schmalen Bildschirmen zu **gestapelten Karten** –
  kein horizontales Scrollen mehr. Beim Ranking wird die Admin-Zeile „Status setzen"
  direkt in die jeweilige Team-Karte integriert (statt als separater Block).
- Damit sind alle Übersichts- und Ergebnistabellen der App durchgängig mobil bedienbar.

## [0.25.1] - 2026-07-05
### Behoben
- **Topbar auf dem Handy entschlackt:** Der Seitentitel brach dort mehrzeilig um
  und überlappte Burger-Menü und Nutzerbereich, „Abmelden" war teils abgeschnitten.
  Auf schmalen Bildschirmen zeigt die Topbar jetzt nur noch Menü-Button, Profil-Avatar
  und „Abmelden" (Titel steht ohnehin als Überschrift auf der Seite; Rolle und Name
  werden ausgeblendet). Ohne Profilfoto erscheint ein Initial-Avatar.

## [0.25.0] - 2026-07-05
### Geändert
- **Mobile-First-Politur der Stammdaten-Listen:** Auf schmalen Bildschirmen
  werden die Übersichtstabellen (Schulen, Jury & Nutzer, Wettbewerbsjahre,
  Teams, Sponsoren) zu **gestapelten Karten** – kein horizontales Scrollen mehr,
  jede Spalte mit Beschriftung, und die Aktionen (Bearbeiten/Löschen/Öffnen)
  als **volle, gut tippbare Buttons**. Reine Icon-Schaltflächen (z. B. „Ansehen
  als") bleiben kompakt.
- **Formular- und Bestätigungs-Dialoge als Bottom-Sheet** auf dem Handy: sie
  fahren von unten ein, füllen die Breite, haben einen **klebrigen Kopf und Fuß**
  (Titel bzw. Aktionsknöpfe immer sichtbar) und berücksichtigen die
  Safe-Area am unteren Rand.
- Löschen-Schaltflächen sind jetzt durchgängig mit „Löschen" beschriftet (statt
  eines bloßen „×"), was besonders auf dem Handy eindeutiger ist.

## [0.24.1] - 2026-07-05
### Behoben
- **Lehrkräfte sehen keine Bewertungen mehr:** Struktur-Check- und KI-Vorbewertungs-
  Spalten (Businessplan-Liste) sowie die entsprechenden Karten auf der Detailseite
  werden für die Rolle Lehrkraft ausgeblendet (Jury-Bereich war bereits gesperrt).
  Upload und Team-/Schülerpflege für die eigene Schule bleiben möglich.

## [0.24.0] - 2026-07-05
### Hinzugefügt
- **Projektlehrer je Schule:** eigene Ansicht (aus der Schulliste über „Projektlehrer")
  zum Anlegen/Bearbeiten von Lehrkräften mit Name, E-Mail und Mobilnummer. Sie melden
  sich per Login-Link (E-Mail) an, laden für ihre Schule Businesspläne hoch und pflegen
  Teams/Schüler – sehen aber **keine Bewertungen**.

### Geändert
- **Menü nach Rollen gruppiert** (Überschriften „Für alle", „Lehrkraft", „Jury",
  „Verwaltung") – jede Gruppe erscheint nur, wenn die aktuelle Rolle mindestens einen
  Punkt darin sieht. So ist transparent, wer was sieht.

## [0.23.0] - 2026-07-05
### Geändert
- **Stammdaten-Verwaltung überarbeitet (Schulen, Jury & Nutzer, Wettbewerbsjahre,
  Teams, Sponsoren):** Jede Übersicht hat jetzt oben rechts einen **„+ Neu"-Button**,
  Anlegen und Bearbeiten laufen einheitlich über ein **modales Formular-Fenster**
  (mit Abdunkeln/Weichzeichnen des Hintergrunds, Schließen per ×, Escape oder
  Klick daneben). Das Bearbeiten befüllt das Formular ohne Neuladen der Seite –
  inkl. Vorschau eines bereits hinterlegten Logos/Porträtfotos.
- **Löschen** ist überall vorhanden und fragt jetzt über einen gestalteten
  Bestätigungs-Dialog nach (statt des schlichten Browser-Hinweises).
- Bei **Teams** ist damit erstmals das Löschen aus der Übersicht möglich; die
  Detailansicht konzentriert sich auf die Teammitglieder, bei **Sponsoren** auf
  die Beiträge/Zuwendungen.

## [0.22.1] - 2026-07-05
### Behoben
- **Bild-Zuschnitt: „Übernehmen" blieb wirkungslos.** Der Zoom-Slider wurde beim
  Öffnen des Dialogs zu früh (vor dem `ready` des Croppers) angesteuert, was einen
  Fehler auslöste und den Ergebnis-Handler nicht mehr registrieren ließ. Der Handler
  wird jetzt vor der Cropper-Initialisierung gesetzt, der Zoom-Slider erst im
  `ready`-Callback sinnvoll konfiguriert. „Zuschneiden/Zoomen/Drehen → Übernehmen"
  speichert das Bild nun zuverlässig (real im Browser verifiziert).

## [0.22.0] - 2026-07-05
### Hinzugefügt
- **PDF-Businesspläne im Modal ansehen**: Der Businessplan öffnet sich jetzt als
  eingebettete Vorschau in einem großen Dialog, ohne die Seite zu verlassen.
  - In der **Übersicht „Businesspläne"** ist der **Team-/Ideenname** anklickbar
    (sofern ein Plan hochgeladen wurde) und öffnet die PDF-Vorschau.
  - In der **Detailansicht** öffnet der neue Button **„PDF ansehen"** dieselbe
    Vorschau; **„Herunterladen"** steht weiterhin daneben zur Verfügung.
  - Der Dialog bietet zusätzlich **„Neuer Tab ↗"** und schließt per Klick auf den
    Hintergrund oder mit **Escape**. Ohne JavaScript bleibt der Link ein normaler
    PDF-Aufruf (Fallback).

## [0.21.0] - 2026-07-05
### Hinzugefügt
- **Bild-Ablage per Drag & Drop mit Zuschnitt** an allen Bild-Stellen: Datei per
  Ziehen ablegen oder klicken, dann im Dialog **zuschneiden, zoomen und drehen**
  (Cropper.js, lokal eingebunden – kein CDN). Umgesetzt für:
  - **Sponsoren-Logo** (ersetzt den bisherigen einfachen Datei-Dialog),
  - **Schul-Logo** (im Schul-Formular bisher gar nicht hochladbar – neu),
  - **Jury-/Nutzer-Porträtfoto** (quadratisch, rund dargestellt) – Avatare
    erscheinen zusätzlich in der Nutzerliste,
  - **eigenes Profilfoto** unter „Mein Profil" (auch in der Topbar sichtbar).
- **`photo_path`** an der `users`-Tabelle (Auto-Migration) für Porträtfotos.
- Vektor-Logos (**SVG**) werden weiterhin unverändert übernommen; ohne
  JavaScript bleibt der klassische Datei-Upload als Rückfallebene erhalten.

## [0.20.1] - 2026-07-05
### Behoben
- Deaktivierte Buttons zeigten einen „Lade"-Cursor (wirkte wie hängender Spinner);
  jetzt korrekt „nicht verfügbar". Der Bulk-Button war bei 0 offenen Plänen deaktiviert,
  sodass sich nach der Kalibrierung nichts neu prüfen ließ.

### Hinzugefügt
- Je Bulk-Aktion (Struktur-Check, KI-Vorbewertung) nun zwei Optionen: **„offene (N)"**
  und **„alle neu (Gesamt)"** — Letzteres prüft/bewertet alle Pläne erneut (z. B. nach
  einer Schwellwert-Kalibrierung). Der Fortschritts-Dialog bleibt über **„Abbrechen"** stoppbar.

## [0.20.0] - 2026-07-05
### Geändert
- **Zuordnung im Wettbewerbsjahr:** In der Projektleitungs-Auswahl werden nur noch
  echte **Projektleitungen** (`lead`) angezeigt – das Admin-/Eigentümer-Konto
  (Super-Admin) taucht dort nicht mehr auf. Bestehende Zuordnungen von Admin-Konten
  bleiben beim Speichern unangetastet.

## [0.19.0] - 2026-07-05
### Hinzugefügt
- **Test-Mail-Funktion im Admin** (Karte „Anmeldung & Zustellung"): Zieladresse
  eingeben und eine gestaltete Test-Mail über den aktuellen Absender verschicken –
  praktisch zum Prüfen der Zustellbarkeit (z. B. mit der Adresse von mail-tester.com).

## [0.18.0] - 2026-07-05
### Geändert
- **Login-Mails im WJD-Design (HTML):** Der Magic-Link kommt jetzt als gestaltete
  E-Mail mit Kopfband, klarem „Jetzt anmelden"-Button und Fußzeile – statt eines
  nackten, langen Links. Versand als **multipart/alternative** (HTML + Text-Fallback),
  wodurch auch Spamfilter-Warnungen zu „leeren" Nachrichten entfallen. Der `Mailer`
  bietet dafür eine wiederverwendbare Vorlage `brandedHtml()`.

## [0.17.0] - 2026-07-05
### Geändert
- **Vereinheitlichtes Wettbewerbsjahr:** Es gibt jetzt nur noch **eine** Quelle für
  „welches Jahr" – den Wettbewerbszyklus (`competition_cycles`). Die Sponsoren-Beiträge
  hängen jetzt am Zyklus (`cycle_id`) statt an einer separaten Jahreszahl; die frühere
  Einstellung `competition_year` und das Admin-Feld dazu entfallen
- Sponsoren-Beitrag wird über ein **Wettbewerbsjahr-Auswahlfeld** erfasst; die
  Dashboard-Auto-Anzeige der Sponsoren richtet sich nach dem **aktiven Zyklus**
- Admin → „Wettbewerb" zeigt das aktive Jahr nur noch an und verlinkt zur zentralen
  Verwaltung unter „Wettbewerbsjahre"

### Migration
- `sponsor_contributions.year` → `cycle_id` (Fremdschlüssel auf `competition_cycles`):
  bestehende Beiträge werden automatisch dem passenden Zyklus zugeordnet, die alte
  Spalte und die Einstellung `competition_year` werden entfernt

## [0.16.0] - 2026-07-05
### Hinzugefügt
- **Eigene Admin-Rolle** (Eigentümer/Super-Admin) getrennt von der
  **Projektleitung**: Bisher waren beide dieselbe Rolle `admin` (nur mit dem
  Label „Projektleitung"). Ab jetzt gibt es vier Rollen —
  **Admin** (dauerhafter Eigentümer, `mv@vimatec.de`),
  **Projektleitung** (`lead`, wechselt jährlich, volle Verwaltung),
  Lehrkraft, Jury.
- Nur ein **Admin** kann die Admin-Rolle vergeben/entziehen sowie Admin-Konten
  bearbeiten oder löschen. Das dauerhafte Eigentümer-Konto `mv@vimatec.de` ist
  vor Löschen/Herabstufen geschützt.
- **„Ansehen als" (View-as):** Ein Admin kann die App aus Sicht eines beliebigen
  Nutzers (Projektleitung, Lehrkraft, Jury) betrachten – Nur-Lese-Ansicht mit
  Hinweisbanner und „Sicht beenden". Start über das 👁-Symbol in „Jury & Nutzer".
### Geändert
- Migration `2026_07_13_admin_role_tier`: erweitert das Rollen-ENUM um `lead`
  und stuft bestehende `admin`-Konten (außer dem Eigentümer) automatisch zur
  Projektleitung (`lead`) herab – bestehende Berechtigungen bleiben voll erhalten.

## [0.15.0] - 2026-07-05
### Hinzugefügt
- **SMS-Login als alternative Anmeldemethode (seven.io):** Auf der Login-Seite kann
  neben dem E-Mail-Magic-Link ein **6-stelliger Einmalcode per SMS** angefordert werden
  (an die am Nutzer hinterlegte Handynummer). Beide Wege sind gleichwertig und passwortlos.
  Der Code ist 10 Minuten gültig, wird nur als SHA-256-Hash gespeichert und gegen Erraten
  geschützt (Versuchszähler). Die SMS-Option erscheint nur, wenn ein seven.io-API-Key
  hinterlegt ist.
- **Admin → „Anmeldung & Zustellung":** E-Mail-Absender (Adresse + Name) sowie
  seven.io-API-Key und SMS-Absender direkt in der App konfigurierbar (in der DB, kein
  Redeploy nötig). Der `Mailer` bevorzugt diese Einstellungen und fällt sonst auf die
  Deploy-Config zurück; Login-Mails erhalten zusätzlich einen `Reply-To`-Header.

### Geändert
- Tabelle `login_codes` (SMS-Einmalcodes) samt automatischer Migration.

## [0.14.0] - 2026-07-05
### Geändert
- **Eigene Subdomain:** Die App läuft jetzt unter **https://uplus.vimatec.de**
  (statt im Unterordner `https://vimatec.de/uplus`). Sie liegt damit im Web-Root;
  der Base-Path wird automatisch leer erkannt, das `BASE_PATH`-Secret entfällt.
- **PHP 8.5:** Betrieb auf PHP 8.5; der Deploy-Workflow prüft und lintet die
  Quellen nun ebenfalls gegen PHP 8.5.
- Dokumentation (README) auf Subdomain, PHP 8.5 und den aktuellen Deploy-Trigger
  (`main`) aktualisiert; Service-Worker-Cache-Version an die App-Version angeglichen.

## [0.13.0] - 2026-07-05
### Hinzugefügt
- **Wettbewerbsjahre (Zyklen)** als zentrales Objekt: eigenes Menü „Wettbewerbsjahre“,
  in dem ein neues Wettbewerbsjahr angelegt und genau eines als *aktiv* gesetzt wird.
  Jury, Projektleitung und teilnehmende Schulen werden je Jahr zugeordnet
- **Jahres-Zuordnung direkt beim Juror**: im Menü „Jury & Nutzer“ lässt sich pro Person
  auswählen, in welchen Wettbewerbsjahren sie dabei ist – Mehrfachauswahl inklusive
  Lücken zwischen den Jahren. Die Historie „wer war wann Juror:in / Projektleitung“
  bleibt dauerhaft erhalten
- Bestehende Jury, Projektleitung und Schulen werden per Migration automatisch dem
  ersten (aktiven) Wettbewerbsjahr zugeordnet, sodass keine Zuordnung verloren geht

## [0.12.0] - 2026-07-05
### Hinzugefügt
- **Progressive Web App (PWA):** Die Anwendung ist jetzt auf Smartphone,
  Tablet und Desktop installierbar (Web-Manifest, Service Worker, App-Icons im
  WJD-Design). Nach der Installation läuft sie im eigenständigen Fenster; bei
  fehlender Verbindung erscheint eine schlanke Offline-Hinweisseite.
- Dezenter **Installations-Hinweis (Toast)** „… für ein super Erlebnis“ mit
  Installieren-Button (Android/Chromium/Edge) bzw. Kurzanleitung „Zum
  Home-Bildschirm“ auf iOS. Merkt sich das Wegklicken und nervt nicht erneut.

### Geändert
- **Navigation links ist jetzt einklappbar:** Am Desktop lässt sie sich per
  Button auf eine schmale Icon-Leiste reduzieren (Zustand wird gemerkt); auf
  dem Smartphone ist sie automatisch eingeklappt und öffnet als Drawer über den
  Burger-Button. Im eingeklappten/mobilen Modus sind die Icons entsprechend
  größer und die Touch-Ziele komfortabler.

## [0.11.0] - 2026-07-05
### Geändert
- **Passwortloser Login (Magic-Link):** Die Anmeldung erfolgt jetzt ausschließlich
  über die E-Mail-Adresse. Nutzer geben ihre Adresse ein und erhalten einen
  einmaligen, 30 Minuten gültigen Login-Link per Mail – es gibt keine Passwörter
  mehr. Bestätigungstext und interne Nachschlage-Logik verhindern, dass sich
  vorhandene Konten anhand der Rückmeldung erraten lassen (kein User-Enumeration)
- Nutzerverwaltung („Jury & Nutzer") und Profil ohne Passwortfelder; neue Konten
  sind sofort per Login-Link nutzbar, sobald eine gültige E-Mail hinterlegt ist

### Hinzugefügt
- Token-Tabelle `login_tokens` (es wird nur der SHA-256-Hash des Einmal-Tokens
  gespeichert) samt automatischer Migration
- Schlanker E-Mail-Versand (`Mailer`) über PHP `mail()` und neue Konfigurationswerte
  `app_url`, `mail_from`, `mail_from_name`

## [0.10.0] - 2026-07-05
### Geändert
- **Struktur-Check kalibriert & steuerbar:** statt einer unzuverlässigen Ja/Nein-
  Entscheidung des Modells liefert der Check nun je Kernabschnitt eine Bearbeitungstiefe
  (behandelt=2 / oberflächlich=1 / fehlt=0) → **Substanz-Score 0–10**. „Unter Standard"
  ergibt sich aus einem **im Admin einstellbaren Schwellwert** (Standard 6) — so lässt
  sich die Aussortier-Quote selbst auf ~30–50 % kalibrieren. Der Score ist sortierbar.
- Zusammenfassung und Anhang zählen **nicht** mehr als Pflichtabschnitt (behebt
  Falsch-Markierungen solider Pläne ohne Executive Summary, z. B. „Schülercafe").
- Struktur-Check-Prompt als strenge Jury-Triage geschärft (Stichpunkte-only = nicht ausreichend).

## [0.9.0] - 2026-07-05
### Hinzugefügt
- **Sponsoren-Verwaltung** (Menü, nur Admin): Logo, Name, Anschrift, Ansprechpartner,
  E-Mail, Website; je Sponsor eine Tabelle mit Beiträgen pro Jahr — Geldbetrag oder
  Sachleistung (z. B. „kostenfreier Bustransfer")
- Sponsor-Logos erscheinen **automatisch im Dashboard**, sobald der Sponsor im
  aktuellen Wettbewerbsjahr eine Leistung erbringt; Wettbewerbsjahr im Admin wählbar
- Logo-Upload je Sponsor (einfacher Datei-Upload; Bildeditor folgt)

## [0.8.1] - 2026-07-05
### Behoben
- App hängte während einer Massen-Verarbeitung: Die frühere synchrone „alle prüfen/
  bewerten"-Aktion verarbeitete alle Pläne in einem Request und sperrte dabei die
  PHP-Session, wodurch alle weiteren Anfragen blockierten. Diese Aktion wurde
  entfernt; die Bulk-Endpunkte geben die Session jetzt sofort frei
  (`session_write_close`), sodass die App während der Verarbeitung bedienbar bleibt.

## [0.8.0] - 2026-07-05
### Behoben
- Tabellen-Sortierung und -Suche wurden wegen Browser-Caching des alten JavaScripts
  nicht angezeigt: CSS/JS (und Assets) erhalten jetzt ein **Versions-Cache-Busting**
  (`?v=Version`), sodass Updates nach dem Deploy sofort greifen

### Hinzugefügt
- **Fortschrittsbalken** für Struktur-Check und KI-Vorbewertung im Menü Businesspläne:
  die Bulk-Verarbeitung läuft jetzt Plan für Plan (ein Request je Plan, kein Timeout)
  und zeigt live „Plan X von N: <Name>" sowie eine Abschluss-Zusammenfassung

## [0.7.0] - 2026-07-05
### Geändert
- Mindeststandard-Gate ist jetzt ein eigener **Struktur-Check** gegen die Abschnitte
  der Businessplan-Vorlage (Zusammenfassung, Geschäftsidee, Vertrieb & Wettbewerb,
  Team & Partner, Dein Unternehmen, Finanzen, Anhang): je Abschnitt „behandelt /
  nur oberflächlich / fehlt". Läuft als **günstiger, eigener Pass** (Standard: Haiku),
  getrennt vom inhaltlichen Scoring
- Modell für den Struktur-Check separat im Admin wählbar

### Hinzugefügt
- Struktur-Check-Spalte in der Businessplan-Liste und eigene Karte auf der
  Detailseite (Abschnitts-Abdeckung + Gate-Ergebnis); Einzel-Button je Plan
- Bulk-Aktionen im Menü Businesspläne: Struktur-Check bzw. KI-Vorbewertung für
  alle offenen Pläne auf einmal (verarbeitet nur noch nicht Erledigte)
- Schul-Logos in der Schulen-Übersicht
- Alle Tabellen sind jetzt standardmäßig **sortierbar** (Klick auf die Spalte,
  korrekte Behandlung deutscher Zahlen- und Datumswerte) und haben eine
  **tokenbasierte Suche** darüber (mehrere Begriffe = UND-Verknüpfung) —
  automatisch für alle bestehenden und künftigen Tabellen

## [0.6.0] - 2026-07-05
### Hinzugefügt
- Mindeststandard-Gate der KI-Vorbewertung: die KI beurteilt zusätzlich, ob ein Plan
  den Mindeststandard eines ernsthaft bemühten Schülerteams erfüllt; „nicht erfüllt"
  wird deutlich markiert (Liste + Detailseite), damit solche Pläne ohne weitere
  Sichtung aussortiert werden können
- In der App editierbare KI-Leitlinie (Admin → KI-Integration): Definition des
  Mindeststandards und zusätzliche Bewertungshinweise
- Lade-Spinner an Buttons mit Aktivität (z. B. KI-Vorbewertung), inkl. Schutz gegen
  Doppel-Absenden

### Geändert
- KI-Prompt liegt in `app/lib/Claude.php`; Mindeststandard und Zusatzhinweise sind
  nun über das Admin-Menü konfigurierbar

## [0.5.0] - 2026-07-05
### Hinzugefügt
- Jury-Bewertung: je Juror:in und Team die 5 Businessplan-Kriterien (0–10 mit
  Notizen), Pitch-Kriterien erscheinen für nominierte Teams; Live-Summe
- Bewertungsübersicht & Ranking: Mittelwerte je Team (Ø Businessplan, Ø Pitch,
  Gesamt bis 140), KI-Wert zum Vergleich, Sortierung nach Gesamtpunktzahl
- Nominierung: „Top 7 (+2)" automatisch aus dem Ranking, plus manuelles Setzen
  von Status und Pitch-Reihenfolge
- Jury-Bewertungen je Plan auf der Businessplan-Detailseite sichtbar

## [0.4.0] - 2026-07-05
### Hinzugefügt
- Admin-Menü (nur Projektleitung) mit zentraler Einstellungen-Seite:
  - KI-Integration: Anthropic-API-Key und Modell direkt in der App hinterlegbar
    (überschreibt das Deploy-Secret, kein Redeploy nötig)
  - Wettbewerb: aktuelle Phase, Anzahl Pitch-Plätze und Nachrücker
  - Sicherheit: 2FA-Einstellung (TOTP-Einrichtung folgt)
- Settings-Verwaltung (Key/Value) als Grundlage für in der App änderbare Konfiguration

### Geändert
- Admin-Konto `mv@vimatec.de` heißt jetzt schlicht „Martin Vierling“ (ohne Zusatz)
- KI-Vorbewertung nutzt bevorzugt den in der App hinterlegten API-Key

## [0.3.0] - 2026-07-05
### Hinzugefügt
- Businessplan-Upload (PDF, je Team versioniert) inkl. geschütztem Download
- KI-Vorbewertung über die Anthropic-Claude-API: liest die PDF nativ und bewertet
  die fünf Businessplan-Kriterien (0–10 mit Begründung, Stärken/Schwächen)
- Import aller eingereichten Businesspläne je Schule (EGF/GFS/HGF) inkl.
  automatischer Ableitung lesbarer Team-/Projektnamen aus den Dateinamen
- Dauerhaftes App-Admin-Konto `mv@vimatec.de` (bleibt Eigentümer, unabhängig von
  der jährlich wechselnden Projektleitung)
- Versionierung mit Changelog – Version in der App sichtbar, per Klick einsehbar

### Geändert
- Upload-Limits per `.user.ini` auf 32 MB angehoben (echte Businesspläne)

### Behoben
- Upload großer PDFs scheiterte am PHP-Standardlimit (2 MB); klare Fehlermeldung
  bei Überschreitung des Server-Limits

## [0.2.0] - 2026-07-05
### Hinzugefügt
- Stammdaten-Verwaltung: Schulen; Jury & Nutzer (Rollen, Passwortvergabe,
  Aktiv-Status); Teams & Schüler (Lehrkräfte auf eigene Schule beschränkt)
- Material & Vorlagen: Downloads, Datei-Upload, Erklärvideo-Embed,
  Sichtbarkeit je Rolle

### Behoben
- Automatische Base-Path-Erkennung: Links & Assets funktionieren jetzt auch im
  Unterordner-Deploy (`/uplus`)

## [0.1.0] - 2026-07-05
### Hinzugefügt
- Grundgerüst: schlanke PHP-8.2-App (Router, PDO, Session-Auth, CSRF)
- Automatischer Schema-Migrator (läuft bei jedem Request, DB-Lock gegen Races)
- Vollständiges Datenbankschema inkl. Bewertungslogik (Formular 06)
- WJD-Corporate-Design (Chivo/Bitter, Blau/Türkis) inkl. Logos
- Login, Dashboard mit Kennzahlen und Projekt-Timeline, Profil
- GitHub-Actions-Deploy: `config.local.php` aus Secrets + FTP-Upload

[Unreleased]: https://github.com/VierlingMt/uplus/compare/v0.19.0...HEAD
[0.19.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.19.0
[0.18.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.18.0
[0.17.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.17.0
[0.16.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.16.0
[0.15.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.15.0
[0.14.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.14.0
[0.13.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.13.0
[0.12.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.12.0
[0.11.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.11.0
[0.10.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.10.0
[0.9.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.9.0
[0.8.1]: https://github.com/VierlingMt/uplus/releases/tag/v0.8.1
[0.8.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.8.0
[0.7.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.7.0
[0.6.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.6.0
[0.5.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.5.0
[0.4.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.4.0
[0.3.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.3.0
[0.2.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.2.0
[0.1.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.1.0
