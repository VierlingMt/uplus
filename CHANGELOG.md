# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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

[Unreleased]: https://github.com/VierlingMt/uplus/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.3.0
[0.2.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.2.0
[0.1.0]: https://github.com/VierlingMt/uplus/releases/tag/v0.1.0
