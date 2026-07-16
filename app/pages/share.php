<?php
/**
 * Öffentlicher, temporärer Download-Link für geteilte Medien.
 *
 * Über ein zufälliges Token erreichbar (zum Weitergeben, OHNE Login). Der Link
 * läuft nach Ablaufdatum ab und löscht sich – wenn als „einmalig" angelegt –
 * zusätzlich nach dem ersten vollständigen Download. Abgelaufene Links werden
 * bei jedem Zugriff mit aufgeräumt.
 */
declare(strict_types=1);

Media::deleteExpiredShares();

$token = (string) input('t');
$share = Media::findShareByToken($token);

if (!$share) {
    http_response_code(410);
    render('error', [
        'title'   => 'Link nicht verfügbar',
        'message' => 'Dieser Download-Link ist abgelaufen oder wurde bereits verwendet.',
    ]);
    return;
}

$items = Media::shareItems($share);
if (!$items) {
    // Nichts mehr vorhanden (Medien gelöscht) → Link entfernen.
    Media::deleteShare((int) $share['id']);
    http_response_code(410);
    render('error', [
        'title'   => 'Keine Medien',
        'message' => 'Die geteilten Medien sind nicht mehr verfügbar.',
    ]);
    return;
}

$zipPath = Media::buildZip($items);
if ($zipPath === null) {
    http_response_code(500);
    render('error', [
        'title'   => 'Download fehlgeschlagen',
        'message' => 'Das Archiv konnte nicht erstellt werden. Bitte später erneut versuchen.',
    ]);
    return;
}

$label = 'geteilt';
if (!empty($share['cycle_id'])) {
    $cycle = Cycle::find((int) $share['cycle_id']);
    if ($cycle) {
        $label = (string) $cycle['year_label'];
    }
}
$fname = 'Mediengalerie_' . preg_replace('/[^0-9A-Za-z_-]+/', '_', $label) . '.zip';

// Nach vollständiger Auslieferung ggf. aufräumen – auch wenn der Client die
// Verbindung trennt, laufen wir bis zum Ende (ignore_user_abort).
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

// Erfolgreicher (vollständiger) Download?
if (!connection_aborted()) {
    Database::run('UPDATE media_shares SET downloads = downloads + 1 WHERE id = ?', [(int) $share['id']]);
    if ((int) $share['one_time'] === 1) {
        Media::deleteShare((int) $share['id']);
    }
}
exit;
