<?php
/**
 * Gesamte Galerie eines Wettbewerbsjahres als ZIP herunterladen (Originale).
 *
 * Alle angemeldeten Nutzer:innen dürfen die Galerien ansehen und damit auch
 * herunterladen. Die Medien sind bereits komprimiert (JPG/MP4/…), daher wird
 * ohne erneute Kompression (STORE) gepackt – schnell und speicherschonend.
 * Das ZIP wird in den Temp-Ordner geschrieben, ausgeliefert und danach gelöscht.
 */
declare(strict_types=1);

Auth::require();

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP wird vom Server nicht unterstützt.');
}

$cycleId = (int) input('cycle');
$cycle   = Cycle::find($cycleId);
if (!$cycle) {
    http_response_code(404);
    exit('Wettbewerbsjahr nicht gefunden.');
}

$items = Media::forCycle($cycleId);
if (!$items) {
    http_response_code(404);
    exit('Keine Medien vorhanden.');
}
if (!Media::ensureTmpDir()) {
    http_response_code(500);
    exit('Temporärer Ordner fehlt.');
}

// Alte ZIP-Reste aufräumen (abgebrochene Downloads), > 1 h.
foreach (glob(Media::tmpDir() . '/zip_*.zip') ?: [] as $old) {
    if (@filemtime($old) < time() - 3600) {
        @unlink($old);
    }
}

@set_time_limit(0);

$zipPath = Media::tmpDir() . '/zip_' . bin2hex(random_bytes(8)) . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('ZIP konnte nicht erstellt werden.');
}

/** Eindeutigen, lesbaren Eintragsnamen bilden: „JJJJ-MM-TT_Person_Original.ext". */
$used = [];
$entryName = static function (array $m) use (&$used): string {
    $src  = !empty($m['taken_at']) ? (string) $m['taken_at'] : (string) ($m['created_at'] ?? '');
    $ts   = $src !== '' ? strtotime($src) : false;
    $date = $ts ? date('Y-m-d', $ts) : '0000-00-00';
    $orig = (string) ($m['original_name'] ?: $m['stored_name']);
    $ext  = pathinfo($orig, PATHINFO_EXTENSION);
    $base = pathinfo($orig, PATHINFO_FILENAME);
    $who  = (string) ($m['uploader_name'] ?? '');
    // Auf dateisystemfreundliche Zeichen reduzieren.
    $san  = static fn(string $s): string => trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $s), '_');
    $name = $date . ($who !== '' ? '_' . $san($who) : '') . '_' . ($san($base) ?: 'medium');
    $name = mb_substr($name, 0, 120);
    $file = $name . ($ext !== '' ? '.' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $ext)) : '');
    // Kollisionen vermeiden.
    $try = $file; $i = 2;
    while (isset($used[mb_strtolower($try)])) {
        $try = $name . '_' . $i . ($ext !== '' ? '.' . strtolower($ext) : '');
        $i++;
    }
    $used[mb_strtolower($try)] = true;
    return $try;
};

$added = 0;
foreach ($items as $m) {
    $path = Media::dir() . '/' . basename((string) $m['stored_name']);
    if (!is_file($path)) {
        continue;
    }
    $entry = $entryName($m);
    if ($zip->addFile($path, $entry)) {
        // Bereits komprimierte Medien nicht erneut komprimieren (schnell, wenig CPU).
        if (method_exists($zip, 'setCompressionName')) {
            @$zip->setCompressionName($entry, ZipArchive::CM_STORE);
        }
        $added++;
    }
}

if ($added === 0) {
    $zip->close();
    @unlink($zipPath);
    http_response_code(404);
    exit('Keine Dateien vorhanden.');
}
if (!$zip->close()) {
    @unlink($zipPath);
    http_response_code(500);
    exit('ZIP konnte nicht abgeschlossen werden (evtl. zu wenig Speicherplatz).');
}

Audit::log('gallery.zip', $added . ' Medien als ZIP geladen (' . (string) $cycle['year_label'] . ')', 'cycle', $cycleId);

$fname = 'Mediengalerie_' . preg_replace('/[^0-9A-Za-z_-]+/', '_', (string) $cycle['year_label']) . '.zip';

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
    }
    fclose($fh);
}
@unlink($zipPath);
exit;
