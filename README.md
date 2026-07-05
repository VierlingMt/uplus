# Unternehmen Plus

Verwaltungs- und Bewertungsplattform für den Businessplanwettbewerb **Unternehmen Plus**
der Wirtschaftsjunioren Forchheim – von der Einreichung der Businesspläne über die
KI-Vorbewertung und die Jury-Bewertung bis zum Pitch-Day.

> Aktuelle Version siehe [CHANGELOG.md](CHANGELOG.md) und in der App unten links in der Navigation.

## Inhalt

- [Überblick](#überblick)
- [Tech-Stack](#tech-stack-bewusst-schlank)
- [Architektur](#architektur)
- [Bewertungsmodell](#bewertungsmodell-formular-06)
- [Deploy & Secrets](#deploy--secrets)
- [Lokale Entwicklung](#lokale-entwicklung)
- [Versionierung & Changelog](#versionierung--changelog)

## Überblick

Der Wettbewerb richtet sich an Gymnasiast:innen der 10. Klasse im Landkreis Forchheim.
Die Plattform bildet den Ablauf ab: Schulen und Teams verwalten, Businesspläne
einreichen, per KI vorbewerten, durch die Jury bewerten, das Ranking bilden, die
besten 7 (+2 Nachrücker) für den Pitch-Day nominieren und dort final bewerten.

**Rollen**

- **Admin (admin)** – dauerhafter Eigentümer/Super-Admin der App
  (`mv@vimatec.de`). Volle Verwaltung wie die Projektleitung, zusätzlich:
  vergibt/entzieht die Admin-Rolle, kann nicht gelöscht oder herabgestuft
  werden. Bleibt unabhängig von der jährlich wechselnden Projektleitung.
  Kann über **„Ansehen als"** (👁 in „Jury & Nutzer") die App aus Sicht eines
  beliebigen Nutzers betrachten – Nur-Lese-Ansicht mit Hinweisbanner.
- **Projektleitung (lead)** – volle Verwaltung; wechselt jährlich.
- **Lehrkraft (teacher)** – verwaltet Teams/Schüler der eigenen Schule, lädt
  Businesspläne hoch, sieht Material.
- **Jury (juror)** – sieht Businesspläne + KI-Vorbewertung, bewertet, sieht Ranking.

## Tech-Stack (bewusst schlank)

Das Deployment-Ziel ist klassisches Shared-Hosting (FTP + MariaDB, kein dauerhafter
Serverprozess/SSH). Daraus folgt der Stack:

- **PHP 8.5** ohne Framework – kleiner Router, PDO, passwortloser Login per
  Magic-Link (E-Mail), Session-Auth, CSRF. Läuft nativ,
  kein Build-Step, Deploy = Dateien per FTP kopieren.
- **MariaDB 10.6** (UNIX-Socket, `localhost`).
- Server-gerendertes HTML im **WJD-Corporate-Design** (Chivo/Bitter, Blau/Türkis).
- **KI-Vorbewertung** über die Anthropic-Claude-API (liest die PDF nativ).
- Deploy per **GitHub Actions → FTP**.

## Architektur

```
index.php              Front-Controller / Router (?r=route)
app/
  bootstrap.php        Config, Autoloader, Session, Auto-Migration, Base-Path
  lib/                 Database, Auth, Csrf, Criteria, Claude, AiEval, Migrator, helpers
  pages/               Views + Controller je Route
config/
  config.php           liest config.local.php / ENV  ->  cfg('schluessel')
  config.local.php     NICHT im Git; beim Deploy aus Secrets erzeugt
db/schema.sql          Basis-Schema (vom Migrator eingespielt)
storage/
  uploads/             Laufzeit-Uploads (Businesspläne, Material) – nicht im Git
  seed_plans/          gebündelte Erst-Importe (per Migration eingelesen)
assets/                CSS, JS, Logos (UPlus, WJ, Schulen, Sponsoren)
scripts/gen_config.php erzeugt config.local.php aus Deploy-Secrets
.github/workflows/deploy.yml
```

### Automatische Schema-Migration

`Migrator::run()` läuft bei **jedem Seitenaufruf** und wendet noch nicht eingespielte
Migrationen an (DB-Lock gegen Races). Neue Schemaänderungen einfach als weiteren
Eintrag in `Migrator::migrations()` ergänzen – sie greifen beim nächsten Aufruf
automatisch. Beim ersten Aufruf werden Schema **und** Grunddaten angelegt sowie die
eingereichten Businesspläne importiert.

### Base-Path

Der Base-Path wird automatisch aus dem Request abgeleitet; die App funktioniert damit
im Web-Root (eigene Subdomain, `base_path` leer) wie in einem Unterordner
(z. B. `/uplus`) ohne weitere Konfiguration. Seit dem Umzug auf die eigene Subdomain
**https://uplus.vimatec.de** liegt die App im Web-Root – das `BASE_PATH`-Secret bleibt
daher leer bzw. entfällt.

## Bewertungsmodell (Formular 06)

- **Businessplan** (×2): Geschäftsidee, Vertrieb & Wettbewerb, Team & Partner,
  Unternehmensgründung, Finanzen & Kosten – je 0–10 → max **50**
- **Pitch-Day** (×1): Überzeugungskraft, Präsentationsstil, Kreativität,
  Antworten auf Jury-Fragen – je 0–10 → max **40**
- **Gesamt** = 2×Businessplan + 1×Pitch → max **140**

## Deploy & Secrets

Push auf `main` löst den Deploy aus (manuell auch via „Run workflow").
Die App ist erreichbar unter **https://uplus.vimatec.de** (eigene Subdomain, PHP 8.5).

**GitHub Actions Secrets**

| Secret | Zweck | Status |
|---|---|---|
| `FTP_SERVER` `FTP_USER` `FTP_PASS` `FTP_PORT` | FTP-Zugang | vorhanden |
| `DB_NAME` `DB_USER` `DB_PASS` | Datenbank | vorhanden |
| `DB_HOST` | optional (Default `localhost`) | optional |
| `ANTHROPIC_API_KEY` | KI-Vorbewertung | nachreichen |
| `APP_KEY` | Session/CSRF (sonst automatisch erzeugt) | optional |
| `SEED_ADMIN_EMAIL` | Start-Admin-Konto (E-Mail) | optional |
| `APP_URL` | Basis-URL für Login-Links in Mails (z. B. `https://uplus.vimatec.de`) | empfohlen |
| `MAIL_FROM` `MAIL_FROM_NAME` | Absender der Login-Mails | optional |

**Optionale Repository-Variablen** (Settings → Variables): `FTP_DIR` (Default `./`),
`FTP_PROTOCOL` (`ftp`/`ftps`), `BASE_PATH` (Secret, falls Auto-Erkennung nicht passt).

### Anmeldung (passwortlos)

Der Login erfolgt **ausschließlich per E-Mail (Magic-Link)**: Auf der Login-Seite
die E-Mail-Adresse eingeben, den zugeschickten Link (30 Min. gültig, einmalig)
öffnen – fertig, kein Passwort. Voraussetzung ist eine korrekte, erreichbare
E-Mail-Adresse am Nutzerkonto (in „Jury & Nutzer" pflegbar). Das dauerhafte
Eigentümer-Konto ist `mv@vimatec.de`.

## Lokale Entwicklung

```bash
cp config/config.local.example.php config/config.local.php   # DB-Werte eintragen
php -S localhost:8000
```

## Versionierung & Changelog

Wir folgen [Semantic Versioning](https://semver.org/lang/de/) und pflegen das
Changelog nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

**Bei jeder relevanten Änderung:**

1. Passenden Abschnitt in [CHANGELOG.md](CHANGELOG.md) ergänzen
   (`Hinzugefügt` / `Geändert` / `Behoben`).
2. `APP_VERSION` in `config/config.php` anheben (SemVer: MAJOR.MINOR.PATCH).
3. Version + Changelog gehören in denselben Commit wie die Änderung.

Die aktuelle Version wird in der App unten in der Navigation angezeigt; ein Klick
öffnet das Changelog.
