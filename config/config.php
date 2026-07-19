<?php
/**
 * Zentrale Konfiguration.
 *
 * Sensible Werte (DB-Zugang, API-Keys) liegen NICHT im Git. Sie kommen aus:
 *   1. config/config.local.php  – wird beim Deploy aus GitHub-Actions-Secrets erzeugt
 *   2. Umgebungsvariablen        – Fallback (z. B. lokale Entwicklung)
 *
 * Zugriff im Code ausschliesslich ueber cfg('schluessel').
 */

declare(strict_types=1);

// Anwendungsversion (bei relevanten Änderungen zusammen mit CHANGELOG.md pflegen).
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.80.1');
}

$defaults = [
    'db_host'           => getenv('DB_HOST')  ?: 'localhost',
    'db_name'           => getenv('DB_NAME')  ?: '',
    'db_user'           => getenv('DB_USER')  ?: '',
    'db_pass'           => getenv('DB_PASS')  ?: '',
    'db_charset'        => 'utf8mb4',

    'app_name'          => 'Unternehmen Plus',
    'app_env'           => getenv('APP_ENV')  ?: 'production',
    'app_key'           => getenv('APP_KEY')  ?: '',          // fuer CSRF/Signaturen
    'base_path'         => getenv('BASE_PATH') ?: '',          // '' wenn im Web-Root, sonst z.B. '/uplus'
    // Absolute Basis-URL (nur Schema+Host) fuer Links in E-Mails (Magic-Link-Login).
    // Leer = automatisch aus dem Request abgeleitet, z. B. 'https://uplus.example.de'.
    'app_url'           => getenv('APP_URL') ?: '',

    // E-Mail-Versand (passwortloser Magic-Link-Login). Leer = no-reply@<Host>.
    'mail_from'         => getenv('MAIL_FROM')      ?: '',
    'mail_from_name'    => getenv('MAIL_FROM_NAME') ?: 'Unternehmen Plus',

    // KI-Vorbewertung (Anthropic Claude)
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    'anthropic_model'   => getenv('ANTHROPIC_MODEL')   ?: 'claude-sonnet-5',

    // Einmaliger Installer-Token (schuetzt install.php)
    'install_token'     => getenv('INSTALL_TOKEN') ?: '',

    // Externe Inhalte
    'explainer_video'   => 'https://www.youtube.com/watch?v=a1adG0Xuq0o',

    // Upload-Limits
    'upload_max_bytes'  => 25 * 1024 * 1024, // 25 MB je Businessplan
];

$local = __DIR__ . '/config.local.php';
$overrides = is_file($local) ? (require $local) : [];

$GLOBALS['__CONFIG'] = array_merge($defaults, is_array($overrides) ? $overrides : []);

/** Konfigurationswert lesen. */
function cfg(string $key, $default = null)
{
    return $GLOBALS['__CONFIG'][$key] ?? $default;
}
