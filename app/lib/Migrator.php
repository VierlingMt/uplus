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
}
