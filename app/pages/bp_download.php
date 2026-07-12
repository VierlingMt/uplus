<?php
/** Businessplan-PDF ausliefern (Auth + Rollen-/Schulprüfung). */
declare(strict_types=1);

Auth::require();

$bp = Database::one(
    'SELECT bp.*, t.school_id FROM business_plans bp JOIN teams t ON t.id = bp.team_id WHERE bp.id = ?',
    [(int) input('id')]
);
if (!$bp) { http_response_code(404); exit('Nicht gefunden.'); }

// Reine Lehrkräfte nur eigene Schule; Admin, Projektleitung & Jury alles.
$me = Auth::user();
$teacherOnly = Auth::has('teacher') && !Auth::isManager() && !Auth::has('juror');
if ($teacherOnly && (int) $bp['school_id'] !== (int) ($me['school_id'] ?? 0)) {
    http_response_code(403); exit('Kein Zugriff.');
}

$path = UPLOAD_PATH . '/plans/' . basename((string) $bp['stored_name']);
if (!is_file($path)) { http_response_code(404); exit('Datei fehlt.'); }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) ($bp['original_name'] ?: 'businessplan.pdf')) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
