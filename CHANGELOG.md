# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

## [0.78.0] - 2026-07-18
### Hinzugefügt
- **Urheberrechts-/Lizenzhinweis:** Die App weist nun sichtbar auf die
  Rechtelage hin – dezent im Footer der Seitenleiste und auf der Anmeldeseite:
  „© 2025–… Martin Vierling · Alle Rechte vorbehalten" samt Namensnennung, dass
  Idee und Konzept auf dem **Erstwettbewerb 2023/24 von Jehona Ahmeti** beruhen.
  Das Jahr wird automatisch fortgeschrieben (zentraler Helper `copyright_notice()`).
- Neue Datei **`LICENSE`** (proprietär, „Alle Rechte vorbehalten") mit vollständigem
  Rechte-Text; Abschnitt **„Lizenz & Urheberrecht"** in der README ergänzt.
### Hinzugefügt
- **Kick-Off als eigenes Modul:** Neuer Bereich „Kick-Off" je Wettbewerbsjahr, in
  dem die **Terminschiene** (alle Meilensteine des Projektablaufs) abgestimmt und
  per **„Terminplan fixieren"** verbindlich festgehalten wird. Die Termine sind
  dieselben, die als Zeitleiste „Projektablauf" auf dem Dashboard erscheinen –
  hier im Kick-Off-Kontext gebündelt, samt Eckdaten und Protokoll. Pflege durch die
  Verwaltung; alle Beteiligten sehen den abgestimmten Plan.
- **Project-Closing als eigenes Modul (Abschluss-Retrospektive):** Jede beteiligte
  Person hält in drei Kategorien fest, **was gut lief, was schlecht lief und was
  sich verbessern lässt** (nur für die eigene Person und die Projektleitung
  sichtbar). Beim Abschlusstermin lässt die Verwaltung die gesammelten
  Rückmeldungen **per KI zu Themen clustern und zusammenfassen** – inklusive
  konkreter Verbesserungen fürs nächste Jahr – als Grundlage fürs gemeinsame
  Gespräch. Mit Eckdaten und Protokoll.
- Beide Module sind über die **Zugriffsmatrix** steuerbar und in die kontextbasierte
  Hilfe aufgenommen.

## [0.76.1] - 2026-07-16
### Geändert
- **Video-Vorschaubilder werden automatisch nachgezogen (ohne Knopf):** Beim
  Öffnen einer Galerie erzeugt die App im Hintergrund still die Poster für
  Videos, die noch keins haben, und tauscht den Platzhalter live gegen das
  Vorschaubild. Der Lauf erledigt sich von selbst (erledigte Videos tauchen nicht
  mehr auf). Der manuelle „Vorschauen erzeugen"-Knopf entfällt.

## [0.76.0] - 2026-07-16
### Behoben / Geändert
- **Mediengalerie lädt deutlich schneller (v. a. bei vielen Videos):** Im Raster
  wird kein `<video>`-Element mehr geladen. Stattdessen zeigt jede Video-Kachel
  ein **statisches Vorschaubild (Poster)** – oder, solange keins existiert, einen
  leichten Platzhalter. Damit entfallen die vielen parallelen Video-Metadaten-
  Abrufe, die das Öffnen der Galerie stark verlangsamt haben.
### Hinzugefügt
- **Video-Vorschaubilder (Poster):** Beim Hochladen eines Videos wird direkt im
  Browser ein Standbild aus einem Frame erzeugt und als Vorschau gespeichert (kein
  `ffmpeg` auf dem Server nötig). Beim Ansehen eines Videos in der Großansicht wird
  ein fehlendes Poster still nachgezogen.
- **„🎬 Video-Vorschauen erzeugen"-Knopf:** Zieht für **alle bestehenden Videos**
  ohne Vorschau die Poster **einmalig** nach – mit Fortschrittsanzeige. Danach
  lädt die Galerie auch mit vielen Videos schnell. `media_file` liefert Video-
  Poster als `v=thumb`/`v=view`.

## [0.75.5] - 2026-07-16
### Behoben
- **Dashboard „Partner & Sponsoren": nur echte Sponsoren.** Das fest
  eingebaute WJ-Forchheim-Logo wird nicht mehr angezeigt – die Leiste zeigt
  jetzt ausschließlich Sponsoren mit einer Leistung im aktiven Wettbewerbsjahr.

## [0.75.4] - 2026-07-16
### Hinzugefügt
- **Moderationskärtchen – Seitenzahl unten rechts:** Jede Karte zeigt jetzt unten
  rechts „Seite X / Y". Beim automatischen Umbruch auf Fortsetzungskarten wird die
  Seitenzahl korrekt fortgeführt.

## [0.75.3] - 2026-07-16
### Behoben
- **Moderationskärtchen – kein Scrollbalken mehr:** Inhaltsreiche Karten (z. B.
  „Nominierte Teams" mit vielen Teams und Mitgliedern) zeigten einen Scrollbalken,
  weil die Schrift fix zu groß war. Jetzt passt sich jede Karte automatisch an:
  Die **Schrift verkleinert sich** so weit, dass alles ohne Scrollbalken auf die
  Karte passt – und reicht das nicht, bricht der Rest automatisch auf eine
  **zweite Karte** („· Forts.") um; die Nummerierung (z. B. Pitch-Reihenfolge)
  läuft dort korrekt weiter. Die Anpassung gilt für Bildschirm, Vollbild und
  Druck/PDF gleichermaßen.

## [0.75.2] - 2026-07-16
### Behoben
- **Bestätigungsdialog „Aus Vorlage erstellen":** Beim Erstellen der
  Moderationskärtchen aus der Vorlage zeigte der Bestätigungsdialog
  fälschlich „Wirklich löschen?" mit rotem „Löschen"-Knopf. Der Dialog
  unterscheidet jetzt zwischen zerstörerischen Aktionen (weiterhin rot,
  „Löschen") und normalen Aktionen: „Aus Vorlage erstellen" fragt jetzt
  neutral „Aus Vorlage erstellen?" mit blauem Knopf „Karten erstellen".

## [0.75.1] - 2026-07-16
### Behoben
- **Bild-Ablage – Logo/Platzhalter überlagerten sich:** Beim Bearbeiten (z. B. eines
  Sponsors mit Logo) blieb der Platzhaltertext „Bild hierher ziehen …" sichtbar und
  das Logo lief aus der Ablage heraus (mit horizontalem Scrollbalken). Ursache war,
  dass die CSS-Klasse mit eigenem `display:flex` die Browser-Regel `[hidden]`
  überstimmte, sodass ausgeblendete Elemente doch angezeigt wurden. Das `hidden`-
  Attribut greift jetzt zuverlässig – **in allen Bildfeldern** (Sponsoren, Schulen,
  Jury, Teams, Materialien, Profilfoto, Galerie …).

## [0.75.0] - 2026-07-16
### Hinzugefügt
- **Moderationskärtchen für den PitchDay:** Ein neues Modul „Moderationskärtchen"
  (Verwaltung) für die Moderation des Pitch Days – ähnlich der Präsentation, aber im
  Format **DIN A5 quer** als Handout-/Spickkarten. Die Projektleitung blättert am
  Rednerpult digital durch die Karten (inkl. **Vollbild**, Pfeiltasten ← →, <kbd>F</kbd>)
  oder druckt sie über **„Als PDF (A5 quer)"** – eine Karte je Seite.
  - **Freie Textkarten:** beliebig viele eigene Karten anlegen, Titel/Untertitel/Text
    (schlankes Markdown) ändern, per ↑/↓ umsortieren und wieder löschen.
  - **Bausteinkarten mit Live-Daten:** feste Bausteine ziehen ihren Inhalt automatisch
    aus dem System und müssen nicht mehr abgetippt werden – **Ehrengäste**, **Grußworte
    & Keynote**, **Jury**, **Ablauf/Zeitplan**, **nominierte Teams**, **Preise** sowie
    **Zahlen & Fakten** (Schulen, Schüler:innen, Teams, Jury, Nominierte). Zu jeder
    Bausteinkarte lässt sich zusätzlich eine eigene Moderations-Notiz setzen.
  - **Vorlage:** „Aus Vorlage erstellen" spielt den kompletten, bewährten
    Moderationsablauf (Begrüßung, Dank, Über das Projekt, Ablauf, Grußworte, Jury,
    Teams, Preisverleihung, Ausklang …) je Wettbewerbsjahr ein – danach frei anpassbar.

## [0.74.0] - 2026-07-16
### Hinzugefügt
- **Sponsoren – Notizfeld für Absprachen:** Im Sponsor-Formular gibt es jetzt ein
  Feld „Notizen / Absprachen". **Beim Klick ins Feld** wird automatisch eine neue
  Zeile mit **Datum und Name** vorangestellt (z. B. `16.07.2026 Martin Vierling: `),
  sodass Absprachen datiert und zurechenbar festgehalten werden. Die Notizen werden
  in der Sponsor-Detailansicht angezeigt.

### Geändert
- **Modals schließen nicht mehr bei Klick daneben:** Ein Klick auf den abgedunkelten
  Hintergrund schließt Dialoge (Formulare, Bestätigungen, PDF- und Zuschnitt-Ansicht)
  bewusst **nicht** mehr – so gehen Eingaben nicht durch einen versehentlichen Klick
  verloren. Schließen weiterhin über **ESC**, das **×** oder **„Abbrechen/Schließen"**.

## [0.73.2] - 2026-07-16
### Behoben
- **Präsentation – Vollbild nutzt die Fläche jetzt voll:** Im Vollbild blieb die
  Schrift zu klein (feste Obergrenze), sodass unten viel leerer Platz blieb. Die
  Schrift skaliert jetzt **ohne Obergrenze proportional** mit der Folie (klein auf
  dem Handy, groß im Vollbild), und die Inhaltsfolien sind **vertikal zentriert** –
  so wird die Folienfläche in jeder Größe sauber ausgenutzt, ohne dass etwas
  abgeschnitten wird.

## [0.73.1] - 2026-07-16
### Behoben
- **Präsentation – Folien auf schmalen Displays abgeschnitten:** Auf dem Handy
  wurden Folien mit viel Inhalt (z. B. „Projektablauf" oder die Titelfolie mit
  Sponsoren) unten abgeschnitten. Die Folie skaliert jetzt **proportional mit
  ihrer Breite** (Container-Query-Einheit als Bezug, niedrigere Schrift-Untergrenze)
  und passt dadurch in jeder Größe sauber ins 16:9-Format – nichts wird mehr
  abgeschnitten.

## [0.73.0] - 2026-07-16
### Geändert
- **Social-Media-Links jetzt zentral im Admin:** Die Links (Web, Instagram,
  Facebook, LinkedIn, YouTube) werden unter **Admin → Social Media** gepflegt
  (neuer `Social`-Helfer, Settings `social_*`) und sind dadurch app-weit
  wiederverwendbar. Sie erscheinen weiterhin automatisch auf der Titelfolie der
  Präsentation.
- **Präsentation – Titelfolie nach WJ-CI:** Logo-Anordnung gemäß WJ-Design-Guide –
  **WJ-Wort-Bildmarke oben links, Projektlogo (Unternehmen Plus) oben rechts**. Der
  **Sponsoren-Streifen** sitzt jetzt auf der **Titelfolie** (statt unter „Unser
  Team").
### Behoben
- **Präsentation – Pitch-Day-Folie:** Der beschreibende **Text (links) fehlte** und
  ließ sich nicht bearbeiten, weil die Folie nicht als Textfolie geführt war. Die
  Folie ist jetzt pflegbar (Text links, dynamische Preise rechts) und zeigt den
  hinterlegten Text.

## [0.72.0] - 2026-07-15
### Geändert
- **Mediengalerie – Teilen-Links jetzt bis zu 2× nutzbar mit Info-Seite:** Ein
  geteilter Link kann nun **zweimal** heruntergeladen werden (statt nur einmal)
  und läuft weiterhin nach 7 Tagen ab. Wer den Link öffnet, sieht zuerst eine
  **Info-Seite** (ohne Anmeldung) mit Anzahl der Medien, Größe, Gültigkeit und
  **verbleibenden Downloads** und startet den Download per Knopf. Nach dem
  letzten erlaubten Download löscht sich der Link automatisch. Neue Spalte
  `media_shares.max_downloads` (Standard 2).

## [0.71.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie – teilbare, temporäre Download-Links:** Über „🔗 Teilen-Link"
  entsteht ein **öffentlicher Link** (ohne Anmeldung nutzbar), mit dem man Medien
  weitergeben kann. Der Link läuft **nach 7 Tagen ab** und **löscht sich nach dem
  ersten vollständigen Download** automatisch. Der Link wird in einem Dialog
  angezeigt und lässt sich mit einem Klick kopieren. Abgelaufene Links werden bei
  jedem Zugriff aufgeräumt. Neue Datei: `app/pages/share.php` (öffentliche Route
  `share`); Schema: neue Tabelle `media_shares`.
- **Mediengalerie – Download & Teilen der Auswahl:** In der Mehrfachauswahl gibt
  es jetzt zusätzlich **„⬇ Herunterladen"** (ausgewählte Medien als ZIP) und
  **„🔗 Teilen-Link"** (nur die Auswahl teilen). Zum Auswählen lässt sich nun jede
  Kachel markieren (nicht nur eigene); Löschen bleibt auf eigene Beiträge bzw.
  die Verwaltung beschränkt.
- **Thumbnails weiterhin sofort beim Upload** erzeugt (klassischer Upload **und**
  Chunk-Upload) – bestehende Bilder werden zusätzlich beim ersten Ansehen
  nachgezogen.

## [0.70.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie – Ansichtsumschalter (Gruppierung):** Über einen Umschalter
  in der Werkzeugleiste lässt sich die Galerie als **Raster** anzeigen oder
  **nach Person (Uploader)** bzw. **nach Monat** (Aufnahmedatum) gruppieren –
  jede Gruppe mit eigener Überschrift und Anzahl. Die Auswahl bleibt beim
  Jahreswechsel erhalten.
- **Mediengalerie – ganze Galerie herunterladen:** Ein Knopf „Galerie
  herunterladen" packt **alle Medien eines Jahres als ZIP** (Originalgröße). Die
  Dateien bekommen sprechende Namen (`JJJJ-MM-TT_Person_Original`); bereits
  komprimierte Medien werden ohne erneute Kompression gepackt (schnell,
  speicherschonend). Neue Datei: `app/pages/media_zip.php`.
- **Mediengalerie – paralleler Upload:** Mehrere Dateien werden jetzt
  **gleichzeitig** hochgeladen (bis zu 3 auf einmal) statt streng nacheinander –
  besonders bei vielen kleineren Bildern spürbar schneller. Die Stücke einer
  einzelnen Datei bleiben weiterhin in Reihenfolge.

## [0.69.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie – Vorschaubilder für schnelles Laden:** Zu jedem Bild werden
  serverseitig zwei verkleinerte Varianten erzeugt – ein **Thumbnail** für die
  Kacheln und eine **Ansicht** für die Großansicht (Lightbox). Statt des vollen
  Originals (oft mehrere MB) lädt die Galerie so nur wenige KB je Bild, was das
  Öffnen drastisch beschleunigt. Die Handy-Ausrichtung (EXIF) wird dabei
  korrigiert, WEBP mit JPEG-Fallback genutzt. Varianten entstehen beim Hochladen
  und werden bei bestehenden Bildern beim ersten Ansehen automatisch nachgezogen
  (auf der Platte gecacht).
- **Original herunterladen:** In der Großansicht gibt es einen **Download-Knopf**,
  der stets das **Original in voller Größe** liefert. Videos werden weiterhin per
  HTTP-Range gestreamt (nur die benötigten Abschnitte).

## [0.68.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie – Sortierung nach Aufnahmedatum:** Bilder und Videos werden
  jetzt nach ihrem **Aufnahmezeitpunkt** einsortiert (neueste zuerst), nicht
  mehr nach der Upload-Zeit. Das Datum wird beim Hochladen aus den Metadaten
  gelesen – Fotos über **EXIF** (`DateTimeOriginal`), MP4/MOV-Videos über das
  **mvhd-Atom** – und als `taken_at` gespeichert; fehlt es, greift als Fallback
  die Upload-Zeit. Das Datum erscheint zusätzlich an jeder Kachel und in der
  Großansicht. Bestehende Medien werden per Migration einmalig nachgezogen.

## [0.67.1] - 2026-07-15
### Behoben
- **Hilfe- und Tour-Buttons reagierten nicht:** Auf Seiten mit Karten ohne
  Überschrift brach das Sammeln der Tour-Schritte mit einem JavaScript-Fehler ab
  (`textContent` auf `null`), sodass weder das Hilfe-Panel (F1 / „?") noch die
  Tour öffneten. Die Titel-Ermittlung ist jetzt null-sicher; solche Karten
  bekommen automatisch den Fallback-Titel „Bereich".

## [0.67.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie – Upload großer Videos (bis 2 GB) per Chunk-Upload:** Große
  Dateien werden im Browser automatisch in kleine Stücke (5 MB) zerlegt und
  einzeln übertragen. Damit umgehen wir die PHP-Request-Limits
  (`post_max_size`/`upload_max_filesize`), die einen 2-GB-Upload sonst verhindern.
  Der Server hängt die Stücke an eine Temp-Datei an und finalisiert am Ende
  (Validierung, Verschieben, DB-Eintrag). Mit **Fortschrittsanzeige je Datei**,
  automatischer **Wiederaufnahme** bei kurzen Aussetzern und Bereinigung
  verwaister Reste. Ohne passende Browser-Funktionen bleibt der klassische
  Direkt-Upload als Rückfallebene. Neue Datei: `app/pages/media_chunk.php`.

  Hinweis: Die Temp-Datei belegt während des Uploads einmalig den vollen
  Speicherplatz der Datei – bei sehr großen Videos auf ausreichendes Kontingent
  des Webspace achten.

## [0.66.2] - 2026-07-15
### Behoben
- **Mediengalerie – Lightbox lag beim Laden offen über der Seite:** Eine
  CSS-Regel übersteuerte das `hidden`-Attribut; die Bild-/Video-Vollansicht
  (und die Mehrfachauswahl-Leiste) erscheinen nun erst bei Bedarf.
- **Mediengalerie – „CSRF"-Fehler beim Mehrfach-Upload:** Übersteigt die
  Auswahl das Server-Limit (`post_max_size`), verwirft PHP den ganzen Request –
  die Prüfung meldete dann irreführend „Sitzung abgelaufen". Jetzt gibt es eine
  verständliche Meldung, eine **clientseitige Vorwarnung** bei zu großer Auswahl
  und **höhere Upload-Limits** (`upload_max_filesize` 64 MB, `post_max_size`
  320 MB, `max_file_uploads` 40) für mehrere Bilder/Videos auf einmal.

## [0.66.1] - 2026-07-15
### Geändert
- **Urkunde – Unterschriften:** Es unterschreibt jetzt ausschließlich die
  **aktuelle Projektleitung (Rolle „lead")**. Das Admin-/Super-Admin-Konto
  (technische Rolle) erscheint bewusst nicht mehr – dadurch keine doppelte
  Unterschrift derselben Person. Zusätzliche Entdopplung nach Namen als
  Sicherheitsnetz.

## [0.66.0] - 2026-07-15
### Hinzugefügt
- **Mediengalerie (neuer Bereich „Mediengalerie"):** Bilder und Videos je
  **Wettbewerbsjahr**. **Alle angemeldeten Nutzer:innen** dürfen für ihr
  Wettbewerbsjahr hochladen – mit **Mehrfachauswahl** (mehrere Dateien auf
  einmal, per Auswahl-Dialog oder Drag-&-Drop). **Eigene Beiträge** lassen sich
  bearbeiten (Titel/Bildunterschrift) und löschen; **Projektleitung und Admin**
  verwalten **alle** Beiträge. **Jede:r sieht alle Galerien** – über eine
  Jahres-Auswahl wechselt man zwischen den Wettbewerbsjahren.
- **Schöne Galerie mit Lightbox:** Responsives Kachel-Raster mit
  Bild-Vorschauen und Video-Thumbnails; ein Klick öffnet die **Lightbox**
  (Vollbild, Vor/Zurück per Pfeiltasten, Videos mit Steuerung). Zusätzlich eine
  **Mehrfachauswahl zum Löschen** mehrerer eigener Medien auf einmal.
- **Technik:** Dateien liegen außerhalb des Web-Roots und werden ausschließlich
  über den Controller `media_file` mit Auth-Prüfung ausgeliefert; Videos mit
  **HTTP-Range-Unterstützung** (flüssiges Abspielen/Spulen). Erlaubt sind
  Bilder (JPG, PNG, GIF, WEBP) und Videos (MP4, WEBM, MOV) bis 32 MB je Datei
  (Bilder werden serverseitig als echtes Bild validiert). Neue Dateien:
  `app/lib/Media.php`, `app/pages/gallery.php`, `app/pages/media_file.php`;
  neues, konfigurierbares Modul „gallery" in der Zugriffsmatrix (Standard: alle
  Rollen dürfen ansehen und hochladen). Schema: neue Tabelle `media_items`.

## [0.65.0] - 2026-07-15
### Hinzugefügt
- **Interaktive Hilfe (F1) & geführte Tour:** Über **F1** (oder das **?**-Symbol
  oben rechts) öffnet sich ein kontextbasiertes Hilfe-Panel, das immer zuerst die
  Themen zur **aktuellen Seite** zeigt. Eine **Tokensuche** durchsucht alle
  Hilfetexte gleichzeitig (mehrere Begriffe) und **hebt die Fundstellen gelb
  hervor**. Mit **„Tour starten"** (Knopf in der Topbar bzw. im Hilfe-Panel) wird
  man Schritt für Schritt durch die **gerade sichtbaren** Inhalte der Seite
  geführt und bekommt jeden Bereich per Spotlight erklärt (Weiter/Zurück,
  Pfeiltasten, Esc beendet).
- **Wichtige Grundlage – hält sich selbst aktuell:** Hilfe und Tour sind bewusst
  *datengetrieben*, nicht als separate Kopie der Oberfläche gepflegt. Die Tour
  entsteht bei jedem Start **live aus dem DOM**: Jeder Baustein bringt seine
  Erklärung über `data-tour` (Helfer `tour_attrs()`) selbst mit, und sichtbare
  Bereiche (Karten) werden zusätzlich **automatisch** als Schritt erkannt. Die
  Routen-Beschriftungen der Hilfe stammen direkt aus der Navigation. Ändert sich
  die App – neuer Bereich, neues Modul, andere Rolle –, aktualisieren sich Hilfe
  und Tour **automatisch mit** (neue Dateien: `app/lib/Help.php`,
  `assets/js/help.js`, `assets/css/help.css`).
## [0.64.0] - 2026-07-15
### Hinzugefügt
- **Präsentation – Titelfolie mit WJ-Logo & Social-Media:** Auf der Startfolie
  erscheint jetzt neben dem Unternehmen-Plus-Logo das **WJ-Forchheim-Logo** (Vektor/
  SVG, in Farbe für helle sowie Weiß für dunkle Hintergründe unter
  `assets/img/wj/`). Darunter eine **Social-Media-Leiste**; die Links (Web,
  Instagram, Facebook, LinkedIn, YouTube) sind über „Bearbeiten" auf der Titelfolie
  **pflegbar** (global, jahresunabhängig) und werden nur angezeigt, wenn hinterlegt.
- **Präsentation – Seitenzahlen:** Jede Folie trägt unten rechts klein die
  Seitenzahl („n / gesamt") – in der App-Ansicht und im PDF.
### Geändert
- **Präsentation – „Unser Team" mit Kontaktdaten:** Die Team-/Projektleitungsfolie
  zeigt jetzt je Person zusätzlich **Telefon und E-Mail** (live aus „Jury &
  Nutzer") sowie den Sponsoren-Streifen. Die separate **Folie „Kontakt" entfällt**
  dadurch.
- **Präsentation – Pitch-Day-Folie:** Klar gegliedert in **Text links** und die
  **dynamischen Preise rechts** (aus dem PitchDay-Budget).

## [0.63.3] - 2026-07-15
### Geändert
- **„Bewertung & Ranking" und „PitchDay" für Handys optimiert:** Beide Listen
  erscheinen auf schmalen Displays jetzt als kompakte, dichte Liste (eine
  Textzeile „Platz · Team · Aktion", darunter klein die Kennzahlen) statt einer
  bildschirmfüllenden Karte je Team. So sind mehrere Teams gleichzeitig sichtbar.
### Behoben
- **Falsches „bewertet" im PitchDay:** Bislang wurde eine Pitch-Bewertung schon
  beim bloßen Öffnen/Autospeichern eines nominierten Teams als abgegeben markiert
  (auch wenn nur der Businessplan ausgefüllt war). „Pitch bewertet" gilt jetzt erst,
  wenn tatsächlich alle Pitch-Kriterien Punkte haben. Der Button zeigt den
  Fortschritt an (`Bewerten` → `Weiter · 2/4` → `✓ bewertet`). Nebenbei bleibt der
  Ø Pitch sauber (keine 0er von noch nicht bewerteten Pitches). Bestehende, falsch
  gesetzte Markierungen werden per Migration korrigiert.
### Hinzugefügt
- **PitchDay – sofort sehen, was noch fehlt:** Wer selbst bewertet, sieht oben einen
  Hinweis „Du musst noch X von Y Bühnen-Teams bewerten"; die offenen Teams sind in
  der Liste links farbig markiert. Für die Projektleitung gibt es – wie bei
  „Bewertung & Ranking" – einen aufklappbaren **Bewertungsstand (Pitch)**: welche:r
  Bewertende hat welche Bühnen-Teams beim Pitch noch nicht (vollständig) bewertet.
## [0.63.2] - 2026-07-15
### Geändert
- **PitchDay-Urkunde – Feinschliff:** Kopf- und Fußzeilen-Logos überschneiden den
  türkisen Rahmen nicht mehr (größerer Innenabstand). Unterschriften und Namen
  kleiner, sodass auch drei Unterzeichnende sauber nebeneinander passen.
  Sponsoren-Logos in der Fußzeile jetzt **farbig**.

## [0.63.1] - 2026-07-15
### Geändert
- **PitchDay-Urkunde an die echte Vorlage angepasst:** Die Urkunde bildet jetzt
  die aktuelle „Unternehmen Plus"-Vorlage nach (statt der alten W³-Vorlage):
  U⁺-Logo, „Businessplanwettbewerb UnternehmenPlus", „URKUNDE" + „Schuljahr",
  großes „__. Platz" (Zahl per Hand), vier beschriftete Linien (Titel des
  Businessplans, Teammitglieder ×2, Schule – automatisch befüllt), „Die
  Wirtschaftsjunioren gratulieren!", Ausstellungsort + PitchDay-Datum, zwei
  Unterschriften der Projektleitung/des Vorstands (mit Funktion) sowie die
  Fußzeile **Veranstalter · Sponsoren · Teilnehmende Schulen** mit den
  jeweiligen Logos.

## [0.63.0] - 2026-07-15
### Hinzugefügt
- **Projektpräsentation in der App:** Neuer Menüpunkt **„Präsentation"** (für alle
  sichtbar). Bildet die WJ-Foliensammlung zum Businessplanwettbewerb ab und lässt
  sich als Deck durchblättern (Pfeiltasten ← →, Punkte-Navigation) sowie im
  **Vollbild präsentieren** (Taste `F`). Über **„Als PDF / Drucken"** entsteht die
  komplette Präsentation als PDF (A4 quer, eine Folie je Seite) – ganz ohne
  zusätzliche PDF-Bibliothek, per Browser-Druck „Als PDF speichern".
- **Dynamische Folien je Wettbewerbsjahr:** Titel/Jahr, **Projektablauf**
  (Meilensteine), **Preise** (aus dem PitchDay-Budget), **Unser Team**
  (Projektleitung), **Kontakt** und **Sponsoren** füllen sich automatisch aus den
  bereits in der App gepflegten Daten des gewählten Jahres. Bei mehreren Jahren
  ist das Jahr oben wählbar.
- **Pflegbare Textfolien:** Die wiederkehrenden Beschreibungstexte (Einleitung,
  Herausforderungen, Ablaufphasen, KI-Hinweis …) pflegt die Verwaltung direkt in
  der Präsentation per **„Bearbeiten"** (einfaches Markdown). Die Texte sind je
  Wettbewerbsjahr überschreibbar; eine **globale Vorlage** dient als Rückfallebene,
  sodass ein neues Jahr die Texte des Vorjahres erbt. Angepasste Folien lassen sich
  auf die Vorlage zurücksetzen.
- **Zugriffsmatrix:** Das Modul **„Präsentation"** ist aufgenommen – ansehen dürfen
  alle Rollen, pflegen die Verwaltung (Admin/Projektleitung).
## [0.62.0] - 2026-07-15
### Hinzugefügt
- **PitchDay-Aushänge & Urkunden (automatisch erzeugt):** Neuer Tab „Aushänge &
  Urkunden" in der PitchDay-Orga (nur Verwaltung). Alle Vorlagen werden aus den in
  der App gepflegten Daten zusammengesetzt und in den offiziellen WJ-CI-Farben als
  eigene Druckseite („Als PDF speichern") ausgegeben – ohne zusätzliche
  PDF-Bibliothek:
  - **DIN-A3-Aushang „Pitch-Day"** – mit Veranstaltungsort, Datum/Uhrzeit und
    Sponsoren-Logostreifen.
  - **DIN-A3-Aushang mit Agenda** – aus dem gepflegten Ablaufplan.
  - **DIN-A4-Wegpfeil** – Richtung (↑ → ↓ ←) und Beschriftung auf der Druckseite
    umstellbar.
  - **DIN-A4-Urkunden für alle nominierten Teams + Nachrücker** – je Team eine
    Seite mit Geschäftsidee, Teammitgliedern, Schule (inkl. Logo), Sponsoren,
    PitchDay-Datum und Pseudo-Unterschrift der Projektleitung. Nur die
    Platzierung („__. Platz") wird am Veranstaltungstag per Hand ergänzt.

## [0.61.0] - 2026-07-13
### Hinzugefügt
- **Eigene PitchDay-Fragen je Jurymitglied:** In der Bewertung kann jede:r Juror:in
  zwischen Businessplan- und Pitch-Bewertung eigene Fragen notieren, die am PitchDay
  gestellt werden sollen. Die Notiz ist privat (nur für die/den Juror:in), je Team,
  und wird automatisch gespeichert. Erscheint bei Teams mit Pitch-Phase.

## [0.60.1] - 2026-07-13
### Geändert
- **Zugriffsmatrix, Bewerten:** „Schreiben" bei **Bewertung & Ranking** bzw.
  **PitchDay** bedeutet jetzt eindeutig „die Rolle darf bewerten". Die Jury steht
  dafür standardmäßig auf **Schreiben** (kann also bewerten); auf „Lesen" gesetzt,
  ist die Bewerten-Maske schreibgeschützt. Die Leitungs-Aktionen (Runden
  einfrieren, Endergebnis) und die Freeze-Sperren bleiben davon unberührt.

## [0.60.0] - 2026-07-13
### Hinzugefügt
- **Ablaufplan/Handout freigeben:** In der PitchDay-Orga (Tab „Ablauf") lässt sich
  der Ablaufplan per Klick **„Für alle freigeben"** (bzw. zurückziehen). Sobald
  freigegeben, erscheint auf dem **Dashboard aller Beteiligten** eine Kachel
  „PitchDay – Ablaufplan & Handout" zum PDF-Download. Vor der Freigabe bleibt das
  Handout nur für die Verwaltung sichtbar (die es auf dem Dashboard als Vorschau
  sieht). Die Reserviert-Schilder bleiben in jedem Fall der Verwaltung vorbehalten.

## [0.59.0] - 2026-07-13
### Hinzugefügt
- **Zugriffsmatrix (nur Admin):** Neuer Menüpunkt „Zugriffsmatrix" (Verwaltung),
  in dem der Admin je **Modul** und **Rolle** die Stufe **Kein Zugriff / Lesen /
  Schreiben** festlegt. Die Einstellung steuert Menüsichtbarkeit, Seitenzugriff
  und Schreibaktionen. Der Admin hat immer vollen Zugriff (nicht sperrbar), das
  Dashboard bleibt für alle mindestens lesbar. Die Standardwerte entsprechen exakt
  dem bisherigen Verhalten – ohne Änderung ändert sich nichts.
- **Jury-Regel:** Juror:innen sehen unter „Jury & Nutzer" ausschließlich Personen
  aus ihren eigenen Wettbewerbsjahrgängen (technische Admin-Konten ausgeblendet) –
  unabhängig von der Matrix.
### Geändert
- Menü, Dashboard-Kacheln und die Modul-Guards (Schulen, Teams, Jury & Nutzer,
  Wettbewerbsjahre, PitchDay-Orga, Sponsoren, Audit, Material, Businesspläne,
  Bewertung, PitchDay, Jury-Feedback, Admin) prüfen den Zugriff jetzt über die
  zentrale Zugriffsmatrix statt fest verdrahteter Rollenlisten.

## [0.58.0] - 2026-07-13
### Hinzugefügt
- **Jury erhält Nur-Lese-Zugriff auf Schulen, Teams & Schüler sowie Jury & Nutzer.**
  Die drei Bereiche sind für Juror:innen im Menü und über die Dashboard-Kacheln
  erreichbar und zeigen alle Inhalte – jedoch ohne Anlegen/Bearbeiten/Löschen
  (keine Buttons/Formulare, schreibende Aktionen serverseitig geblockt). Verwaltung
  (Admin/Projektleitung) und Lehrkräfte behalten ihre vollen Rechte unverändert.

## [0.57.4] - 2026-07-13
### Behoben
- **Dashboard-Kacheln verlinken nur noch auf erreichbare Bereiche.** Kennzahl-Kacheln
  (Schulen, Teams, Juror:innen), deren Zielmodul der aktuellen Rolle nicht offensteht,
  werden als reine Info-Kachel ohne Link angezeigt – kein toter Klick mehr auf die
  Seite „Kein Zugriff". Jury sieht z. B. nur „Eingereichte Pläne" verlinkt.

## [0.57.3] - 2026-07-13
### Hinzugefügt
- **Budget-PDFs: digitale „Pseudo-Unterschriften" der Projektleitung** – für alle
  aktuellen Projektleiter:innen (Rolle „lead") erscheint der Name in Schreibschrift
  über einer Linie mit gedrucktem Namen und Position. Dazu ein Hinweis, dass das
  Dokument automatisch erzeugt wurde und auch ohne handschriftliche Unterschrift
  gültig ist. Ersetzt die bisherigen leeren Unterschriftsfelder.

## [0.57.2] - 2026-07-13
### Hinzugefügt
- **Budget-PDFs: Abschnitt „Teilnehmende Schulen"** – live je Wettbewerbsjahr die
  teilnehmenden Schulen mit Anzahl der Teams und Schüler:innen (samt Summenzeile).
  Schulen aus der Zyklus-Zuordnung, Zahlen live aus den erfassten Teams.
### Geändert
- **Budget-PDFs: Fußzeile** zeigt links unten – klein und anthrazit – erst die
  Seitenzahl im Format „Seite 1 von 3" und auf gleicher Höhe den Dokumenttitel.
  A4-Format bleibt gesetzt.

## [0.57.1] - 2026-07-13
### Hinzugefügt
- **Kurzer Projektabschnitt „Über das Projekt" in den Budget-PDFs**: beschreibt
  „Unternehmen Plus" knapp und benennt Wirkung und Mehrwert für Schülerinnen und
  Schüler sowie für die Region – als Kontext für Zuwendungsgeber.

## [0.57.0] - 2026-07-13
### Hinzugefügt
- **Zwei druckfertige Budget-Übersichten** im Menüpunkt **PitchDay → Budget**:
  „Ausgaben-Übersicht" (Kosten + Preisgelder mit Summen) und
  „Ausgaben-/Einnahmen-Übersicht" (zusätzlich die Sponsoren-Einnahmen und der
  Saldo). Beide öffnen eine sauber formatierte A4-Seite mit Kopf, Eckdaten,
  Summen sowie Datums- und Unterschriftszeile und lassen sich per „Als PDF
  speichern" drucken – gedacht als Nachweis gegenüber Zuwendungsgebern.

## [0.56.0] - 2026-07-12
### Geändert
- **Deutlich kompaktere Mobil-Ansicht** für PitchDay und Bewertung & Ranking: Jede
  Team-Zeile wird zu einer dichten Karte (Platz + Team in einer Kopfzeile, Schule
  darunter, Kennzahlen als kompakte Chips, schlanker Bewerten-Button) statt jeder
  Wert in einer eigenen Zeile – spart auf dem Handy sehr viel Höhe.
- **Aufräumen der Bewertungsansicht:** Die „Finale Platzierung" erscheint jetzt
  ausschließlich im Menüpunkt **PitchDay**, nicht mehr unter „Bewertung & Ranking".
- **„Endergebnis einfrieren" nur noch im Menüpunkt PitchDay** (dort, wo die
  Platzierung entsteht). Unter „Bewertung & Ranking" bleibt nur das Einfrieren der
  Businessplan-Runde.

## [0.55.0] - 2026-07-12
### Hinzugefügt
- **Businessplan-Bewertungsrunde separat einfrierbar** (vor dem PitchDay). Über
  „🔒 BP-Runde einfrieren" (Bewertung & Ranking) werden die Businessplan-Punkte
  festgeschrieben – die Jury kann sie nicht mehr ändern, während die
  Pitch-Bewertung am Veranstaltungstag weiterhin möglich bleibt. Von der
  Verwaltung jederzeit wieder freizugeben. Unabhängig vom finalen
  „Endergebnis einfrieren" (nach dem Pitch, mit 15-Minuten-Notausstieg).
### Geändert
- Bewertungsformular sperrt jetzt phasengenau: bei eingefrorener BP-Runde sind nur
  die Businessplan-Kriterien schreibgeschützt, die Pitch-Kriterien bleiben
  editierbar (bereits gespeicherte BP-Punkte werden dabei zuverlässig erhalten).
- Der bisherige Freeze heißt zur Abgrenzung nun **„Endergebnis einfrieren"**.

## [0.54.0] - 2026-07-12
### Hinzugefügt
- **Neuer Menüpunkt „PitchDay" (Jury).** Schlanke, fokussierte Seite nur mit den
  Pitch-Teams: Mini-Ranking der nominierten Teams (Ø Businessplan, Ø Pitch,
  Gesamt) mit Platz 1–X und Podest 🥇🥈🥉, Bühnenreihenfolge-Pill und direktem
  „Bewerten"-Button je Team, darunter die Nachrücker. Sauber getrennt von
  „Bewertung & Ranking" (dort weiterhin alle Teams) – ideal zum Abarbeiten am
  Veranstaltungstag. Respektiert das Einfrieren (dann nur „Ansehen").
### Geändert
- Der Verwaltungs-Menüpunkt „PitchDay" (Orga: Gäste, Ablauf, Budget) heißt jetzt
  **„PitchDay-Orga"**, um ihn klar von der neuen Jury-Seite abzugrenzen.

## [0.53.1] - 2026-07-12
### Geändert
- **Freigeben nur noch als 15-Minuten-Notausstieg.** Ein eingefrorenes Ranking
  lässt sich nur innerhalb von 15 Minuten nach dem Einfrieren wieder freigeben
  (für versehentliches Einfrieren); danach bleibt es endgültig festgeschrieben.
  Der Freigeben-Button zeigt die Restzeit an und verschwindet nach Ablauf.
  Einfrieren/Freigeben bleibt Admin bzw. Projektleitung vorbehalten.

## [0.53.0] - 2026-07-12
### Hinzugefügt
- **Finale Platzierung der Pitch-Teams.** Neue Karte „🏆 Finale Platzierung" im
  Ranking: alle nominierten (auf der Bühne stehenden) Teams werden nach der
  Gesamtwertung (2 × Businessplan + 1 × Pitch) auf **Platz 1 bis X** gesetzt –
  Podest mit 🥇🥈🥉. Solange noch Pitch-Bewertungen fehlen, weist ein Hinweis auf
  den vorläufigen Stand hin.
- **Filter „Nur Pitch-Teams"** in der Ranking-Tabelle, um während des Pitch-Days
  gezielt nur die Bühnenteams zu sehen.
- **Bewertung einfrieren.** Verwaltung kann das Ranking per „🔒 Einfrieren"
  festschreiben: Die Jury kann ihre Bewertungen dann nicht mehr ändern
  (schreibgeschützt inkl. Hinweisbanner). Nur Admin/Projektleitung können noch
  korrigieren oder per „🔓 Freigeben" wieder öffnen.
### Geändert
- **Handout: Anschrift des Veranstaltungsorts** wird als eigene Zeile „Anschrift"
  unter dem Ort ausgegeben.

## [0.52.0] - 2026-07-12
### Geändert
- **Handout folgt jetzt dem Ablauf der Moderationskärtchen.** Die Infos sind nach
  dem Veranstaltungsablauf sinnvoll gruppiert: erst „Wer & Was" (Veranstaltungs-
  infos, Projekt, Ehrengäste, Lehrkräfte, Presse, Sponsoren), dann der
  Programmablauf (Ablauf/Organisatorisches, **Grußworte & Keynote vor** den
  nominierten Teams, Jury, Aufgaben & Bewertung, Nominierte Teams, Preise) und zum
  Schluss „Fragen & Kontakt".
### Hinzugefügt
- **Jury-Bewertungskriterien im Handout** (Businessplan & Pitch) unter „Aufgaben &
  Bewertung der Jury".
- **Sparkasse als „offizieller Bildungssponsor"** wird im Sponsorenblock
  hervorgehoben.
- **Hinweis zu Buffet/Getränken & Toiletten** im Ablauf-/Organisationsteil.

## [0.51.1] - 2026-07-12
### Geändert
- **Bühnen-Reihenfolge der Pitches ist jetzt zufällig** (nicht die Punktereihenfolge).
  Beim automatischen Nominieren wird die `pitch_order` gelost – so kann man die
  Teams stumpf der Reihe nach aufrufen, ohne die Platzierung zu verraten.
- **Handout „Nominierte Teams" zeigt die Jury-Punkte** (Jury-Ø Businessplan, max. 50,
  nur Jury – kein KI) und erklärt in einer Unterüberschrift die zufällige
  Reihenfolge sowie die faire Verteilung je Schule.

## [0.51.0] - 2026-07-12
### Hinzugefügt
- **Faire Pitch-Verteilung je Schule.** Damit keine Schule leer ausgeht, verteilt
  die automatische Nominierung die Pitch-Plätze jetzt gleichmäßig auf die Schulen;
  überzählige Plätze gehen an die Schule(n) mit den besten Businessplänen (z. B.
  bei 7 Plätzen: beste Schule 3, die anderen je 2). **Nachrücker je Schule**
  (Standard 2). Neu einstellbar unter **Admin** (Schalter „Faire Verteilung je
  Schule" + „Nachrücker je Schule"); ohne Schalter bleibt die klassische globale
  Top-Liste. Die Pitch-Reihenfolge richtet sich weiterhin nach der Gesamtwertung.

## [0.50.3] - 2026-07-12
### Hinzugefügt
- **Handout: Übersicht der nominierten Teams inkl. Nachrücker.** Neue Sektion
  „Nominierte Teams (Pitches)" mit Geschäftsidee, Team- und Schulname sowie
  Teammitgliedern (in Pitch-Reihenfolge), plus separater Liste der **Nachrücker**.

## [0.50.2] - 2026-07-12
### Hinzugefügt
- **Dashboard: Klick auf den „Pitch Day"-Meilenstein öffnet das Handout-PDF**
  (neuer Tab). Sichtbar/klickbar für die Verwaltung, sobald ein PitchDay angelegt
  ist (kleines 📄-Symbol als Hinweis).

## [0.50.1] - 2026-07-12
### Geändert
- **Menü Jury:** „Jury-Feedback" steht jetzt **vor** „Bewertung & Ranking" –
  entspricht der chronologischen Reihenfolge im Ablauf.

## [0.50.0] - 2026-07-12
### Hinzugefügt
- **Neuer Menüpunkt „Jury-Feedback" (Gruppe Jury).** Skizziert je Schule einen
  groben Zeitplan für die Feedback-Gespräche. Aus der Zahl der Schüler-Gruppen
  (Teams je Schule) und wenigen Parametern werden **Gesamtzeit** und **Zeit je
  Jury-Gruppe** geschätzt; eine „Gesamt"-Spalte fasst alle Schulen zusammen.
- **Konfigurierbar (nur Verwaltung):** Gesprächsdauer je Schüler-Gruppe,
  Pausenlänge und „Pause nach X Gesprächen" (global) sowie Anzahl Jury-Gruppen
  und Jurymitglieder je Gruppe **je Schule**. Bei weniger als 2 Jurymitgliedern
  je Gruppe wird der Wert markiert. Die Jury sieht die Übersicht schreibgeschützt.
- Rechenmodell: `Gesamtzeit = n · Gesprächsdauer + (n / Pause-nach-X) · Pausenlänge`,
  `Zeit je Jury-Gruppe = Gesamtzeit / Anzahl Jury-Gruppen` (Gruppen laufen parallel).

## [0.49.2] - 2026-07-12
### Behoben
- **PitchDay „Gäste & VIPs" warf einen Fehler (500).** Durch die neue Live-
  Verknüpfung (JOIN auf `users`) waren `name`/`org` in den Sortier-Ausdrücken
  mehrdeutig. Die Sortierungen sind jetzt eindeutig qualifiziert – die Seite lädt
  wieder.
- **Handout „Teilnehmende": „Presse" ergänzt** (fehlte in der Aufzählung).

## [0.49.1] - 2026-07-12
### Geändert
- **Import „Jury & Nutzer übernehmen" überspringt nur noch reine Admin-Konten.**
  Wer die Rolle Jury (oder Projektleitung/Lehrkraft) hat, wird jetzt übernommen –
  **auch wenn er zusätzlich Admin ist** (z. B. Jury nach Rolle → Jury). Nur Konten,
  die **ausschließlich** Admin sind, bleiben außen vor.
- **Sponsoren-Übersicht zeigt den Beitrag in € für das aktuelle Wettbewerbsjahr**
  (Spalte statt „aktiv ja/nein") samt Gesamtsumme des Jahres.

## [0.49.0] - 2026-07-12
### Geändert
- **Gästeliste zieht Stammdaten jetzt LIVE aus „Jury & Nutzer".** Übernommene
  Gäste (Jury, Projektleitung, Lehrkräfte) werden fest mit dem Nutzerkonto
  verknüpft (`user_id`); Name, Organisation, Position und E-Mail kommen direkt aus
  dem Profil. Aktualisiert jemand sein Profil (oder wird in „Jury & Nutzer"
  geändert), erscheint das **sofort** in Gäste-Übersicht, auf den Reserviert-
  Schildern und im Handout – **ohne Neu-Import**. Manuelle Gäste (VIP/Presse ohne
  Konto) bleiben eine eigenständige Kopie. Bestehende Gäste werden per Migration
  anhand des Namens automatisch verknüpft; verknüpfte Einträge sind mit „🔗
  verknüpft" gekennzeichnet.

## [0.48.0] - 2026-07-12
### Geändert
- **„Jury & Nutzer übernehmen" ist jetzt EIN Button** und übernimmt alle am
  Wettbewerbsjahr Beteiligten (Jury, Projektleitung, Lehrkräfte) – **nie
  Admin-Konten**. Idempotent (neue anlegen, vorhandene auffrischen). Der separate
  „Lehrkräfte übernehmen"-Button entfällt. Rollen-Zuordnung: Jury → Jury,
  Projektleitung → VIP/Gastgeber, Lehrkraft → Lehrkraft.
- **Personen werden überall klassisch nach Nachname sortiert** (Ehrengäste, Jury,
  Lehrkräfte, Presse) – in der Gäste-Übersicht, auf den Reserviert-Schildern und
  im Handout. **Lehrkräfte** werden zusätzlich **je Schule gruppiert**.
### Hinzugefügt
- **Grußworte & Keynote: Reihenfolge festlegbar.** In der Gäste-Übersicht lässt
  sich die Reihenfolge per ↑/↓ anpassen; sie wird ins Handout übernommen.

## [0.47.0] - 2026-07-12
### Hinzugefügt
- **Passkeys / Geräte-Login (WebAuthn).** Man kann sich jetzt gerätegebunden per
  **Fingerabdruck, Face-ID oder Geräte-PIN** anmelden – zusätzlich zum Login per
  E-Mail-/SMS-Code (der als Rückfallweg für neue Geräte erhalten bleibt).
  - Einrichtung im **Profil** unter „Passkeys & Geräte-Login" (nach Anmeldung per
    Code): „Dieses Gerät hinzufügen". Mehrere Passkeys je Konto möglich, jederzeit
    entfernbar; Liste mit „hinzugefügt/zuletzt genutzt".
  - Auf der **Login-Seite** neuer Button „🔑 Mit Passkey anmelden" (nur sichtbar,
    wenn der Browser Passkeys unterstützt; Anmeldung ohne Eingabe eines Kontos über
    auffindbare Passkeys).
  - Umsetzung **ohne externe Abhängigkeiten**: eigene, schlanke WebAuthn-Bibliothek
    (`app/lib/WebAuthn.php`, CBOR-/COSE-Parsing, Signaturprüfung ES256 & RS256 über
    openssl), neue Tabelle `webauthn_credentials`, JSON-Endpunkte unter `?r=passkey`.
    Öffentliche Schlüssel werden serverseitig gespeichert, private Schlüssel bleiben
    sicher auf dem Gerät.

## [0.46.1] - 2026-07-12
### Hinzugefügt
- **Lehrkräfte im PitchDay & im Handout.** Neue Gäste-Kategorie „Lehrkraft" und
  Button **„👩‍🏫 Lehrkräfte übernehmen"** (Lehrkräfte der teilnehmenden Schulen,
  idempotent). Im Handout gibt es jetzt eine eigene Sektion **„Lehrkräfte /
  Projektbetreuung"**, und sie zählen bei „Teilnehmende" mit – ohne sie würde das
  Projekt nicht funktionieren.

## [0.46.0] - 2026-07-12
### Hinzugefügt
- **Organisation & Position pflegbar – fließen in die Gästeliste.** Nutzer können
  im eigenen Profil ihre **Organisation** und **Position** selbst eintragen (auch
  in „Jury & Nutzer" pflegbar). Beim Übernehmen der Jury in den PitchDay werden
  diese Angaben in die Gästeliste (und damit aufs Reserviert-Schild) übernommen.
- **Wettbewerbsjahr jetzt auch für Lehrkräfte auswählbar** (eigene Zyklus-Rolle
  „Lehrkraft").
### Geändert
- **„Jury übernehmen" → „Jury & Nutzer übernehmen".** Der Button fragt nicht mehr
  irreführend „Wirklich löschen?" und arbeitet **idempotent**: Neue Jury-Mitglieder
  werden angelegt, bereits vorhandene mit den aktuellen Angaben (Organisation,
  Position, E-Mail) aufgefrischt – Status/Sitzplatz/Bemerkung bleiben erhalten.
- **Handynummer systemweit eindeutig.** Doppelte Handynummern werden jetzt auch in
  „Jury & Nutzer" und „Projektlehrer" abgewiesen (bisher nur im Profil), zusätzlich
  abgesichert durch einen DB-UNIQUE-Index. Doppelte Nummern hätten den Handy-Login
  mehrdeutig gemacht. (E-Mail war bereits doppelt gesichert.)

## [0.45.0] - 2026-07-12
### Hinzugefügt
- **Mehrfachrollen je Nutzer.** Eine Person kann jetzt mehrere Rollen zugleich
  haben – z. B. Jury **und** Projektleitung, oder zusätzlich Admin. In „Jury &
  Nutzer" werden die Rollen als **Chips zur Mehrfachauswahl** gepflegt und in der
  Liste als mehrere Chips angezeigt. Neue Tabelle `user_roles` (Backfill aus der
  bisherigen Einzelrolle); `users.role` bleibt als „Hauptrolle" (höchste
  Berechtigung) erhalten und wird synchron gehalten.
### Geändert
- **Alle rollenbasierten Auswertungen berücksichtigen die volle Rollenmenge:**
  Navigation und Sichtbarkeiten, Jury-Zählungen und -Mittelwerte (ein Admin, der
  zugleich Jury ist, zählt nun als Jury; reine Admin-Konten weiterhin nicht),
  Zuordnungslisten der Wettbewerbsjahre, Projektleitung in Kontakt/Handout sowie
  die Lehrkraft-Zuordnung der Schulen. Reine Lehrkräfte bleiben schulgebunden;
  ist dieselbe Person auch Jury/Leitung, greift die weitergehende Berechtigung.

## [0.44.7] - 2026-07-12
### Hinzugefügt
- **„Jury & Nutzer": Filter nach Wettbewerbsjahr** – standardmäßig das aktuelle
  Jahr. Zeigt die Mitwirkenden des Jahres (Jury/Projektleitung des Zyklus sowie
  die Lehrkräfte der teilnehmenden Schulen); Admin-Konten als jahresübergreifende
  Servicerolle immer. „Alle Jahre" zeigt wieder alle Nutzer.

## [0.44.6] - 2026-07-12
### Hinzugefügt
- **Handout-Fußzeile mit Seitenzahlen:** unten rechts „Seite X / Y", unten links
  kurz die Überschrift – beides klein und in Anthrazit, auf jeder Seite (echte
  Seitenzähler über CSS Paged Media).
### Geändert
- **Reserviert-Schilder: Logo größer und mittig ganz oben** über dem Titel.
### Behoben
- **Handout listet keine Absagen mehr.** Gäste mit Status „Absage" tauchen nicht
  mehr in Ehrengäste/Jury/Presse/Grußworte oder in „Teilnehmende" auf (z. B. ein
  abgesagtes Jurymitglied stand bisher noch in der Liste).

## [0.44.5] - 2026-07-12
### Behoben
- **Ranking-Filter ließ „Status setzen"-Zeilen der ausgefilterten Teams stehen.** Beim
  Suchen/Filtern in „Bewertung & Ranking" blieben die Verwaltungs-Unterzeilen (Status
  setzen) der nicht passenden Teams sichtbar. Ursache: Die Tabellen-Logik hielt eine
  Unterzeile (eine einzelne `colspan`-Zelle) fälschlich für eine Platzhalter-Zeile und
  übersprang sie beim Filtern. Unterzeilen werden jetzt korrekt ihrer Team-Zeile
  zugeordnet – das behebt zugleich, dass sie beim Sortieren nach unten rutschten.

## [0.44.4] - 2026-07-12
### Behoben
- **Handout „Teilnehmende": „rund" vor der Teamzahl entfernt.** Die Zahl ist der
  exakte Teams-Zählwert – das aus der Vorlage übernommene „rund" war irreführend
  (z. B. „44 Teams" statt „rund 44 Teams").

## [0.44.3] - 2026-07-12
### Geändert
- **„Ablaufplan / Handout (PDF)" liegt jetzt im Tab „Ablaufplan"** (statt bei
  „Gäste & VIPs") – dort, wo man ihn erwartet.
- **Reserviert-Schilder standardmäßig für alle Gäste/VIPs außer Absagen.** Die
  Auswahl ist jetzt für alle nicht abgesagten Gäste vorbelegt (einzeln
  abwählbar); ohne Auswahl druckt der Button alle außer Absagen.

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
