<?php
/**
 * Erzeugt config/config.local.php aus Umgebungsvariablen (Deploy-Secrets).
 * Wird im GitHub-Actions-Deploy vor dem FTP-Upload ausgefuehrt.
 * var_export sorgt fuer sicheres Escaping (auch bei Sonderzeichen im Passwort).
 */

declare(strict_types=1);

$appKey = getenv('APP_KEY') ?: bin2hex(random_bytes(32));

$cfg = [
    'db_host'           => getenv('DB_HOST') ?: 'localhost',
    'db_name'           => getenv('DB_NAME') ?: '',
    'db_user'           => getenv('DB_USER') ?: '',
    'db_pass'           => getenv('DB_PASS') ?: '',
    'app_env'           => 'production',
    'app_key'           => $appKey,
    'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    'anthropic_model'   => getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-5',
    'base_path'         => getenv('BASE_PATH') ?: '',
];

// Passwortloser Login per Magic-Link: Basis-URL + Mail-Absender.
if ($u = getenv('APP_URL'))        { $cfg['app_url'] = $u; }
if ($f = getenv('MAIL_FROM'))      { $cfg['mail_from'] = $f; }
if ($n = getenv('MAIL_FROM_NAME')) { $cfg['mail_from_name'] = $n; }

if ($e = getenv('SEED_ADMIN_EMAIL'))    { $cfg['seed_admin_email'] = $e; }
if ($p = getenv('SEED_ADMIN_PASSWORD')) { $cfg['seed_admin_password'] = $p; }

$out = "<?php\n// AUTOMATISCH beim Deploy erzeugt – nicht committen, nicht bearbeiten.\nreturn "
     . var_export($cfg, true) . ";\n";

$target = __DIR__ . '/../config/config.local.php';
file_put_contents($target, $out);

fwrite(STDOUT, sprintf(
    "config.local.php geschrieben (db_name=%s, ai_key=%s, app_key=%s).\n",
    $cfg['db_name'] !== '' ? 'gesetzt' : '—',
    $cfg['anthropic_api_key'] !== '' ? 'gesetzt' : 'fehlt',
    getenv('APP_KEY') ? 'aus Secret' : 'generiert'
));
