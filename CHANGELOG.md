# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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
