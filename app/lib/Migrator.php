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
        ];
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

        // --- Benutzer (Projektleitung + Jury) ---
        // Projektleitung = admin. Weitere Juror:innen = juror.
        // Für Konten ohne bekannte E-Mail wird eine Platzhalter-Adresse gesetzt
        // (in "Jury & Nutzer" später korrigierbar). Passwörter setzt die
        // Projektleitung im Nutzer-Modul; nur das Start-Admin-Konto hat eins.
        $adminEmail = strtolower((string) cfg('seed_admin_email', 'mv@vimatec.de'));
        $adminPass  = (string) cfg('seed_admin_password', 'UPlus-Start!2026');

        $users = [
            ['admin', 'Martin Vierling',  $adminEmail,               password_hash($adminPass, PASSWORD_DEFAULT), 'Unternehmer & Gründer'],
            ['admin', 'Anton Schreiber',  'anton@wirduzen.de',       null, 'Unternehmer'],
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
            'admin', 'Martin Vierling (App-Admin)', 'mv@vimatec.de',
            password_hash($pass, PASSWORD_DEFAULT), 'App-Verwaltung (dauerhaft)',
        ]);
    }
}
