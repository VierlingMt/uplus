<?php
/**
 * Mediendatei ausliefern (mit Auth-Prüfung). Galerie-Medien liegen außerhalb
 * des Web-Roots und werden nur über diesen Controller ausgegeben.
 *
 *   (ohne v)      → Original (Bild inline, Video mit HTTP-Range zum Streamen)
 *   v=thumb       → kleine Vorschau für die Kacheln (schnelles Laden)
 *   v=view        → mittlere Ansicht für die Lightbox
 *   download=1    → Original als Download (Originalgröße)
 *
 * Für Videos gibt es keine Bildvarianten; dort wird immer das Original mit
 * Range-Unterstützung geliefert.
 */
declare(strict_types=1);

Auth::require();

$m = Media::find((int) input('id'));
if (!$m) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

$download = (bool) input('download');
$variant  = (string) input('v');

// Auszuliefernden Pfad bestimmen: Download & Video immer Original; bei Bildern
// die angeforderte Variante (bei Bedarf erzeugt), sonst das Original.
$path = Media::dir() . '/' . basename((string) $m['stored_name']);
if (!$download && !$variant && $m['kind'] === Media::KIND_IMAGE) {
    // Ohne Angabe zeigen wir bei Bildern die „Ansicht“ (spart Bandbreite);
    // das Original gibt es über download=1.
    $variant = 'view';
}
if (!$download && $variant && $m['kind'] === Media::KIND_IMAGE) {
    $deriv = Media::ensureDerivative($m, $variant);
    if ($deriv !== null && is_file($deriv)) {
        $path = $deriv;
    }
}

if (!is_file($path)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$size = (int) filesize($path);
$isOriginal = ($path === Media::dir() . '/' . basename((string) $m['stored_name']));
$mime = $isOriginal
    ? (string) ($m['mime'] ?: (mime_content_type($path) ?: 'application/octet-stream'))
    : (mime_content_type($path) ?: 'image/webp');

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=604800'); // Varianten & Originale sind unveränderlich

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
