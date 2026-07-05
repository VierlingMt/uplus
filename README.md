# Unternehmen Plus – Verwaltungs- & Bewertungsplattform

Verwaltungssoftware für den Businessplanwettbewerb **Unternehmen Plus** der
Wirtschaftsjunioren Forchheim: Schulen & Teams, Einreichung der Businesspläne,
**KI-Vorbewertung**, **Jury-Bewertung**, Ranking und Pitch-Day.

## Tech-Stack (bewusst schlank)

- **PHP 8.2** ohne schweres Framework (kleiner Router, PDO, Session-Auth)
- **MariaDB 10.6** (UNIX-Socket, `localhost`)
- Server-gerendertes HTML im **WJD-Corporate-Design** (Chivo/Bitter, Blau/Türkis)
- **KI-Vorbewertung** über die Anthropic-Claude-API (liest die PDF nativ)
- Deploy per **GitHub Actions → FTP**

## Architektur

```
index.php            Front-Controller / Router (?r=route)
app/
  bootstrap.php      Config, Autoloader, Session, Auto-Migration
  lib/               Database, Auth, Csrf, Criteria, Claude, Migrator, helpers
  pages/             Views + Controller je Route
config/
  config.php         liest config.local.php / ENV  ->  cfg('schluessel')
  config.local.php   NICHT im Git; beim Deploy aus Secrets erzeugt
db/schema.sql        Basis-Schema (vom Migrator eingespielt)
assets/              CSS, JS, Logos (UPlus, WJ, Schulen, Sponsoren)
scripts/gen_config.php   erzeugt config.local.php aus Deploy-Secrets
.github/workflows/deploy.yml
```

### Automatische Schema-Migration

`Migrator::run()` läuft bei **jedem Seitenaufruf** (aus `bootstrap.php`) und
wendet noch nicht eingespielte Migrationen an (per DB-Lock gegen Races). Neue
Schemaänderungen einfach als weiteren Eintrag in `Migrator::migrations()`
ergänzen – sie greifen beim nächsten Aufruf automatisch. Beim ersten Aufruf
werden Schema **und** Grunddaten (Schulen, Jury, Einstellungen) angelegt.

## Bewertungsmodell (Formular 06)

- **Businessplan** (×2): Geschäftsidee, Vertrieb & Wettbewerb, Team & Partner,
  Unternehmensgründung, Finanzen & Kosten – je 0–10 → max **50**
- **Pitch-Day** (×1): Überzeugungskraft, Präsentationsstil, Kreativität,
  Antworten auf Jury-Fragen – je 0–10 → max **40**
- **Gesamt** = 2×Businessplan + 1×Pitch → max **140**

## Deploy / Secrets

Push auf `claude/uplus-management-system-ostrxb` oder `main` löst den Deploy aus.

**GitHub Actions Secrets:**

| Secret | Zweck | Status |
|---|---|---|
| `FTP_SERVER` `FTP_USER` `FTP_PASS` `FTP_PORT` | FTP-Zugang | ✅ vorhanden |
| `DB_NAME` `DB_USER` `DB_PASS` | Datenbank | ✅ vorhanden |
| `DB_HOST` | optional (Default `localhost`) | optional |
| `ANTHROPIC_API_KEY` | KI-Vorbewertung | ⬜ nachreichen |
| `APP_KEY` | Session/CSRF (sonst automatisch erzeugt) | optional |
| `SEED_ADMIN_EMAIL` `SEED_ADMIN_PASSWORD` | Start-Admin-Konto | optional |

**Optionale Repository-Variablen** (Settings → Variables), falls die App nicht
im FTP-Root liegt oder FTPS nötig ist:

| Variable | Default | Zweck |
|---|---|---|
| `FTP_DIR` | `./` | Zielverzeichnis auf dem Server |
| `FTP_PROTOCOL` | `ftp` | `ftps` für verschlüsselten Transfer |
| `BASE_PATH` (Secret) | `` | URL-Unterpfad, z. B. `/uplus` |

### Start-Admin

Ohne `SEED_ADMIN_*`-Secrets wird beim ersten Aufruf ein Admin angelegt:

- **E-Mail:** `mv@vimatec.de`
- **Passwort:** `UPlus-Start!2026` → **bitte nach dem ersten Login ändern**

## Lokale Entwicklung

```bash
cp config/config.local.example.php config/config.local.php   # Werte eintragen
php -S localhost:8000
```
