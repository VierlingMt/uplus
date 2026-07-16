<?php
/**
 * Öffentlicher, temporärer Download-Link für geteilte Medien.
 *
 * Über ein zufälliges Token erreichbar (zum Weitergeben, OHNE Login):
 *   ?t=<token>          → Info-Seite (was ist enthalten, gültig bis, verbleibende
 *                          Downloads) mit Download-Knopf – für den Empfänger sichtbar.
 *   ?t=<token>&dl=1     → baut das ZIP, liefert es aus und zählt den Download.
 *
 * Der Link läuft nach Ablaufdatum ab und ist höchstens `max_downloads`-mal (Standard 2)
 * nutzbar; danach löscht er sich. Abgelaufene Links werden bei jedem Zugriff aufgeräumt.
 */
declare(strict_types=1);

/** Eigenständige (layout-freie) HTML-Seite für den öffentlichen Link ausgeben. */
if (!function_exists('share_render')) {
    function share_render(string $title, string $inner, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        $logo = asset('img/logo.svg');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>' . e($title) . ' – Unternehmen Plus</title>'
           . '<link rel="icon" href="' . e($logo) . '">'
           . '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">'
           . '<style>body{background:linear-gradient(160deg,#003594,#012a76);min-height:100vh;margin:0;'
           . 'display:flex;align-items:center;justify-content:center;padding:20px}'
           . '.share-card{background:#fff;border-radius:16px;box-shadow:0 14px 44px rgba(0,0,0,.28);max-width:520px;width:100%;padding:28px 30px}'
           . '.share-card h1{font-family:"Chivo",sans-serif;color:#003594;font-size:22px;margin:0 0 8px}'
           . '.share-brand{display:flex;align-items:center;gap:10px;margin-bottom:16px}'
           . '.share-brand img{width:38px;height:38px}'
           . '.share-meta{list-style:none;padding:0;margin:18px 0;border-top:1px solid #e2e7ec}'
           . '.share-meta li{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #e2e7ec}'
           . '.share-meta b{color:#003594}'
           . '.share-dl{display:block;text-align:center;margin-top:6px;font-size:17px;padding:14px}'
           . '</style></head><body><div class="share-card">'
           . '<div class="share-brand"><img src="' . e($logo) . '" alt="">'
           . '<div><strong style="font-family:Chivo,sans-serif;color:#003594">Unternehmen Plus</strong>'
           . '<br><span class="muted" style="font-size:13px">Mediengalerie</span></div></div>'
           . $inner . '</div></body></html>';
        exit;
    }

    function share_error(string $title, string $message): void
    {
        share_render($title, '<h1>' . e($title) . '</h1><p class="muted">' . e($message) . '</p>', 410);
    }
}

Media::deleteExpiredShares();

$token = (string) input('t');
$share = Media::findShareByToken($token);
if (!$share) {
    share_error('Link nicht verfügbar', 'Dieser Download-Link ist abgelaufen oder wurde bereits vollständig genutzt.');
}

$max       = max(1, (int) ($share['max_downloads'] ?? Media::SHARE_DEFAULT_DOWNLOADS));
$done      = (int) $share['downloads'];
$remaining = max(0, $max - $done);
if ($remaining <= 0) {
    Media::deleteShare((int) $share['id']);
    share_error('Link aufgebraucht', 'Dieser Download-Link wurde bereits ' . $max . '-mal genutzt und ist nicht mehr gültig.');
}

$items = Media::shareItems($share);
if (!$items) {
    Media::deleteShare((int) $share['id']);
    share_error('Keine Medien', 'Die geteilten Medien sind nicht mehr verfügbar.');
}

$expiresFmt = date('d.m.Y', (int) strtotime((string) $share['expires_at']));
$total = 0;
foreach ($items as $it) {
    $total += (int) ($it['size_bytes'] ?? 0);
}

// --- Info-Seite (kein dl) ---------------------------------------------------
if (!input('dl')) {
    $year = '';
    if (!empty($share['cycle_id'])) {
        $c = Cycle::find((int) $share['cycle_id']);
        $year = $c ? (string) $c['year_label'] : '';
    }
    $inner = '<h1>Geteilte Medien</h1>'
        . '<p class="muted" style="margin-top:0">Es wurden Medien aus der Mediengalerie mit dir geteilt. '
        . 'Du kannst sie als ZIP herunterladen – ganz ohne Anmeldung.</p>'
        . '<ul class="share-meta">'
        . '<li><span>Enthaltene Medien</span><b>' . count($items) . '</b></li>'
        . ($year !== '' ? '<li><span>Wettbewerbsjahr</span><b>' . e($year) . '</b></li>' : '')
        . '<li><span>Größe</span><b>' . e(human_size($total)) . '</b></li>'
        . '<li><span>Gültig bis</span><b>' . e($expiresFmt) . '</b></li>'
        . '<li><span>Verbleibende Downloads</span><b>' . $remaining . ' von ' . $max . '</b></li>'
        . '</ul>'
        . '<a class="btn btn--primary share-dl" href="' . e(url('share', ['t' => $token, 'dl' => 1])) . '">⬇ Jetzt herunterladen</a>'
        . '<p class="muted" style="font-size:13px;margin-top:14px">Der Link ist bis zum <strong>' . e($expiresFmt)
        . '</strong> gültig und kann noch <strong>' . $remaining . '-mal</strong> genutzt werden. '
        . 'Danach wird er automatisch gelöscht.</p>';
    share_render('Geteilte Medien', $inner);
}

// --- Download (dl=1) --------------------------------------------------------
$zipPath = Media::buildZip($items);
if ($zipPath === null) {
    share_error('Download fehlgeschlagen', 'Das Archiv konnte nicht erstellt werden. Bitte später erneut versuchen.');
}

$label = 'geteilt';
if (!empty($share['cycle_id'])) {
    $cycle = Cycle::find((int) $share['cycle_id']);
    if ($cycle) {
        $label = (string) $cycle['year_label'];
    }
}
$fname = 'Mediengalerie_' . preg_replace('/[^0-9A-Za-z_-]+/', '_', $label) . '.zip';

// Bis zum Ende ausliefern, auch wenn der Client kurz hakt.
ignore_user_abort(true);
@set_time_limit(0);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($zipPath));
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
    ob_end_clean();
}
$fh = fopen($zipPath, 'rb');
if ($fh !== false) {
    while (!feof($fh)) {
        echo fread($fh, 262144);
        flush();
        if (connection_aborted()) {
            break;
        }
    }
    fclose($fh);
}
@unlink($zipPath);

// Nur einen vollständigen Download zählen (löscht den Link beim Erreichen von max).
if (!connection_aborted()) {
    Media::registerShareDownload($share);
}
exit;
