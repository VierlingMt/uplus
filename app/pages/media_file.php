<?php
/**
 * Mediendatei ausliefern (mit Auth-Prüfung). Bilder & Videos der Galerie liegen
 * außerhalb des Web-Roots und werden nur über diesen Controller ausgegeben.
 * Für Videos wird HTTP-Range (206) unterstützt, damit Abspielen/Spulen im
 * Browser zuverlässig funktioniert. Jede:r angemeldete Nutzer:in darf ansehen.
 */
declare(strict_types=1);

Auth::require();

$m = Media::find((int) input('id'));
if (!$m) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$path = Media::dir() . '/' . basename((string) $m['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$size = filesize($path);
$mime = (string) ($m['mime'] ?: (mime_content_type($path) ?: 'application/octet-stream'));
$download = (bool) input('download');

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');

if ($download) {
    $name = (string) ($m['original_name'] ?: $m['stored_name']);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
}

// --- Range-Anfrage (Teilauslieferung, v. a. für Videos) --------------------
$start = 0;
$end   = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';

if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $mm)) {
    if ($mm[1] !== '') {
        $start = (int) $mm[1];
    }
    if ($mm[2] !== '') {
        $end = (int) $mm[2];
    }
    if ($mm[1] === '' && $mm[2] !== '') {
        // Suffix-Range: letzte N Bytes.
        $start = max(0, $size - (int) $mm[2]);
        $end   = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $end = min($end, $size - 1);
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

// Ausgabepuffer leeren, damit große Dateien nicht in den Speicher passen müssen.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fh = fopen($path, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
if ($start > 0) {
    fseek($fh, $start);
}
$remaining = $length;
$chunk = 8192;
while ($remaining > 0 && !feof($fh)) {
    $read = fread($fh, (int) min($chunk, $remaining));
    if ($read === false) {
        break;
    }
    echo $read;
    flush();
    $remaining -= strlen($read);
}
fclose($fh);
exit;
