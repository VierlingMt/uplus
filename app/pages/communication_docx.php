<?php
/**
 * Pressemitteilung als Word-Dokument (.docx) ausliefern – Fließtext + Bildanhang
 * (jeweils mit Bildunterschrift und Fotograf). Wird bei Bedarf frisch erzeugt.
 *
 * Zugriff: Verwaltung jederzeit; alle anderen nur bei veröffentlichten Beiträgen.
 */
declare(strict_types=1);

Auth::require();
Access::requireRead('communication');

$item = Communication::find((int) input('id'));
if (!$item) {
    http_response_code(404);
    exit('Nicht gefunden.');
}
if (Communication::kindOf((string) $item['type']) !== 'press') {
    http_response_code(404);
    exit('Das Word-Dokument gibt es nur für Pressemitteilungen.');
}
if ($item['status'] !== 'published' && !Auth::isManager()) {
    http_response_code(403);
    exit('Kein Zugriff.');
}
if (trim((string) ($item['body'] ?? '')) === '') {
    http_response_code(404);
    exit('Für diesen Beitrag gibt es noch keinen Text.');
}

@set_time_limit(120);
if (!Communication::ensurePdfDir()) {
    http_response_code(500);
    exit('Speicherordner nicht verfügbar.');
}

$tmp = Communication::pdfDir() . '/tmp_' . bin2hex(random_bytes(8)) . '.docx';
if (!Communication::buildDocxTo($item, $tmp)) {
    @unlink($tmp);
    http_response_code(500);
    exit('Das Word-Dokument konnte nicht erzeugt werden.');
}

$name = Communication::docxFilename($item);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($tmp));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($tmp);
@unlink($tmp);
exit;
