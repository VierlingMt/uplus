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
            [
                'version' => '2026_07_17_seed_team_members',
                'name'    => 'Teammitglieder aus den Businessplänen als Schüler:innen importieren',
                'up'      => [self::class, 'seedTeamMembers'],
            ],
            [
                'version' => '2026_07_18_structure_substance',
                'name'    => 'Struktur-Check: Eigentext-Wortzahl + manueller Override der Projektleitung',
                'up'      => "ALTER TABLE structure_checks
                    ADD COLUMN IF NOT EXISTS own_words INT NULL AFTER completeness_score",
            ],
            [
                'version' => '2026_07_19_structure_override',
                'name'    => 'Manueller Override des Struktur-Checks (am Plan gespeichert)',
                'up'      => 'ALTER TABLE business_plans
                    ADD COLUMN IF NOT EXISTS sc_override TINYINT NULL,
                    ADD COLUMN IF NOT EXISTS sc_override_by INT UNSIGNED NULL,
                    ADD COLUMN IF NOT EXISTS sc_override_reason VARCHAR(255) NULL,
                    ADD COLUMN IF NOT EXISTS sc_override_at DATETIME NULL',
            ],
            [
                'version' => '2026_07_20_sponsor_logo_backfill',
                'name'    => 'Bekannten Sponsoren den vorhandenen Logo-Pfad nachtragen (falls leer)',
                'up'      => [self::class, 'sponsorLogoBackfill'],
            ],
            [
                'version' => '2026_07_22_phone_normalize',
                'name'    => 'Bestehende Handynummern ins internationale Format (+49…) normalisieren',
                'up'      => [self::class, 'phoneNormalize'],
            ],
            [
                'version' => '2026_07_23_contact_changes',
                'name'    => 'Selbstverwaltete E-Mail-/Handynummer-Änderung mit Bestätigung',
                'up'      => "CREATE TABLE IF NOT EXISTS contact_changes (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id      INT UNSIGNED NOT NULL,
                    kind         ENUM('email','phone') NOT NULL,
                    new_value    VARCHAR(190) NOT NULL,
                    secret_hash  CHAR(64) NOT NULL,
                    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at   DATETIME NOT NULL,
                    used_at      DATETIME NULL,
                    requested_ip VARCHAR(45) NULL,
                    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_contact_changes_user (user_id),
                    KEY idx_contact_changes_secret (secret_hash),
                    KEY idx_contact_changes_expires (expires_at),
                    CONSTRAINT fk_contact_changes_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            [
                'version' => '2026_07_24_audit_log',
                'name'    => 'Audit-Log: wer hat wann was geändert (inkl. Login/Login-Versuche)',
                'up'      => "CREATE TABLE IF NOT EXISTS audit_log (
                    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    user_id    INT UNSIGNED NULL,
                    actor      VARCHAR(190) NULL,
                    action     VARCHAR(64) NOT NULL,
                    entity     VARCHAR(40) NULL,
                    entity_id  INT UNSIGNED NULL,
                    summary    VARCHAR(500) NULL,
                    ip         VARCHAR(45) NULL,
                    meta       TEXT NULL,
                    PRIMARY KEY (id),
                    KEY idx_audit_created (created_at),
                    KEY idx_audit_action (action),
                    KEY idx_audit_user (user_id),
                    KEY idx_audit_entity (entity, entity_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            [
                'version' => '2026_07_25_pitchday_events',
                'name'    => 'PitchDay-Eventplanung (Aufgaben, Gäste, Agenda, Budget) je Wettbewerbsjahr',
                'up'      => [self::class, 'pitchdayEvents'],
            ],
            [
                'version' => '2026_07_26_guest_substitute',
                'name'    => 'PitchDay-Gäste: Vertretung (wer vertritt wen) hinterlegen',
                'up'      => 'ALTER TABLE event_guests
                    ADD COLUMN IF NOT EXISTS sub_name     VARCHAR(190) NULL AFTER status,
                    ADD COLUMN IF NOT EXISTS sub_position VARCHAR(190) NULL AFTER sub_name,
                    ADD COLUMN IF NOT EXISTS sub_org      VARCHAR(190) NULL AFTER sub_position',
            ],
            [
                'version' => '2026_07_27_seed_martin_evaluations',
                'name'    => 'Jury-Bewertung von Martin Vierling (martin.vierling@vierling.de) an KI angelehnt',
                'up'      => [self::class, 'seedMartinEvaluations'],
            ],
            [
                'version' => '2026_07_28_user_roles',
                'name'    => 'Mehrfachrollen je Nutzer (user_roles) – Backfill aus users.role',
                'up'      => [self::class, 'userRoles'],
            ],
            [
                'version' => '2026_07_29_user_org_position',
                'name'    => 'Nutzer: Organisation & Position (selbst pflegbar, für Gästeliste)',
                'up'      => 'ALTER TABLE users
                    ADD COLUMN IF NOT EXISTS org      VARCHAR(190) NULL AFTER specialty,
                    ADD COLUMN IF NOT EXISTS position VARCHAR(190) NULL AFTER org',
            ],
            [
                'version' => '2026_07_30_user_phone_unique',
                'name'    => 'Handynummer systemweit eindeutig (UNIQUE, sofern keine Dubletten)',
                'up'      => [self::class, 'userPhoneUnique'],
            ],
            [
                'version' => '2026_07_31_guest_teacher_category',
                'name'    => 'PitchDay-Gäste: Kategorie „Lehrkraft" ergänzen',
                'up'      => "ALTER TABLE event_guests
                    MODIFY category ENUM('jury','vip','press','sponsor','speaker','teacher')
                    NOT NULL DEFAULT 'vip'",
            ],
            [
                'version' => '2026_07_32_cycle_member_teacher',
                'name'    => 'Wettbewerbsjahr auch für Lehrkräfte zuweisbar (cycle_members-Rolle)',
                'up'      => "ALTER TABLE cycle_members
                    MODIFY role_in_cycle ENUM('juror','project_lead','teacher')
                    NOT NULL DEFAULT 'juror'",
            ],
            [
                'version' => '2026_07_32b_guest_user_link',
                'name'    => 'PitchDay-Gäste mit Nutzerkonto verknüpfen (Live-Daten statt Kopie)',
                'up'      => [self::class, 'guestUserLink'],
            ],
            [
                'version' => '2026_07_33_webauthn_credentials',
                'name'    => 'Passkeys / WebAuthn (geräte­gebundener Login per Fingerabdruck/Face-ID)',
                'up'      => "CREATE TABLE IF NOT EXISTS webauthn_credentials (
                    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id       INT UNSIGNED NOT NULL,
                    credential_id VARCHAR(512) NOT NULL,
                    public_key    TEXT NOT NULL,
                    sign_count    INT UNSIGNED NOT NULL DEFAULT 0,
                    transports    VARCHAR(255) NULL,
                    label         VARCHAR(120) NULL,
                    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at  DATETIME NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_webauthn_cred (credential_id),
                    KEY idx_webauthn_user (user_id),
                    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
        ];
    }

    /**
     * Handynummer systemweit eindeutig machen. Fügt einen UNIQUE-Index hinzu –
     * aber nur, wenn keine doppelten (nicht-leeren) Nummern existieren; sonst
     * würde die Migration fehlschlagen. Mehrere NULL-Werte sind in MySQL bei
     * UNIQUE erlaubt (Nutzer ohne Handynummer). Existieren Dubletten, greift bis
     * zur Bereinigung weiterhin die App-Prüfung.
     */
    public static function userPhoneUnique(PDO $pdo): void
    {
        $idxExists = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'users'
               AND index_name = 'uq_users_phone'"
        )->fetchColumn();
        if ($idxExists) {
            return;
        }
        $dupes = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT phone FROM users
                WHERE phone IS NOT NULL AND phone <> ''
                GROUP BY phone HAVING COUNT(*) > 1
             ) d"
        )->fetchColumn();
        if ($dupes === 0) {
            $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_phone (phone)');
        }
    }

    /**
     * Vorbelegte Jury-Bewertung für Martin Vierling (Konto
     * martin.vierling@vierling.de – bewusst getrennt vom App-Admin mv@vimatec.de).
     *
     * Grundlage sind die KI-Vorbewertungen der aktuellen Businesspläne. Damit die
     * Bewertung menschlich wirkt und nicht 1:1 die KI kopiert, wird pro Kriterium
     * eine kleine, aber reproduzierbare Abweichung (−1 / 0 / +1) aufgeschlagen.
     * Zusätzlich fließt Martins persönliche Vorliebe ein: Ideen, bei denen echte
     * Anlagen, Maschinen oder Geräte erfunden und gebaut werden, bekommen einen
     * Bonus (v. a. auf „Geschäftsidee“ und „Unternehmensgründung/Umsetzung“).
     *
     * Idempotent: Teams, die von diesem Konto bereits eine Bewertung haben, werden
     * übersprungen (kein Überschreiben manueller Eingaben).
     */
    public static function seedMartinEvaluations(PDO $pdo): void
    {
        // 1) Juror-Konto sicherstellen (eigenes Konto, unabhängig vom Admin).
        $pdo->prepare(
            "INSERT INTO users (role, name, email, password_hash, specialty, is_active)
             VALUES ('juror', 'Martin Vierling', 'martin.vierling@vierling.de', NULL, ?, 1)
             ON DUPLICATE KEY UPDATE role = 'juror', name = 'Martin Vierling', is_active = 1"
        )->execute(['Technik & Anlagenbau']);
        $jurorId = (int) ($pdo->query("SELECT id FROM users WHERE email = 'martin.vierling@vierling.de'")->fetchColumn() ?: 0);
        if (!$jurorId) {
            return;
        }

        // 2) Dem aktiven Wettbewerbsjahr als Jury zuordnen (falls Zyklen existieren).
        $active = (int) ($pdo->query('SELECT id FROM competition_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        if ($active) {
            $pdo->prepare(
                "INSERT IGNORE INTO cycle_members (cycle_id, user_id, role_in_cycle, specialty) VALUES (?,?, 'juror', ?)"
            )->execute([$active, $jurorId, 'Technik & Anlagenbau']);
        }

        // 3) Je aktuellem Businessplan mit fertiger KI-Bewertung eine eigene
        //    Jury-Bewertung ableiten.
        $plans = $pdo->query(
            "SELECT bp.id AS bp_id, bp.team_id, t.name AS team_name, t.idea_name, t.idea_pitch,
                    ae.id AS ae_id, ae.summary, ae.strengths, ae.weaknesses
               FROM business_plans bp
               JOIN teams t ON t.id = bp.team_id
               JOIN ai_evaluations ae ON ae.id = (
                   SELECT ae2.id FROM ai_evaluations ae2
                    WHERE ae2.business_plan_id = bp.id AND ae2.status = 'done'
                    ORDER BY ae2.id DESC LIMIT 1
               )
              WHERE bp.is_current = 1"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $findScores = $pdo->prepare('SELECT criterion_key, score FROM ai_evaluation_scores WHERE ai_evaluation_id = ?');
        $hasEval    = $pdo->prepare('SELECT id FROM evaluations WHERE juror_id = ? AND team_id = ?');
        $insEval    = $pdo->prepare(
            'INSERT INTO evaluations (juror_id, team_id, bp_submitted, pitch_submitted, bp_total, pitch_total, grand_total)
             VALUES (?,?,1,0,?,NULL,?)'
        );
        $insScore   = $pdo->prepare(
            'INSERT INTO evaluation_scores (evaluation_id, criterion_key, phase, points, notes) VALUES (?,?,?,?,?)'
        );

        foreach ($plans as $p) {
            $teamId = (int) $p['team_id'];

            // Bereits bewertet? Dann nichts überschreiben.
            $hasEval->execute([$jurorId, $teamId]);
            if ($hasEval->fetchColumn()) {
                continue;
            }

            // KI-Einzelscores laden.
            $findScores->execute([(int) $p['ae_id']]);
            $ai = [];
            foreach ($findScores->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ai[(string) $r['criterion_key']] = (float) $r['score'];
            }
            if (!$ai) {
                continue;
            }

            // Baut das Team ein echtes Gerät / eine Maschine / Anlage? -> Bonus.
            $text = mb_strtolower(
                (string) $p['team_name'] . ' ' . (string) $p['idea_name'] . ' ' . (string) $p['idea_pitch'] . ' ' .
                (string) $p['summary'] . ' ' . (string) $p['strengths'] . ' ' . (string) $p['weaknesses']
            );
            $maker = self::looksLikeHardware($text);

            $bpTotal = 0;
            $rows = [];
            foreach (array_keys(Criteria::BUSINESSPLAN) as $key) {
                $pts = (int) round($ai[$key] ?? 0.0);
                $pts += self::seedDelta((int) $p['bp_id'], $key); // leichte Abweichung −1/0/+1
                if ($maker) {
                    // Martins Steckenpferd: erfundene/gebaute Geräte, Maschinen, Anlagen.
                    $pts += ($key === 'idea' || $key === 'foundation') ? 2 : 1;
                }
                $pts = max(0, min(10, $pts));
                $rows[$key] = $pts;
                $bpTotal += $pts;
            }

            $grand = (int) Criteria::grandTotal((float) $bpTotal, 0.0); // Pitch noch offen
            $insEval->execute([$jurorId, $teamId, $bpTotal, $grand]);
            $evalId = (int) $pdo->lastInsertId();
            foreach ($rows as $key => $pts) {
                $insScore->execute([$evalId, $key, 'businessplan', $pts, null]);
            }
        }
    }

    /**
     * Kleine, reproduzierbare Abweichung (−1/0/+1) je Businessplan+Kriterium, damit
     * die abgeleitete Jury-Bewertung nicht exakt die KI-Punkte spiegelt.
     */
    private static function seedDelta(int $bpId, string $key): int
    {
        $m = crc32($bpId . '|' . $key) % 10;
        if ($m < 3) { return -1; } // ~30 %
        if ($m < 7) { return 0; }  // ~40 %
        return 1;                  // ~30 %
    }

    /**
     * Heuristik: Deutet der Text (Team-/Ideenname + KI-Zusammenfassung) darauf hin,
     * dass ein echtes physisches Gerät / eine Maschine / Anlage erfunden und gebaut
     * wird? Bewusst großzügig – der Bonus soll Martins Vorliebe abbilden.
     */
    private static function looksLikeHardware(string $text): bool
    {
        $needles = [
            'gerät', 'maschine', 'anlage', 'prototyp', 'sensor', 'roboter', 'hardware',
            'elektronik', 'vorrichtung', 'apparat', 'mechanik', 'mechanismus', 'konstru',
            '3d-druck', '3d druck', 'bauteil', 'platine', 'mikrocontroller', 'arduino',
            'raspberry', 'ladegerät', 'akku', 'solar', 'scanner', 'motor', 'gebaut',
        ];
        foreach ($needles as $n) {
            if (mb_strpos($text, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * PitchDay-Eventplanung: Veranstaltung, Aufgaben-Checkliste, Gäste/VIPs,
     * Ablaufplan und Budget – alles am Wettbewerbsjahr aufgehängt. Für das
     * aktive Jahr wird direkt ein PitchDay mit dem Standard-Playbook und der
     * Standard-Agenda vorbelegt (idempotent).
     */
    public static function pitchdayEvents(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS events (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                cycle_id      INT UNSIGNED NOT NULL,
                type          VARCHAR(40) NOT NULL DEFAULT 'pitchday',
                title         VARCHAR(190) NOT NULL,
                event_date    DATE NULL,
                time_from     TIME NULL,
                venue         VARCHAR(190) NULL,
                venue_address VARCHAR(255) NULL,
                notes         TEXT NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_events_cycle (cycle_id),
                CONSTRAINT fk_events_cycle FOREIGN KEY (cycle_id)
                    REFERENCES competition_cycles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS event_tasks (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id    INT UNSIGNED NOT NULL,
                category    VARCHAR(40) NOT NULL DEFAULT 'general',
                title       VARCHAR(190) NOT NULL,
                responsible VARCHAR(120) NULL,
                status      ENUM('open','requested','confirmed','done') NOT NULL DEFAULT 'open',
                due_date    DATE NULL,
                offset_days INT NULL,
                comment     VARCHAR(500) NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tasks_event (event_id),
                CONSTRAINT fk_tasks_event FOREIGN KEY (event_id)
                    REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS event_guests (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id         INT UNSIGNED NOT NULL,
                category         ENUM('jury','vip','press','sponsor','speaker') NOT NULL DEFAULT 'vip',
                name             VARCHAR(190) NOT NULL,
                org              VARCHAR(190) NULL,
                position         VARCHAR(190) NULL,
                email            VARCHAR(190) NULL,
                invite_channel   VARCHAR(60) NULL,
                status           ENUM('open','requested','confirmed','declined','substitute') NOT NULL DEFAULT 'open',
                greeting         TINYINT(1) NOT NULL DEFAULT 0,
                greeting_minutes INT NULL,
                keynote          TINYINT(1) NOT NULL DEFAULT 0,
                seat_reserved    TINYINT(1) NOT NULL DEFAULT 0,
                notes            VARCHAR(500) NULL,
                sort_order       INT NOT NULL DEFAULT 0,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_guests_event (event_id),
                CONSTRAINT fk_guests_event FOREIGN KEY (event_id)
                    REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS event_agenda (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id   INT UNSIGNED NOT NULL,
                time_from  TIME NULL,
                time_to    TIME NULL,
                title      VARCHAR(190) NOT NULL,
                note       VARCHAR(300) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_agenda_event (event_id),
                CONSTRAINT fk_agenda_event FOREIGN KEY (event_id)
                    REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS event_budget_items (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id   INT UNSIGNED NOT NULL,
                kind       ENUM('cost','prize') NOT NULL DEFAULT 'cost',
                label      VARCHAR(190) NOT NULL,
                amount     DECIMAL(10,2) NULL,
                place      INT NULL,
                note       VARCHAR(300) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_budget_event (event_id),
                CONSTRAINT fk_budget_event FOREIGN KEY (event_id)
                    REFERENCES events(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Für das aktive Wettbewerbsjahr direkt einen PitchDay vorbereiten,
        // solange dort noch keiner existiert.
        $active = (int) ($pdo->query('SELECT id FROM competition_cycles WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
        if (!$active) {
            return;
        }
        $has = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE cycle_id = $active AND type = 'pitchday'")->fetchColumn();
        if ($has > 0) {
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO events (cycle_id, type, title, venue) VALUES (?, 'pitchday', ?, ?)"
        );
        $ins->execute([$active, 'PitchDay', 'Stadthalle Ebermannstadt']);
        $eventId = (int) $pdo->lastInsertId();

        // Playbook-Aufgaben (ohne Event-Datum → Fälligkeiten werden gesetzt,
        // sobald die Projektleitung das Datum einträgt).
        $order = array_keys(PitchDay::TASK_CATEGORIES);
        $t = $pdo->prepare(
            'INSERT INTO event_tasks (event_id, category, title, status, offset_days, sort_order) VALUES (?,?,?,?,?,?)'
        );
        foreach (PitchDay::TEMPLATE_TASKS as $i => [$cat, $title, $offset]) {
            $sort = (array_search($cat, $order, true) * 100) + $i;
            $t->execute([$eventId, $cat, $title, 'open', $offset, $sort]);
        }

        // Standard-Agenda.
        $a = $pdo->prepare(
            'INSERT INTO event_agenda (event_id, time_from, time_to, title, sort_order) VALUES (?,?,?,?,?)'
        );
        foreach (PitchDay::AGENDA_TEMPLATE as $i => [$from, $to, $title]) {
            $a->execute([$eventId, $from, $to, $title, ($i + 1) * 10]);
        }
    }

    /**
     * Mehrfachrollen je Nutzer. Bisher trug jeder Nutzer genau eine Rolle
     * (`users.role`). Manche Personen sind aber zugleich z. B. Jury UND
     * Projektleitung (und ggf. Admin). Diese Tabelle bildet die n:m-Beziehung ab;
     * `users.role` bleibt als „Hauptrolle" (höchste Berechtigung) erhalten und
     * wird beim Speichern synchron gehalten. Backfill übernimmt die bisherige
     * Einzelrolle jedes Nutzers (nur beim ersten Lauf, wenn die Tabelle leer ist).
     */
    public static function userRoles(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_roles (
                user_id INT UNSIGNED NOT NULL,
                role    ENUM('admin','lead','teacher','juror') NOT NULL,
                PRIMARY KEY (user_id, role),
                KEY idx_user_roles_role (role),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $count = (int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn();
        if ($count === 0) {
            $pdo->exec('INSERT IGNORE INTO user_roles (user_id, role) SELECT id, role FROM users');
        }
    }

    /**
     * PitchDay-Gäste mit dem Nutzerkonto verknüpfen. Ist `user_id` gesetzt, zieht
     * die App Name/Organisation/Position/E-Mail live aus „Jury & Nutzer" – so
     * wirken Profil-Änderungen sofort, ohne Neu-Import. Manuelle Gäste ohne Konto
     * bleiben eine eigenständige Kopie. Backfill verknüpft bestehende Gäste per
     * eindeutigem Namen (best effort).
     */
    public static function guestUserLink(PDO $pdo): void
    {
        $col = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'event_guests' AND column_name = 'user_id'"
        )->fetchColumn();
        if (!$col) {
            $pdo->exec('ALTER TABLE event_guests ADD COLUMN user_id INT UNSIGNED NULL AFTER event_id');
        }
        $idx = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'event_guests' AND index_name = 'idx_guests_user'"
        )->fetchColumn();
        if (!$idx) {
            $pdo->exec('ALTER TABLE event_guests ADD KEY idx_guests_user (user_id)');
        }
        $fk = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = 'event_guests' AND constraint_name = 'fk_guests_user'"
        )->fetchColumn();
        if (!$fk) {
            $pdo->exec('ALTER TABLE event_guests
                ADD CONSTRAINT fk_guests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
        }
        // Bestehende Jury-/Lehrkraft-/VIP-Gäste per eindeutigem Namen verknüpfen.
        $pdo->exec(
            "UPDATE event_guests g
             JOIN users u ON u.name = g.name
             SET g.user_id = u.id
             WHERE g.user_id IS NULL
               AND g.category IN ('jury','teacher','vip')
               AND (SELECT COUNT(*) FROM users u2 WHERE u2.name = g.name) = 1"
        );
    }

    /** Alle hinterlegten Handynummern ins internationale Format ohne Leerzeichen bringen. */
    public static function phoneNormalize(PDO $pdo): void
    {
        $rows = $pdo->query("SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone <> ''")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?');
        foreach ($rows as $r) {
            $norm = phone_normalize((string) $r['phone']);
            if ($norm !== null && $norm !== $r['phone']) {
                $upd->execute([$norm, (int) $r['id']]);
            }
        }
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
     * Bekannten Sponsoren den passenden Logo-Pfad nachtragen, wenn dieser (etwa
     * weil die ursprüngliche Seed-Migration früher ohne Logos lief) noch leer
     * ist. Ändert nur Datensätze ohne Logo – vom Nutzer gepflegte Logos bleiben
     * unangetastet. Idempotent.
     */
    public static function sponsorLogoBackfill(PDO $pdo): void
    {
        $upd = $pdo->prepare(
            "UPDATE sponsors SET logo_path = ?
             WHERE name = ? AND (logo_path IS NULL OR logo_path = '')"
        );
        foreach (self::SEED_SPONSORS as [$name, $logo]) {
            $upd->execute([$logo, $name]);
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

    /**
     * Teammitglieder aus den eingereichten Businessplänen als Schüler:innen
     * anlegen. Die Namen sind aus den PDFs in storage/seed_plans extrahiert und
     * je Plan-Dateiname (= business_plans.original_name) hinterlegt. Zuordnung
     * erfolgt über den aktuellen Businessplan des jeweiligen Teams.
     *
     * Hinweise zur Datenlage:
     *  - Wo die Pläne nur Vor- oder abgekürzte Nachnamen nennen (z. B. „Anna T.“,
     *    „Neele K.“) ist das 1:1 übernommen.
     *  - „EGF_B1_Businessplan_Worksy.pdf“ nennt auf dem Deckblatt „Carla, Carla,
     *    Salma, Mia“ – der doppelte Vorname ist als ein Eintrag übernommen.
     *  - „9b_4youcafe.pdf“ und „9b_Heimatbox.pdf“ enthalten keine Namen und
     *    bleiben ohne Mitglieder.
     * Idempotent: Teams, die bereits Mitglieder haben, werden übersprungen.
     */
    public static function seedTeamMembers(PDO $pdo): void
    {
        $byPlan = [
            'EGF_10a_1_V7SprayserBusinessplan.pdf' => ['Maximilian Halach', 'Jan Phillip Henneberg', 'Paul Müller', 'David Steurer', 'Matteo Zeus'],
            'EGF_10a_2_BusinessPlanSmartDesk.pdf' => ['Liam Wagner', 'Eric Donath', 'Maximilian Schmitt', 'Bruno Várallyay', 'Philipp Kaiser', 'Ayushmaan Tank'],
            'EGF_10a_3_BusinessplanApp.pdf' => ['Lea Sammet', 'Robin Sennst', 'Ella Steinmann', 'Kilian Schindler', 'Emmylou Kießling'],
            'EGF_10a_4_Businessplan - Schullink.PDF' => ['Kiana Afschari', 'Emilia Romanenko', 'Marie Yokotani', 'Neele Lange', 'Tianyi Yang'],
            'EGF_10a_5_AERO-SMART Businessplan.pdf' => ['Emily Kleinke', 'Anna Eberlein', 'Marlene Ahlers', 'Lucia Strenglein', 'Sophie Duwe'],
            'EGF_B1_Businessplan_Worksy.pdf' => ['Carla', 'Salma', 'Mia'],
            'EGF_B2_Businessplan_Bio-Catering.pdf' => ['David M/R', 'Felix Baier', 'Jonas Karger'],
            'EGF_B4_Businessplan_Mintopia GmbH.pdf' => ['Clara Istratescu', 'Julia Hippacher', 'Anja Hempel', 'Ines Oueslati'],
            'EGF_B6_ Businessplan_Local Steps.PDF' => ['Louisa Schmidt', 'Dameris Kraus', 'Lena Blumauer', 'Paula Schneider', 'Nora Meise'],
            'EGF_D1_FashionSwap Businessplan.pdf' => ['Abdal Rahman Aessa', 'Danny Alnakola', 'Tarek Alsheikh Salo', 'Jano Scordo', 'Ben Buschmeyer', 'Leonas Wabra'],
            'EGF_D2_NexPack - Buissnisplan.pdf' => ['Amelie Thierfelder', 'Elena Kröppel', 'Sarah Worsch', 'Chiara Grunow', 'Elena Gallmetzer', 'Celina Töpfer'],
            'EGF_D3_Businessplan-VitaBox.pdf' => ['Michael Bongartz', 'Raffael Zenk', 'Vinzenz Klatt', 'Ben Wirth', 'David Pachuntke', 'Tom Schönfelder'],
            'EGF_D4_Businessplan.pdf' => ['Anni Blümlein', 'Anna Hutzler', 'Elena Utzmann', 'Lea Schütz'],
            'EGF_D5_ShapeBite - Business-Plan 3.pdf' => ['Ceren Bagriyanik', 'Leonardo Schießl', 'Vincent Bober', 'Lena Schmitt'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C1- ScanPen.pdf' => ['Ida Hartmann', 'Gustav Mayer', 'Lea Schaffer', 'Leni Schuster'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C2 - LMT_Last_Minute_Table.pdf' => ['Elias', 'Tobi', 'Gabriel', 'Anna'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C3 - wor-CO-fé.pdf' => ['Sophie Bögelein', 'Julius Andersen', 'Franziska Wohlleber', 'Sarah Brug', 'Marlene Perle'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C4 - Ur Styl.pdf' => ['Ela Karabag', 'Ena Frick', 'Tom Billes', 'Alisa Engler', 'Simon Petersammer'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C5 - Nap Air.PDF' => ['Alina Post', 'Gizem Kutlu', 'Aneta Chadová', 'Lukas Braun', 'Johanna Klein'],
            'Unternehmen Plus Businessplan 2025_26 EGF 10C Team-C6 - Soundventure.pdf' => ['Lea Düsterberg', 'Isabel Trode', 'Julian Shaw', 'Anja Bezold', 'Kora Gschoßmann'],
            'Businessplan 10a_prove your food.pdf' => ['Franka Wibiral', 'Fiona Hack', 'Amelie Brütting', 'Chloe Billman', 'Marie Sitzmann'],
            'Businessplan_10a_focus mat.pdf' => ['Helene Herold', 'Antonia Stark', 'Melissa Gieser', 'Pauline Hofmann', 'Pia Forstner'],
            'Businessplan_10a_novigo.pdf' => ['Clarissa Möck', 'Justus Rackelmann', 'Lea Richter', 'Luisa Hostalka', 'Sophia Zipfel', 'Viktoria Mourick'],
            'Businessplan_10a_safe band.pdf' => ['Maja Zargartalebi', 'Anna-Lena Frenzel', 'Luisa Grey', 'Sebastian Vogel', 'Marie Hack'],
            'Businessplan_10b_bay mi.pdf' => ['Benedikt Klaassen', 'David Gabler', 'Hannes Brunner', 'Fabian Herbst', 'Nathan Opitz', 'Elias Vogel'],
            'Businessplan_10b_high protein döner.pdf' => ['Charlotte Thor', 'Elisa Grasser', 'Janina Göhl', 'Leon Krahl', 'Sinah Hornung'],
            'Businessplan_10b_level up.pdf' => ['Valentina', 'Emilie', 'Johann', 'Johanna'],
            'Businessplan_10c_FrankenGO.PDF' => ['Bruno', 'Vivien', 'Justus', 'Jonas'],
            'Businessplan_10c_assistify.pdf' => ['Lukas Höck', 'Raphael Altemeier', 'Mario Wrede', 'Mika Seitz', 'Enrico Thiem'],
            'Businessplan_10c_mellow.pdf' => ['Cajetan v. Pölnitz', 'Leonas Dippold', 'Julian Dorsch', 'Mats Massobust', 'Max H. Porzelt'],
            '10a_KÜMMR.pdf' => ['Serena S.', 'Joelle L.', 'Erik M.', 'Johanna H.'],
            '10a_SafariExpress.pdf' => ['Emma Hack', 'Emma Brehm', 'Melissa Lleshi', 'Hanna Singer', 'Lena Frömel'],
            '10a_Schülercafe.pdf' => ['Lilli', 'Yasmin', 'Anna T.', 'Kristin', 'Anna S.'],
            '10a_infiniteat.pdf' => ['Lena Spitzer', 'Elli Dick', 'Leo Wilk', 'Marlon Kaupper', 'Theo Keuchl'],
            '10b_Bowlmemaybe.pdf' => ['Greta Deckert', 'Kimberly Reichl', 'Paula Röttger', 'Leonie House', 'Pia Heidorn', 'Lina Groß'],
            '10b_Chstyle.pdf' => ['Karla Gerlach', 'Helen Wendt', 'Lara Wüstner', 'Helena Werber'],
            '10b_Kosmoseum.pdf' => ['Annemarie', 'Lena', 'Meike', 'Sophie'],
            '10b_SortiSmart.pdf' => ['Sophie Pöhnlein', 'Maya Ben M’barek', 'Helena Werner', 'Gabriel Bewer', 'Josef Huber'],
            '10b_SunCharger.pdf' => ['Neele K.', 'Marie D.', 'Lisa W.', 'Felicitas L.', 'Emma S.', 'Lisa N.'],
            '9b_Mounte.pdf' => ['Montgomery Mart Morant', 'Rene Trautner', 'Philipp de Boer', 'Ben Kraus', 'Phil Alter'],
            '9b_Schüsselglück.pdf' => ['Jessie Potzner', 'Laura Werber', 'Johanna Balbach', 'Nina Glaser', 'Sophie Nützel'],
            '9b_SoftnCrunch.pdf' => ['Maja Heidorn', 'Leni Schmitt', 'Klara Böhm', 'Emilia Ponner', 'Livleen Bhullar', 'Jule Stilkerich'],
        ];

        // Dateinamen können je nach System als NFC oder NFD (zerlegte Umlaute)
        // vorliegen; deshalb den Abgleich über eine normalisierte Fassung führen.
        $lookup = [];
        foreach ($byPlan as $plan => $names) {
            $lookup[self::nfc($plan)] = $names;
        }

        $rows     = $pdo->query('SELECT team_id, original_name FROM business_plans WHERE is_current = 1')->fetchAll();
        $hasStud  = $pdo->prepare('SELECT COUNT(*) FROM students WHERE team_id = ?');
        $insStud  = $pdo->prepare('INSERT INTO students (team_id, name) VALUES (?, ?)');

        foreach ($rows as $row) {
            $names = $lookup[self::nfc((string) $row['original_name'])] ?? null;
            if (!$names) { continue; }
            $teamId = (int) $row['team_id'];
            $hasStud->execute([$teamId]);
            if ((int) $hasStud->fetchColumn() > 0) { continue; } // bereits gepflegt
            foreach ($names as $name) {
                $insStud->execute([$teamId, $name]);
            }
        }
    }

    /**
     * Unicode-Normalisierung nach NFC (zusammengesetzte Umlaute). Nutzt die
     * intl-Erweiterung, falls vorhanden; sonst ein Fallback für die im Projekt
     * vorkommenden dekomponierten Zeichen (deutsche Umlaute, Akzente).
     */
    private static function nfc(string $s): string
    {
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if ($n !== false) { return $n; }
        }
        return strtr($s, [
            "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
            "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
            "e\u{0301}" => 'é', "e\u{0300}" => 'è',
        ]);
    }
}
