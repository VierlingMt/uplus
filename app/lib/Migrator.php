<?php
/**
 * Automatischer Schema-Migrator.
 *
 * Laeuft bei jedem Request (aus bootstrap.php) und wendet noch nicht
 * eingespielte Migrationen an. Neue Schemaänderungen einfach als weiteren
 * Eintrag in migrations() ergaenzen – sie greifen beim naechsten Seitenaufruf
 * automatisch. Ein DB-Lock verhindert Races bei parallelen Requests.
 */

declare(strict_types=1);

final class Migrator
{
    private static bool $done = false;

    public static function run(): void
    {
        if (self::$done) {
            return;
        }
        $pdo = Database::pdo();

        try {
            $applied = self::applied($pdo);
        } catch (PDOException $e) {
            self::ensureTable($pdo);
            $applied = [];
        }

        $migrations = self::migrations();
        $pending = array_filter($migrations, static fn($m) => !in_array($m['version'], $applied, true));
        if (!$pending) {
            self::$done = true;
            return;
        }

        // Serialisieren, damit nicht zwei Requests gleichzeitig migrieren.
        $pdo->query("SELECT GET_LOCK('uplus_migrate', 15)");
        try {
            $applied = self::applied($pdo); // nach Lock erneut pruefen
            foreach ($migrations as $m) {
                if (in_array($m['version'], $applied, true)) {
                    continue;
                }
                if (is_callable($m['up'])) {
                    ($m['up'])($pdo);
                } else {
                    self::execSql($pdo, (string) $m['up']);
                }
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, NOW())');
                $stmt->execute([$m['version'], $m['name'] ?? '']);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('uplus_migrate')");
        }
        self::$done = true;
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL,
                name    VARCHAR(190) NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return string[] Liste bereits angewandter Versionen. */
    private static function applied(PDO $pdo): array
    {
        return $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private static function execSql(PDO $pdo, string $sql): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_map('trim', explode(';', (string) $sql)) as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    /**
     * Registry aller Migrationen (in Reihenfolge).
     * @return array<int,array{version:string,name:string,up:mixed}>
     */
    private static function migrations(): array
    {
        return [
            [
                'version' => '2026_07_01_base_schema',
                'name'    => 'Basis-Schema',
                'up'      => file_get_contents(ROOT_PATH . '/db/schema.sql'),
            ],
            [
                'version' => '2026_07_02_seed',
                'name'    => 'Grunddaten (Schulen, Jury, Einstellungen)',
                'up'      => [self::class, 'seed'],
            ],
            [
                'version' => '2026_07_03_import_plans',
                'name'    => 'Import eingereichter Businesspläne',
                'up'      => [self::class, 'importPlans'],
            ],
            [
                'version' => '2026_07_04_owner_admin',
                'name'    => 'Dauerhafter App-Admin (mv@vimatec.de)',
                'up'      => [self::class, 'ownerAdmin'],
            ],
            [
                'version' => '2026_07_05_admin_name',
                'name'    => 'Admin-Konto mv@vimatec.de normalisieren',
                'up'      => [self::class, 'normalizeOwnerAdmin'],
            ],
            [
                'version' => '2026_07_06_ai_min_standard',
                'name'    => 'Mindeststandard-Gate für KI-Vorbewertung',
                'up'      => 'ALTER TABLE ai_evaluations
                    ADD COLUMN IF NOT EXISTS meets_minimum TINYINT(1) NULL AFTER total_score,
                    ADD COLUMN IF NOT EXISTS min_reason TEXT NULL AFTER meets_minimum',
            ],
            [
                'version' => '2026_07_07_structure_checks',
                'name'    => 'Struktur-/Mindeststandard-Check (eigener Pass, günstiges Modell)',
                'up'      => "CREATE TABLE IF NOT EXISTS structure_checks (
                    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    business_plan_id INT UNSIGNED NOT NULL,
                    model            VARCHAR(80) NULL,
                    status           ENUM('running','done','error') NOT NULL DEFAULT 'running',
                    meets_minimum    TINYINT(1) NULL,
                    reason           TEXT NULL,
                    sections_json    LONGTEXT NULL,
                    error_message    TEXT NULL,
                    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_sc_bp (business_plan_id),
                    CONSTRAINT fk_sc_bp FOREIGN KEY (business_plan_id) REFERENCES business_plans(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            [
                'version' => '2026_07_08_sponsors',
                'name'    => 'Sponsoren + Beiträge (Verwaltung, Auto-Anzeige je Jahr)',
                'up'      => [self::class, 'sponsorsSetup'],
            ],
            [
                'version' => '2026_07_09_structure_score',
                'name'    => 'Substanz-Score für den Struktur-Check',
                'up'      => 'ALTER TABLE structure_checks
                    ADD COLUMN IF NOT EXISTS completeness_score TINYINT NULL AFTER meets_minimum',
            ],
            [
                'version' => '2026_07_10_login_tokens',
                'name'    => 'Passwortloser Login per Magic-Link (Token-Tabelle)',
                'up'      => "CREATE TABLE IF NOT EXISTS login_tokens (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id      INT UNSIGNED NOT NULL,
                    token_hash   CHAR(64) NOT NULL,
                    expires_at   DATETIME NOT NULL,
                    used_at      DATETIME NULL,
                    requested_ip VARCHAR(45) NULL,
                    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_login_tokens_hash (token_hash),
                    KEY idx_login_tokens_user (user_id),
                    KEY idx_login_tokens_expires (expires_at),
                    CONSTRAINT fk_login_tokens_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            [
                'version' => '2026_07_11_competition_cycles',
                'name'    => 'Wettbewerbszyklen + Zuordnung Jury/Projektleitung/Schulen (mit Historie)',
                'up'      => [self::class, 'competitionCycles'],
            ],
            [
                'version' => '2026_07_12_login_codes',
                'name'    => 'Passwortloser Login per SMS-Einmalcode (seven.io)',
                'up'      => "CREATE TABLE IF NOT EXISTS login_codes (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id      INT UNSIGNED NOT NULL,
                    code_hash    CHAR(64) NOT NULL,
                    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at   DATETIME NOT NULL,
                    used_at      DATETIME NULL,
                    requested_ip VARCHAR(45) NULL,
                    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_login_codes_user (user_id),
                    KEY idx_login_codes_expires (expires_at),
                    CONSTRAINT fk_login_codes_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            [
                'version' => '2026_07_13_admin_role_tier',
                'name'    => 'Eigene Admin-Rolle (Eigentümer) über der Projektleitung (lead)',
                'up'      => [self::class, 'adminRoleTier'],
            ],
            [
                'version' => '2026_07_14_sponsor_cycle',
                'name'    => 'Sponsoren-Beiträge an Wettbewerbszyklus koppeln (year → cycle_id vereinheitlicht)',
                'up'      => [self::class, 'sponsorCycle'],
            ],
            [
                'version' => '2026_07_15_user_photo',
                'name'    => 'Profil-/Porträtfoto für Nutzer (Jury, Projektleitung)',
                'up'      => 'ALTER TABLE users
                    ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER phone',
            ],
            [
                'version' => '2026_07_16_cycle_milestones',
                'name'    => 'Konfigurierbare Meilensteine (Projektablauf) je Wettbewerbsjahr',
                'up'      => [self::class, 'cycleMilestones'],
            ],
        ];
    }

    /** Vorbelegung des Projektablaufs (bisher hartkodiert im Dashboard). */
    public const SEED_MILESTONES = [
        ['Kick-Off', 'Ende Feb', 'done'],
        ['Teambuilding', 'Ende Mrz', 'done'],
        ['Ideenfindung', 'ab April', 'done'],
        ['Juryfeedback', 'KW21/Mai', 'done'],
        ['Businessplan-Erstellung', '8 Wochen', 'done'],
        ['Einsendeschluss', '01.07', 'active'],
        ['Jury-Bewertung', 'Jul', 'active'],
        ['Pitch Day', '15.07', 'upcoming'],
        ['Project Closing', '22.07', 'upcoming'],
    ];

    /**
     * Konfigurierbare Meilensteine (Projektablauf) je Wettbewerbsjahr einführen.
     * Legt die Tabelle an und übernimmt die bislang im Dashboard hartkodierte
     * Zeitleiste in den aktiven Zyklus, damit die Anzeige unverändert bleibt.
     */
    public static function cycleMilestones(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cycle_milestones (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                cycle_id     INT UNSIGNED NOT NULL,
                label        VARCHAR(190) NOT NULL,
                date_from    DATE NULL,
                date_to      DATE NULL,
                period_label VARCHAR(120) NULL,
                status       ENUM('auto','done','active','upcoming') NOT NULL DEFAULT 'auto',
                sort_order   INT NOT NULL DEFAULT 0,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ms_cycle (cycle_id),
                CONSTRAINT fk_ms_cycle FOREIGN KEY (cycle_id)
                    REFERENCES competition_cycles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Bestehende Zeitleiste in den aktiven Zyklus überführen (nur, solange
        // dort noch keine Meilensteine gepflegt sind).
        $active = (int) ($pdo->query('SELECT id FROM competition_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        if (!$active) {
            return;
        }
        $has = (int) $pdo->query("SELECT COUNT(*) FROM cycle_milestones WHERE cycle_id = $active")->fetchColumn();
        if ($has > 0) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO cycle_milestones (cycle_id, label, period_label, status, sort_order) VALUES (?,?,?,?,?)'
        );
        foreach (self::SEED_MILESTONES as $i => [$label, $period, $state]) {
            $ins->execute([$active, $label, $period, $state, ($i + 1) * 10]);
        }
    }

    /**
     * Führt die getrennte Admin-/Projektleitungs-Rolle ein.
     * Bisher waren Eigentümer und Projektleitung beide „admin". Ab jetzt:
     *   admin = dauerhafter Eigentümer (mv@vimatec.de + ggf. konfigurierter Seed-Admin)
     *   lead  = Projektleitung (wechselt jährlich)
     * Bestehende „admin"-Konten, die nicht der Eigentümer sind, werden zu „lead".
     */
    public static function adminRoleTier(PDO $pdo): void
    {
        // ENUM erweitern (idempotent – MODIFY ist unkritisch für vorhandene Daten).
        $pdo->exec(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('admin','lead','teacher','juror') NOT NULL"
        );

        // Eigentümer-Konten: der dauerhafte App-Admin und ein evtl. konfigurierter Seed-Admin.
        $owners = array_values(array_unique(array_filter([
            'mv@vimatec.de',
            strtolower((string) cfg('seed_admin_email', 'mv@vimatec.de')),
        ])));
        $ph = implode(',', array_fill(0, count($owners), '?'));

        // Alle bisherigen Admins außer den Eigentümern werden Projektleitung (lead).
        $pdo->prepare(
            "UPDATE users SET role = 'lead' WHERE role = 'admin' AND email NOT IN ($ph)"
        )->execute($owners);

        // Eigentümer-Konten sicher auf admin.
        $pdo->prepare(
            "UPDATE users SET role = 'admin' WHERE email IN ($ph)"
        )->execute($owners);
    }

    /** Bekannte Sponsoren (Logos liegen als Assets bereit). Beitrag/Wettbewerbsjahr
     *  wird in der Migration 2026_07_14_sponsor_cycle am aktiven Zyklus gesetzt. */
    public const SEED_SPONSORS = [
        ['Sparkasse Forchheim', 'img/sponsors/sparkasse.png'],
        ['Medical Valley', 'img/sponsors/medical-valley.png'],
        ['Bildungsregion Forchheim', 'img/sponsors/bildungsregion.png'],
        ['VIERLING', 'img/sponsors/vierling.jpg'],
        ['Stadtwerke Ebermannstadt', 'img/sponsors/stadtwerke-ebs.png'],
        ['Stadt Ebermannstadt', 'img/sponsors/stadt-ebs.png'],
        ['Wirtschaftsjunioren Bayern', 'img/sponsors/wj-bayern.jpg'],
    ];

    /** Sponsoren-Tabellen anlegen + bekannte Sponsoren seeden (Beiträge folgen je Zyklus). */
    public static function sponsorsSetup(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sponsors (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(190) NOT NULL,
                logo_path    VARCHAR(255) NULL,
                address      TEXT NULL,
                contact_name VARCHAR(190) NULL,
                email        VARCHAR(190) NULL,
                website      VARCHAR(255) NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sponsors_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // Beiträge hängen am Wettbewerbszyklus (cycle_id). Für bestehende Installationen,
        // die diese Tabelle noch mit der alten Spalte `year` besitzen, überführt die
        // Migration 2026_07_14_sponsor_cycle die Daten und entfernt `year`.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sponsor_contributions (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sponsor_id  INT UNSIGNED NOT NULL,
                cycle_id    INT UNSIGNED NULL,
                amount      DECIMAL(10,2) NULL,
                description VARCHAR(190) NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_contrib_sponsor (sponsor_id),
                KEY idx_contrib_cycle (cycle_id),
                CONSTRAINT fk_contrib_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $insS = $pdo->prepare('INSERT INTO sponsors (name, logo_path) VALUES (?, ?) ON DUPLICATE KEY UPDATE logo_path=VALUES(logo_path)');
        foreach (self::SEED_SPONSORS as $s) {
            $insS->execute($s);
        }
    }

    /**
     * Zentrales Wettbewerbsjahr (Zyklus) einführen. Legt die Tabellen an,
     * erstellt einen ersten aktiven Zyklus und ordnet alle bestehenden
     * Juror:innen, Projektleitungen und Schulen diesem Jahr zu, damit die
     * bisherige Aufstellung als Historie erhalten bleibt.
     */
    public static function competitionCycles(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS competition_cycles (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                year_label  VARCHAR(40)  NOT NULL,
                title       VARCHAR(190) NULL,
                starts_on   DATE NULL,
                ends_on     DATE NULL,
                is_active   TINYINT(1) NOT NULL DEFAULT 0,
                note        TEXT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cycles_year (year_label),
                KEY idx_cycles_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cycle_members (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                cycle_id      INT UNSIGNED NOT NULL,
                user_id       INT UNSIGNED NOT NULL,
                role_in_cycle ENUM('juror','project_lead') NOT NULL DEFAULT 'juror',
                specialty     VARCHAR(190) NULL,
                note          VARCHAR(255) NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cycle_member (cycle_id, user_id),
                KEY idx_cm_cycle (cycle_id),
                KEY idx_cm_user (user_id),
                CONSTRAINT fk_cm_cycle FOREIGN KEY (cycle_id) REFERENCES competition_cycles(id) ON DELETE CASCADE,
                CONSTRAINT fk_cm_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cycle_schools (
                cycle_id   INT UNSIGNED NOT NULL,
                school_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (cycle_id, school_id),
                KEY idx_cs_school (school_id),
                CONSTRAINT fk_cs_cycle  FOREIGN KEY (cycle_id)  REFERENCES competition_cycles(id) ON DELETE CASCADE,
                CONSTRAINT fk_cs_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Bereits Zyklen vorhanden? Dann nichts weiter tun.
        $exists = (int) $pdo->query('SELECT COUNT(*) FROM competition_cycles')->fetchColumn();
        if ($exists > 0) {
            return;
        }

        // Ersten (aktiven) Zyklus für das laufende Wettbewerbsjahr anlegen.
        $label = (string) cfg('seed_cycle_label', '2025/26');
        $pdo->prepare(
            'INSERT INTO competition_cycles (year_label, title, is_active) VALUES (?,?,1)'
        )->execute([$label, 'Businessplanwettbewerb ' . $label]);
        $cycleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO settings (k, v) VALUES ("active_cycle_id", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([(string) $cycleId]);

        // Bestehende Jury & Projektleitung diesem Jahr zuordnen (Historie).
        $mIns = $pdo->prepare(
            'INSERT IGNORE INTO cycle_members (cycle_id, user_id, role_in_cycle, specialty) VALUES (?,?,?,?)'
        );
        // Admin & Projektleitung (lead) zählen als Projektleitung im Zyklus, Jury als Jury.
        foreach ($pdo->query("SELECT id, role, specialty FROM users WHERE role IN ('admin','lead','juror')")->fetchAll() as $u) {
            $roleInCycle = $u['role'] === 'juror' ? 'juror' : 'project_lead';
            $mIns->execute([$cycleId, (int) $u['id'], $roleInCycle, $u['specialty'] ?: null]);
        }

        // Bestehende Schulen diesem Jahr zuordnen.
        $sIns = $pdo->prepare('INSERT IGNORE INTO cycle_schools (cycle_id, school_id) VALUES (?,?)');
        foreach ($pdo->query('SELECT id FROM schools')->fetchAll() as $s) {
            $sIns->execute([$cycleId, (int) $s['id']]);
        }
    }

    /**
     * Sponsoren-Beiträge an den Wettbewerbszyklus koppeln – vereinheitlicht die
     * beiden früheren Jahres-Konzepte zu einer Quelle: `competition_cycles`.
     *
     * Bestehende Installationen: fügt `cycle_id` hinzu, überführt die bisherigen
     * `year`-Beiträge auf den passenden Zyklus (Label enthält das Jahr, sonst
     * aktiver Zyklus), erzwingt den Fremdschlüssel und entfernt Spalte `year`
     * sowie die überflüssige Einstellung `competition_year`.
     * Neuinstallationen: legt für die bekannten Sponsoren einen Beitrag im aktiven
     * Zyklus an, damit die Auto-Anzeige im Dashboard funktioniert.
     */
    public static function sponsorCycle(PDO $pdo): void
    {
        $has = static function (string $table, string $column) use ($pdo): bool {
            return (bool) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table) . "
                   AND column_name = " . $pdo->quote($column)
            )->fetchColumn();
        };

        if (!$has('sponsor_contributions', 'cycle_id')) {
            $pdo->exec('ALTER TABLE sponsor_contributions ADD COLUMN cycle_id INT UNSIGNED NULL AFTER sponsor_id');
        }

        // Ziel-Zyklus für die Überführung bestimmen (aktiv, sonst neuester).
        $active = (int) ($pdo->query('SELECT id FROM competition_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        if (!$active) {
            $active = (int) ($pdo->query('SELECT id FROM competition_cycles ORDER BY year_label DESC, id DESC LIMIT 1')->fetchColumn() ?: 0);
        }

        // Altbestand (year) auf einen Zyklus überführen.
        if ($has('sponsor_contributions', 'year') && $active) {
            foreach ($pdo->query('SELECT DISTINCT year FROM sponsor_contributions WHERE cycle_id IS NULL AND year IS NOT NULL')->fetchAll() as $r) {
                $y = (int) $r['year'];
                $cid = (int) ($pdo->query(
                    'SELECT id FROM competition_cycles WHERE year_label LIKE ' . $pdo->quote('%' . $y . '%') . ' ORDER BY id DESC LIMIT 1'
                )->fetchColumn() ?: 0);
                if (!$cid) { $cid = $active; }
                $st = $pdo->prepare('UPDATE sponsor_contributions SET cycle_id = ? WHERE cycle_id IS NULL AND year = ?');
                $st->execute([$cid, $y]);
            }
        }
        // Verbleibende ohne Zuordnung dem aktiven Zyklus zuschlagen.
        if ($active) {
            $pdo->prepare('UPDATE sponsor_contributions SET cycle_id = ? WHERE cycle_id IS NULL')->execute([$active]);
        }

        // Fremdschlüssel + NOT NULL erzwingen (nur wenn alle Zeilen zugeordnet sind).
        $nulls = (int) $pdo->query('SELECT COUNT(*) FROM sponsor_contributions WHERE cycle_id IS NULL')->fetchColumn();
        if ($nulls === 0) {
            $pdo->exec('ALTER TABLE sponsor_contributions MODIFY cycle_id INT UNSIGNED NOT NULL');
        }
        $fkExists = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = 'sponsor_contributions'
               AND constraint_name = 'fk_contrib_cycle'"
        )->fetchColumn();
        if (!$fkExists) {
            $idxExists = (bool) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'sponsor_contributions'
                   AND index_name = 'idx_contrib_cycle'"
            )->fetchColumn();
            if (!$idxExists) {
                $pdo->exec('ALTER TABLE sponsor_contributions ADD KEY idx_contrib_cycle (cycle_id)');
            }
            $pdo->exec('ALTER TABLE sponsor_contributions
                ADD CONSTRAINT fk_contrib_cycle FOREIGN KEY (cycle_id) REFERENCES competition_cycles(id) ON DELETE CASCADE');
        }

        // Alte Spalte `year` samt Index entfernen.
        if ($has('sponsor_contributions', 'year')) {
            $yIdx = (bool) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'sponsor_contributions'
                   AND index_name = 'idx_contrib_year'"
            )->fetchColumn();
            if ($yIdx) { $pdo->exec('ALTER TABLE sponsor_contributions DROP INDEX idx_contrib_year'); }
            $pdo->exec('ALTER TABLE sponsor_contributions DROP COLUMN year');
        }

        // Überflüssige Einstellung entfernen – das Jahr steuert jetzt der aktive Zyklus.
        $pdo->exec("DELETE FROM settings WHERE k = 'competition_year'");

        // Für bekannte Sponsoren einen Beitrag im aktiven Zyklus sicherstellen
        // (Neuinstallation; für Bestand bereits durch die Überführung vorhanden).
        if ($active) {
            $ins = $pdo->prepare('INSERT INTO sponsor_contributions (sponsor_id, cycle_id, description) VALUES (?,?,?)');
            foreach (self::SEED_SPONSORS as $s) {
                $sid = (int) ($pdo->query('SELECT id FROM sponsors WHERE name = ' . $pdo->quote($s[0]))->fetchColumn() ?: 0);
                if (!$sid) { continue; }
                $exists = (int) $pdo->query("SELECT COUNT(*) FROM sponsor_contributions WHERE sponsor_id = $sid AND cycle_id = $active")->fetchColumn();
                if (!$exists) {
                    $ins->execute([$sid, $active, 'Unterstützung ' . ($pdo->query("SELECT year_label FROM competition_cycles WHERE id = $active")->fetchColumn() ?: '')]);
                }
            }
        }
    }

    /** Grunddaten anlegen (einmalig). */
    public static function seed(PDO $pdo): void
    {
        // --- Schulen ---
        $schools = [
            ['Ehrenbürg-Gymnasium Forchheim', 'EGF', 'Forchheim', 'img/schools/egf.png'],
            ['Gymnasium Fränkische Schweiz Ebermannstadt', 'GFS', 'Ebermannstadt', 'img/schools/gfs.png'],
            ['HGF', 'HGF', null, 'img/schools/hgf.png'],
        ];
        $sIns = $pdo->prepare(
            'INSERT INTO schools (name, short_name, city, logo_path) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE short_name = VALUES(short_name), logo_path = VALUES(logo_path)'
        );
        foreach ($schools as $s) {
            $sIns->execute($s);
        }

        // --- Benutzer (Admin + Projektleitung + Jury) ---
        // admin = dauerhafter Eigentümer (mv@vimatec.de). lead = Projektleitung
        // (wechselt jährlich). Weitere Juror:innen = juror.
        // Für Konten ohne bekannte E-Mail wird eine Platzhalter-Adresse gesetzt
        // (in "Jury & Nutzer" später korrigierbar). Passwörter setzt die
        // Projektleitung im Nutzer-Modul; nur das Start-Admin-Konto hat eins.
        $adminEmail = strtolower((string) cfg('seed_admin_email', 'mv@vimatec.de'));
        $adminPass  = (string) cfg('seed_admin_password', 'UPlus-Start!2026');

        $users = [
            ['admin', 'Martin Vierling',  $adminEmail,               password_hash($adminPass, PASSWORD_DEFAULT), 'Unternehmer & Gründer'],
            ['lead',  'Anton Schreiber',  'anton@wirduzen.de',       null, 'Unternehmer'],
            ['juror', 'Jehona Ahmeti',    'jehona.ahmeti@juror.uplus.local',   null, null],
            ['juror', 'Yannick Reinlein', 'yannick.reinlein@juror.uplus.local', null, null],
            ['juror', 'Anna Niegel',      'anna.niegel@juror.uplus.local',     null, null],
            ['juror', 'Paul Redetzky',    'paul.redetzky@juror.uplus.local',   null, null],
            ['juror', 'Marcus Müller',    'marcus.mueller@juror.uplus.local',  null, null],
        ];
        $uIns = $pdo->prepare(
            'INSERT INTO users (role, name, email, password_hash, specialty, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );
        foreach ($users as $u) {
            $uIns->execute($u);
        }

        // --- Einstellungen ---
        $settings = [
            ['pitch_slots', '7'],
            ['fallback_slots', '2'],
            ['current_phase', 'evaluation'],
            ['pitch_minutes', '3'],
        ];
        $setIns = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
        foreach ($settings as $s) {
            $setIns->execute($s);
        }

        // --- Material: Erklärvideo ---
        $pdo->prepare(
            'INSERT IGNORE INTO materials (id, title, description, link_url, visibility, sort_order)
             VALUES (1, ?, ?, ?, ?, ?)'
        )->execute([
            'Erklärvideo für Schüler & Lehrkräfte',
            'Einführung in den Businessplanwettbewerb Unternehmen Plus.',
            (string) cfg('explainer_video'),
            'all',
            1,
        ]);
    }

    /**
     * Eingereichte Businesspläne aus storage/seed_plans/<SCHULE>/ importieren.
     * Legt je PDF ein Team an, kopiert die Datei nach uploads/plans und
     * verknüpft sie als aktuellen Businessplan. Läuft nur einmal.
     */
    public static function importPlans(PDO $pdo): void
    {
        $root = ROOT_PATH . '/storage/seed_plans';
        if (!is_dir($root)) {
            return; // nichts zu importieren
        }
        $dest = UPLOAD_PATH . '/plans';
        if (!is_dir($dest)) { @mkdir($dest, 0775, true); }

        // Schulen nach Kürzel
        $schoolByCode = [];
        foreach ($pdo->query('SELECT id, short_name FROM schools')->fetchAll() as $r) {
            if ($r['short_name']) { $schoolByCode[strtoupper($r['short_name'])] = (int) $r['id']; }
        }

        $insTeam = $pdo->prepare('INSERT INTO teams (school_id, name, idea_name, status) VALUES (?,?,?,?)');
        $insPlan = $pdo->prepare(
            'INSERT INTO business_plans (team_id, original_name, stored_name, mime, size_bytes, version, is_current)
             VALUES (?,?,?,?,?,1,1)'
        );

        foreach (glob($root . '/*', GLOB_ONLYDIR) as $dir) {
            $code = strtoupper(basename($dir));
            if (!isset($schoolByCode[$code])) { continue; }
            $schoolId = $schoolByCode[$code];
            $files = glob($dir . '/*.[pP][dD][fF]') ?: [];
            sort($files);
            foreach ($files as $file) {
                $orig = basename($file);
                [$teamName] = self::deriveTeamName($orig, $code);
                $insTeam->execute([$schoolId, $teamName, $teamName, 'submitted']);
                $teamId = (int) $pdo->lastInsertId();

                $stored = bin2hex(random_bytes(12)) . '.pdf';
                @copy($file, $dest . '/' . $stored);
                $insPlan->execute([$teamId, $orig, $stored, 'application/pdf', (int) @filesize($file)]);
            }
        }
    }

    /**
     * Aus einem Dateinamen einen lesbaren Team-/Projektnamen ableiten.
     * @return array{0:string,1:?string} [Name, Klasse]
     */
    public static function deriveTeamName(string $filename, string $code): array
    {
        $s = pathinfo($filename, PATHINFO_FILENAME);
        // Klasse extrahieren (z.B. 10a, 9b)
        $class = null;
        if (preg_match('/\b(\d{1,2}[a-eA-E])\b/u', $s, $m)) { $class = strtolower($m[1]); }

        // camelCase trennen – aber Akronyme (GmbH, LMT, AERO) erhalten:
        // nur splitten, wenn auf den Großbuchstaben ein Kleinbuchstabe folgt.
        $s = preg_replace('/(?<=[a-zäöü])(?=[A-ZÄÖÜ][a-zäöü])/u', ' ', $s);
        // Trenner zu Leerzeichen
        $s = preg_replace('/[_\-]+/u', ' ', $s);
        // Störtokens entfernen (jetzt durch Leerzeichen getrennt)
        $s = preg_replace('/unternehmen\s*plus/iu', ' ', $s);
        $s = preg_replace('/\bbusiness\s?plan\b|\bbuissnisplan\b|\bbusinessplan\b/iu', ' ', $s);
        $s = preg_replace('/\b' . preg_quote($code, '/') . '\b/iu', ' ', $s);
        $s = preg_replace('/\b20\d{2}\s?\d{0,2}\b/u', ' ', $s);        // Jahr 2025 / 2025 26
        $s = preg_replace('/\bteam\b/iu', ' ', $s);
        $s = preg_replace('/\b\d{1,2}[a-eA-E]\b/u', ' ', $s);          // Klasse 10a
        $s = preg_replace('/\b[a-eA-E]\d{1,2}\b/u', ' ', $s);          // Codes B1, D2, C6
        $s = preg_replace('/\b\d{1,2}\b/u', ' ', $s);                  // lose Nummerierung
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '' || mb_strlen($s) < 2) { $s = pathinfo($filename, PATHINFO_FILENAME); }

        $name = $class ? ($s . ' (' . $class . ')') : $s;
        return [$name, $class];
    }

    /**
     * Dauerhaftes App-Admin-Konto sicherstellen (mv@vimatec.de). Die eigentliche
     * Projektleitung wechselt jährlich; dieses Konto bleibt Eigentümer der App.
     */
    public static function ownerAdmin(PDO $pdo): void
    {
        $pass = (string) cfg('seed_admin_password', 'UPlus-Start!2026');
        $pdo->prepare(
            'INSERT IGNORE INTO users (role, name, email, password_hash, specialty, is_active)
             VALUES (?,?,?,?,?,1)'
        )->execute([
            'admin', 'Martin Vierling', 'mv@vimatec.de',
            password_hash($pass, PASSWORD_DEFAULT), null,
        ]);
    }

    /**
     * Admin-Konto mv@vimatec.de sicherstellen und Bezeichnung normalisieren
     * (ohne Zusatz „App-Admin“); Passwort bleibt unangetastet.
     */
    public static function normalizeOwnerAdmin(PDO $pdo): void
    {
        $pass = (string) cfg('seed_admin_password', 'UPlus-Start!2026');
        $pdo->prepare(
            "INSERT INTO users (role, name, email, password_hash, specialty, is_active)
             VALUES ('admin', 'Martin Vierling', 'mv@vimatec.de', ?, NULL, 1)
             ON DUPLICATE KEY UPDATE role = 'admin', name = 'Martin Vierling', specialty = NULL, is_active = 1"
        )->execute([password_hash($pass, PASSWORD_DEFAULT)]);
    }
}
