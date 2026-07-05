<?php
/**
 * Bootstrap: laedt Konfiguration, Bibliotheken, startet Session.
 * Wird von index.php und install.php eingebunden.
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_PATH', STORAGE_PATH . '/uploads');

require ROOT_PATH . '/config/config.php';

// Einfache Klassen-Autoloader fuer app/lib
spl_autoload_register(static function (string $class): void {
    $file = APP_PATH . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/lib/helpers.php';

// Fehleranzeige nur ausserhalb der Produktion
if (cfg('app_env') === 'production') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Session sicher konfigurieren
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('uplus_sess');
    session_start();
}

// Automatische Schema-Migration bei jedem Aufruf.
try {
    Migrator::run();
} catch (Throwable $e) {
    if ((string) cfg('db_name') === '') {
        fail_page('Konfiguration fehlt',
            "Die Datenbank ist noch nicht konfiguriert.\n"
            . 'Bitte config/config.local.php anlegen (oder Deploy-Secrets setzen).');
    }
    error_log('[uplus] Migration/DB-Fehler: ' . $e->getMessage());
    if (cfg('app_env') !== 'production') {
        fail_page('Datenbankfehler', $e->getMessage());
    }
    fail_page('Datenbank nicht erreichbar', 'Bitte versuche es in einigen Minuten erneut.');
}
