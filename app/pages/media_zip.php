<?php
/**
 * Medien als ZIP herunterladen (Originalgröße) – für angemeldete Nutzer:innen.
 *
 *   GET  ?cycle=<id>      → ganze Jahres-Galerie
 *   POST ids[]=…&cycle=…  → nur die ausgewählten Medien
 *
 * Alle angemeldeten Nutzer:innen dürfen die Galerien ansehen und damit auch
 * herunterladen. Der Aufbau (STORE, Temp-Datei, Streaming) steckt in Media.
 */
declare(strict_types=1);

Auth::require();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP wird vom Server nicht unterstützt.');
}

$cycleId = (int) input('cycle');
$rawIds  = input('ids');

if ($rawIds !== null) {
    // Auswahl-Download.
    $items = Media::byIds((array) $rawIds);
    $label = 'Auswahl';
} else {
    $cycle = Cycle::find($cycleId);
    if (!$cycle) {
        http_response_code(404);
        exit('Wettbewerbsjahr nicht gefunden.');
    }
    $items = Media::forCycle($cycleId);
    $label = (string) $cycle['year_label'];
}

if (!$items) {
    http_response_code(404);
    exit('Keine Medien vorhanden.');
}

$zipPath = Media::buildZip($items);
if ($zipPath === null) {
    http_response_code(500);
    exit('ZIP konnte nicht erstellt werden (evtl. zu wenig Speicherplatz).');
}

Audit::log('gallery.zip', count($items) . ' Medien als ZIP geladen (' . $label . ')', 'cycle', $cycleId);

$fname = 'Mediengalerie_' . preg_replace('/[^0-9A-Za-z_-]+/', '_', $label) . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($zipPath));
header('X-Content-Type-Options: nosniff');

@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}
$fh = fopen($zipPath, 'rb');
if ($fh !== false) {
    while (!feof($fh)) {
        echo fread($fh, 262144);
        flush();
    }
    fclose($fh);
}
@unlink($zipPath);
exit;
