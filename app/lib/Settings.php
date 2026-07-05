<?php
/**
 * Anwendungseinstellungen (Key/Value in Tabelle `settings`).
 * Für in der App änderbare Konfiguration wie KI-Zugang, Wettbewerbs-Parameter.
 */

declare(strict_types=1);

final class Settings
{
    private static array $cache = [];
    private static bool $loaded = false;

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        foreach (Database::all('SELECT k, v FROM settings') as $row) {
            self::$cache[$row['k']] = $row['v'];
        }
        self::$loaded = true;
    }

    public static function get(string $key, $default = null)
    {
        self::load();
        $v = self::$cache[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function set(string $key, ?string $value): void
    {
        Database::run(
            'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            [$key, $value]
        );
        self::$cache[$key] = $value;
        self::$loaded = true;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }
}
