<?php
/**
 * Vorlage fuer config/config.local.php.
 *
 * Diese Datei NICHT mit echten Werten committen. Im Produktivbetrieb wird
 * config.local.php beim Deploy automatisch aus GitHub-Actions-Secrets erzeugt
 * (siehe .github/workflows/deploy.yml). Fuer lokale Tests kann sie manuell
 * angelegt werden.
 */

return [
    'db_host'           => 'localhost',
    'db_name'           => 'DEINE_DB',
    'db_user'           => 'DEIN_USER',
    'db_pass'           => 'DEIN_PASSWORT',

    'app_key'           => 'zufaelliger-langer-string',
    'anthropic_api_key' => 'sk-ant-...',
    'anthropic_model'   => 'claude-sonnet-5',
    'install_token'     => 'ein-geheimer-installer-token',

    // '' wenn die App im Web-Root liegt, sonst z.B. '/uplus'
    'base_path'         => '',

    // Passwortloser Login per Magic-Link (E-Mail):
    // Absolute Basis-URL (nur Schema+Host) fuer die Login-Links in den Mails.
    // Leer lassen = automatisch aus dem Request; explizit setzen ist robuster.
    'app_url'           => 'https://uplus.example.de',
    // Absender der System-Mails (sollte zur Domain passen -> Zustellbarkeit).
    'mail_from'         => 'no-reply@uplus.example.de',
    'mail_from_name'    => 'Unternehmen Plus',
];
