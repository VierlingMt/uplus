<?php
/** Material-Datei ausliefern (mit Auth + Sichtbarkeitsprüfung). */
declare(strict_types=1);

Auth::require();

$m = Database::one('SELECT * FROM materials WHERE id = ?', [(int) input('id')]);
if (!$m || !$m['stored_name']) {
    http_response_code(404);
    exit('Nicht gefunden.');
}

// Sichtbarkeit prüfen
$role = Auth::role();
if (!Auth::isManager() && !in_array($m['visibility'], ['all', $role], true)) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

$path = UPLOAD_PATH . '/materials/' . basename((string) $m['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$name = $m['original_name'] ?: $m['stored_name'];
header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $name) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
