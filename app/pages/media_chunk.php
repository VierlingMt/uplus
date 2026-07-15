<?php
/**
 * Chunk-Upload-Endpunkt für die Mediengalerie (JSON-API).
 *
 * Große Videos (bis Media::maxUploadBytes()) werden im Browser in kleine Stücke
 * zerlegt und einzeln hochgeladen – so umgehen wir die PHP-Request-Limits
 * (post_max_size / upload_max_filesize), die einen 2-GB-Upload sonst verhindern.
 *
 *   phase=chunk    → hängt ein Stück an storage/uploads/gallery/tmp/<id>.part an
 *   phase=finalize → validiert, verschiebt in die Galerie, legt den DB-Eintrag an
 *
 * Antwort ist immer JSON: { ok: bool, ... }.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** JSON ausgeben und beenden. */
function jexit(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

Auth::require();

if (!is_post()) {
    jexit(['ok' => false, 'error' => 'POST erforderlich.'], 405);
}
if (!Access::canWrite('gallery')) {
    jexit(['ok' => false, 'error' => 'Keine Berechtigung.'], 403);
}
// CSRF (Token als Formularfeld – jedes Stück ist klein, $_POST bleibt intakt).
if (!hash_equals(Csrf::token(), (string) ($_POST['_csrf'] ?? ''))) {
    jexit(['ok' => false, 'error' => 'Sitzung abgelaufen. Bitte Seite neu laden.'], 419);
}

$cycleId = (int) input('cycle_id');
if (!Media::canUploadTo($cycleId)) {
    jexit(['ok' => false, 'error' => 'In dieses Wettbewerbsjahr darfst du nicht hochladen.'], 403);
}

// Upload-ID: vom Client erzeugt, streng auf Hex begrenzt (kein Path-Traversal).
$uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('upload_id'));
if (strlen((string) $uploadId) < 8 || strlen((string) $uploadId) > 64) {
    jexit(['ok' => false, 'error' => 'Ungültige Upload-Kennung.'], 400);
}

if (!Media::ensureTmpDir()) {
    jexit(['ok' => false, 'error' => 'Temporärer Ordner fehlt oder ist nicht beschreibbar.'], 500);
}
Media::cleanupTmp(); // verwaiste Reste gelegentlich aufräumen

$tmpPath = Media::tmpDir() . '/' . $uploadId . '.part';
$phase   = (string) input('phase');

// --- Ein Stück anhängen ----------------------------------------------------
if ($phase === 'chunk') {
    $offset = (int) input('offset');
    if (empty($_FILES['chunk']) || !is_uploaded_file($_FILES['chunk']['tmp_name'] ?? '')) {
        jexit(['ok' => false, 'error' => 'Kein Datenstück empfangen.'], 400);
    }
    $chunkSize = (int) $_FILES['chunk']['size'];
    $curSize   = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;

    // Idempotenz: identisches Stück bereits geschrieben (Retry nach Timeout)?
    if ($offset < $curSize && $curSize === $offset + $chunkSize) {
        jexit(['ok' => true, 'received' => $curSize]);
    }
    // Reihenfolge muss stimmen (Client lädt sequvia nacheinander).
    if ($curSize !== $offset) {
        jexit(['ok' => false, 'error' => 'Falscher Offset.', 'received' => $curSize], 409);
    }
    // Gesamtgröße begrenzen.
    if ($offset + $chunkSize > Media::maxUploadBytes()) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Datei zu groß (max. ' . human_size(Media::maxUploadBytes()) . ').'], 413);
    }

    $in  = @fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = @fopen($tmpPath, 'ab');
    if (!$in || !$out) {
        if ($in) { fclose($in); }
        if ($out) { fclose($out); }
        jexit(['ok' => false, 'error' => 'Schreibfehler.'], 500);
    }
    $copied = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($copied === false) {
        jexit(['ok' => false, 'error' => 'Stück konnte nicht gespeichert werden.'], 500);
    }
    clearstatcache(true, $tmpPath);
    jexit(['ok' => true, 'received' => (int) filesize($tmpPath)]);
}

// --- Abschließen: validieren, verschieben, eintragen -----------------------
if ($phase === 'finalize') {
    $name = (string) input('name');
    $type = Media::typeFor($name);
    if ($type === null) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Format nicht erlaubt.'], 415);
    }
    if (!is_file($tmpPath)) {
        jexit(['ok' => false, 'error' => 'Keine Daten empfangen.'], 400);
    }
    $size = (int) filesize($tmpPath);
    if ($size <= 0) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Leere Datei.'], 400);
    }
    if ($size > Media::maxUploadBytes()) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Datei zu groß.'], 413);
    }
    // Optionaler Abgleich mit der vom Client gemeldeten Größe.
    $expected = (int) input('size');
    if ($expected > 0 && $expected !== $size) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Übertragung unvollständig – bitte erneut versuchen.'], 400);
    }
    // Bilder als echtes Bild verifizieren (Videos nicht prüfbar).
    if ($type['kind'] === Media::KIND_IMAGE && @getimagesize($tmpPath) === false) {
        @unlink($tmpPath);
        jexit(['ok' => false, 'error' => 'Kein gültiges Bild.'], 415);
    }
    if (!Media::ensureDir()) {
        jexit(['ok' => false, 'error' => 'Upload-Ordner fehlt.'], 500);
    }

    $stored = bin2hex(random_bytes(12)) . '.' . $type['ext'];
    $dest   = Media::dir() . '/' . $stored;
    if (!@rename($tmpPath, $dest)) {
        // Fallback über die Ordnergrenze hinweg (z. B. anderes Dateisystem).
        if (!@copy($tmpPath, $dest)) {
            jexit(['ok' => false, 'error' => 'Speichern fehlgeschlagen.'], 500);
        }
        @unlink($tmpPath);
    }

    $taken = Media::extractTakenAt($dest, $type['kind'], $type['mime']);
    $id = Database::insert(
        'INSERT INTO media_items (cycle_id, uploaded_by, kind, stored_name, original_name, mime, size_bytes, taken_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$cycleId, Auth::id(), $type['kind'], $stored, mb_substr($name, 0, 255), $type['mime'], $size, $taken]
    );
    // Vorschau-/Ansichtsvarianten erzeugen (nur Bilder; best effort).
    Media::buildDerivatives(['stored_name' => $stored, 'kind' => $type['kind']]);
    Audit::log('gallery.upload', '1 Medium hochgeladen (' . human_size($size) . ')', 'media', $id);
    jexit(['ok' => true, 'id' => $id]);
}

jexit(['ok' => false, 'error' => 'Unbekannte Phase.'], 400);
