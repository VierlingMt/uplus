# CLAUDE.md – Projekt „Unternehmen Plus" (uplus)

## Git-Workflow (WICHTIG – gilt für ALLE Sessions in diesem Repo)

- Nach Abschluss einer Aufgabe **immer auf `main` mergen und pushen** (ausdrückliche
  Vorgabe von Martin Vierling, mv@vimatec.de). Nicht auf einem Feature-Branch
  liegen lassen.
- Üblicher Ablauf: Änderungen committen → auf den aktuellen `origin/main` rebasen
  (Konflikte auflösen) → `main` per Fast-Forward auf den Stand bringen →
  `git push origin main`.
- Ein Push auf `main` löst automatisch den Deploy-Workflow (`.github/workflows/deploy.yml`,
  FTP auf den Live-Webspace) aus. Das ist gewollt.
- `APP_VERSION` in `config/config.php` zusammen mit `CHANGELOG.md` pflegen (SemVer).

## Architektur (Kurzüberblick)

- Schlankes PHP (Front-Controller `index.php`, Routing über `?r=route`), MariaDB/MySQL.
- Auto-Migrator (`app/lib/Migrator.php`) läuft bei jedem Request; neue Schemaänderungen
  als weiteren Eintrag in `migrations()` ergänzen (idempotent).
- Login ist **passwortlos** (Magic-Link, `login_tokens`).

## Rollen

- `admin` = dauerhafter Eigentümer/Super-Admin (`mv@vimatec.de`).
- `lead` = Projektleitung (wechselt jährlich, volle Verwaltung).
- `teacher` = Lehrkraft, `juror` = Jury.
- „Verwaltung" (admin **oder** lead) über `Auth::isManager()` / `Auth::requireManager()`.
- Admin kann über **„Ansehen als"** (`viewas`/`viewstop`) die App aus Nutzersicht
  betrachten (Nur-Lese).
