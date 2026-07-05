<?php
/**
 * Einmaliger Generator für die PWA-Icons (App-Symbol „U+" im WJ-Corporate-Design).
 * Erzeugt PNGs in assets/img/icons/ – ausgeführt via `php scripts/gen_pwa_icons.php`.
 * Kein Laufzeit-Code; nur Build-Hilfe, damit installierbare Icons ohne externe
 * Rasterisierung (kein rsvg/inkscape auf Shared-Hosting nötig) entstehen.
 */

declare(strict_types=1);

$out = dirname(__DIR__) . '/assets/img/icons';
@mkdir($out, 0775, true);

$font = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';
if (!is_file($font)) {
    fwrite(STDERR, "Font nicht gefunden: $font\n");
    exit(1);
}

$blue = [0x00, 0x35, 0x94];
$teal = [0x47, 0xD7, 0xAC];
$white = [0xFF, 0xFF, 0xFF];

/**
 * Zeichnet das Icon in gewünschter Größe.
 * @param float $scale Anteil (0–1) des Monogramms an der Kantenlänge (kleiner = mehr Rand, für maskable).
 */
function make_icon(int $size, float $scale, string $font, array $blue, array $teal, array $white): \GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagealphablending($im, true);

    $cBlue = imagecolorallocate($im, ...$blue);
    $cTeal = imagecolorallocate($im, ...$teal);
    $cWhite = imagecolorallocate($im, ...$white);

    imagefilledrectangle($im, 0, 0, $size, $size, $cBlue);

    // Türkiser Akzentbalken unten (Markenwiedererkennung)
    $barH = (int) round($size * 0.06);
    imagefilledrectangle($im, 0, $size - $barH, $size, $size, $cTeal);

    // Monogramm „U+" mittig
    $fontSize = $size * scaleFactor($scale);
    $text = 'U+';
    $bbox = imagettfbbox($fontSize, 0, $font, $text);
    $textW = $bbox[2] - $bbox[0];
    $textH = $bbox[1] - $bbox[7];
    $x = (int) round(($size - $textW) / 2 - $bbox[0]);
    $y = (int) round(($size - $textH) / 2 - $bbox[7] - $barH * 0.35);
    imagettftext($im, $fontSize, 0, $x, $y, $cWhite, $font, $text);

    return $im;
}

function scaleFactor(float $scale): float
{
    return $scale; // Punktgröße = Kantenlänge * scale
}

$targets = [
    ['icon-192.png',          192, 0.46],
    ['icon-512.png',          512, 0.46],
    ['icon-maskable-192.png', 192, 0.34],
    ['icon-maskable-512.png', 512, 0.34],
    ['apple-touch-icon.png',  180, 0.46],
];

foreach ($targets as [$name, $size, $scale]) {
    $im = make_icon($size, $size * $scale / $size, $font, $blue, $teal, $white);
    imagepng($im, "$out/$name");
    imagedestroy($im);
    echo "  $name ({$size}px)\n";
}

echo "PWA-Icons erstellt in $out\n";
