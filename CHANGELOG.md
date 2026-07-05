# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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

[Unreleased]: https://github.com/VierlingMt/uplus/compare/v0.8.0...HEAD
[0.8.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.8.0
[0.7.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.7.0
[0.6.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.6.0
[0.5.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.5.0
[0.4.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.4.0
[0.3.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.3.0
[0.2.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.2.0
[0.1.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.1.0
