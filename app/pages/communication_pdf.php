<?php
/**
 * Ausliefern der veröffentlichten Pressemitteilungs-PDF (mit Auth-Prüfung).
 *
 * Die PDFs liegen außerhalb des Web-Roots (storage/uploads/communication) und
 * werden nur über diesen Controller ausgegeben. Nicht-Verwalter sehen die PDF
 * ausschließlich bei veröffentlichten Beiträgen; die Verwaltung auch im Entwurf.
 */
declare(strict_types=1);

Auth::require();
Access::requireRead('communication');

$item = Communication::find((int) input('id'));
if (!$item || empty($item['pdf_path'])) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

// Nur veröffentlichte Beiträge sind für alle einsehbar; Entwürfe nur die Verwaltung.
if ($item['status'] !== 'published' && !Auth::isManager()) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

$path = Communication::pdfDir() . '/' . basename((string) $item['pdf_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$name = (string) ($item['pdf_name'] ?: 'Pressemitteilung.pdf');
$disposition = input('download') ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, max-age=3600');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($path);
exit;
