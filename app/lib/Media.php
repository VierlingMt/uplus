<?php
/**
 * Mediengalerie – Bilder & Videos je Wettbewerbsjahr.
 *
 * Jede:r angemeldete Nutzer:in darf Medien für das eigene Wettbewerbsjahr
 * hochladen (Mehrfachauswahl möglich) und die eigenen Beiträge bearbeiten oder
 * löschen. Projektleitung und Admin verwalten alle Beiträge. Angesehen werden
 * dürfen die Galerien aller Jahre von allen.
 *
 * Dateien liegen unter storage/uploads/gallery (nicht direkt per Web
 * erreichbar) und werden ausschließlich über den Controller `media_file`
 * mit Auth-Prüfung ausgeliefert.
 */

declare(strict_types=1);

final class Media
{
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';

    /** Erlaubte Bildtypen: Endung => MIME. */
    public const IMAGE_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** Erlaubte Videotypen (browsertauglich): Endung => MIME. */
    public const VIDEO_TYPES = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogv'  => 'video/ogg',
        'ogg'  => 'video/ogg',
        'mov'  => 'video/quicktime',
    ];

    /** Speicherort der Mediendateien. */
    public static function dir(): string
    {
        return UPLOAD_PATH . '/gallery';
    }

    public static function ensureDir(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Maximale Dateigröße für den klassischen (einteiligen) Upload. Orientiert
     * sich an den PHP-Limits der .user.ini (upload_max_filesize = 64M). Größere
     * Dateien laufen über den Chunk-Upload (siehe maxUploadBytes()).
     */
    public static function maxBytes(): int
    {
        return 64 * 1024 * 1024;
    }

    /**
     * Obergrenze für den stückweisen (Chunk-)Upload großer Videos. Umgeht die
     * PHP-Request-Limits, da jedes Stück einzeln übertragen wird. Benötigt 64-Bit
     * PHP (Dateigrößen > 2 GB) – auf modernem Hosting Standard.
     */
    public static function maxUploadBytes(): int
    {
        return 2 * 1024 * 1024 * 1024; // 2 GB
    }

    /** Größe eines Chunks (Client & Server müssen nicht exakt übereinstimmen). */
    public const CHUNK_BYTES = 5 * 1024 * 1024;

    /** Temporärer Sammelordner für laufende Chunk-Uploads. */
    public static function tmpDir(): string
    {
        return self::dir() . '/tmp';
    }

    public static function ensureTmpDir(): bool
    {
        if (!self::ensureDir()) {
            return false;
        }
        $d = self::tmpDir();
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return is_dir($d) && is_writable($d);
    }

    /** Verwaiste Chunk-Reste (abgebrochene Uploads) älter als 24 h entfernen. */
    public static function cleanupTmp(): void
    {
        $d = self::tmpDir();
        if (!is_dir($d)) {
            return;
        }
        $cutoff = time() - 24 * 3600;
        foreach (glob($d . '/*.part') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    /**
     * Art und MIME-Typ anhand der Dateiendung bestimmen.
     * @return array{kind:string,ext:string,mime:string}|null
     */
    public static function typeFor(string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (isset(self::IMAGE_TYPES[$ext])) {
            return ['kind' => self::KIND_IMAGE, 'ext' => $ext, 'mime' => self::IMAGE_TYPES[$ext]];
        }
        if (isset(self::VIDEO_TYPES[$ext])) {
            return ['kind' => self::KIND_VIDEO, 'ext' => $ext, 'mime' => self::VIDEO_TYPES[$ext]];
        }
        return null;
    }

    /** Menschlich lesbare Liste der erlaubten Formate (für Hinweise). */
    public static function allowedHint(): string
    {
        return 'Bilder (JPG, PNG, GIF, WEBP) und Videos (MP4, WEBM, MOV) · große Videos '
            . 'bis ' . human_size(self::maxUploadBytes()) . ' werden automatisch stückweise hochgeladen';
    }

    // --- Aufnahmedatum aus Metadaten ------------------------------------

    /**
     * Aufnahmedatum (Aufnahmezeitpunkt) aus den Metadaten lesen. Fotos über EXIF,
     * MP4/MOV-Videos über das mvhd-Atom. Liefert „Y-m-d H:i:s“ oder null, wenn
     * kein plausibles Datum ermittelbar ist (dann greift die Fallback-Sortierung
     * nach Upload-Zeit).
     */
    public static function extractTakenAt(string $path, string $kind, ?string $mime): ?string
    {
        try {
            if ($kind === self::KIND_IMAGE) {
                return self::imageTakenAt($path, (string) $mime);
            }
            if ($kind === self::KIND_VIDEO) {
                $ts = self::videoCreationTime($path);
                return $ts !== null ? date('Y-m-d H:i:s', $ts) : null;
            }
        } catch (\Throwable $e) {
            // Metadaten sind „nice to have“ – niemals den Upload gefährden.
        }
        return null;
    }

    private static function imageTakenAt(string $path, string $mime): ?string
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }
        // EXIF gibt es sinnvoll nur bei JPEG/TIFF (PNG/GIF/WEBP i. d. R. ohne).
        if (!in_array($mime, ['image/jpeg', 'image/tiff'], true)) {
            return null;
        }
        $ex = @exif_read_data($path);
        if (!is_array($ex)) {
            return null;
        }
        $dt = $ex['DateTimeOriginal'] ?? $ex['DateTimeDigitized'] ?? $ex['DateTime'] ?? null;
        if (!is_string($dt) || !preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $dt, $m)) {
            return null;
        }
        $year = (int) $m[1];
        if ($year < 1990 || $year > 2100) {
            return null; // unplausibel (z. B. leeres/0000-Datum)
        }
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }

    /** Unix-Zeit aus dem mvhd-Atom eines MP4/MOV (best effort) oder null. */
    private static function videoCreationTime(string $path): ?int
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }
        try {
            $size = (int) filesize($path);
            return self::scanForMvhd($fh, 0, $size, 0);
        } finally {
            fclose($fh);
        }
    }

    /** Durchsucht die Atome von MP4/MOV nach 'moov' → 'mvhd' (per Seek, ohne alles zu laden). */
    private static function scanForMvhd($fh, int $start, int $end, int $depth): ?int
    {
        if ($depth > 3) {
            return null;
        }
        $pos = $start;
        while ($pos + 8 <= $end) {
            fseek($fh, $pos);
            $hdr = fread($fh, 8);
            if ($hdr === false || strlen($hdr) < 8) {
                break;
            }
            $size = unpack('N', substr($hdr, 0, 4))[1];
            $type = substr($hdr, 4, 4);
            $headerLen = 8;
            if ($size === 1) { // 64-Bit-Größe folgt
                $ext = fread($fh, 8);
                if ($ext === false || strlen($ext) < 8) {
                    break;
                }
                $size = unpack('J', $ext)[1];
                $headerLen = 16;
            } elseif ($size === 0) {
                $size = $end - $pos; // reicht bis zum Ende
            }
            if ($size < $headerLen) {
                break;
            }
            $payloadStart = $pos + $headerLen;
            $payloadEnd   = min($pos + $size, $end);

            if ($type === 'mvhd') {
                fseek($fh, $payloadStart);
                $vf = fread($fh, 4); // 1 Byte version + 3 Byte flags
                if ($vf === false || strlen($vf) < 4) {
                    return null;
                }
                if (ord($vf[0]) === 1) {
                    $ct = fread($fh, 8);
                    if ($ct === false || strlen($ct) < 8) {
                        return null;
                    }
                    $secs = unpack('J', $ct)[1];
                } else {
                    $ct = fread($fh, 4);
                    if ($ct === false || strlen($ct) < 4) {
                        return null;
                    }
                    $secs = unpack('N', $ct)[1];
                }
                return self::macTimeToUnix((int) $secs);
            }
            if ($type === 'moov') {
                $r = self::scanForMvhd($fh, $payloadStart, $payloadEnd, $depth + 1);
                if ($r !== null) {
                    return $r;
                }
            }
            $pos += $size;
        }
        return null;
    }

    /** QuickTime-Zeit (Sekunden seit 1904-01-01) → Unix; null bei 0/unplausibel. */
    private static function macTimeToUnix(int $secs): ?int
    {
        $epoch = 2082844800; // Sekunden zwischen 1904-01-01 und 1970-01-01
        if ($secs <= $epoch) {
            return null; // 0 = unbekannt, oder vor 1970
        }
        $unix = $secs - $epoch;
        // Plausibilität: ab 1990 bis knapp in die Zukunft.
        if ($unix < 631152000 || $unix > time() + 400 * 86400) {
            return null;
        }
        return $unix;
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM media_items WHERE id = ?', [$id]);
    }

    /**
     * Medien eines Zyklus samt Name der hochladenden Person. Sortiert nach
     * Aufnahmedatum (neueste zuerst); fehlt es, greift die Upload-Zeit.
     */
    public static function forCycle(int $cycleId): array
    {
        return Database::all(
            'SELECT m.*, u.name AS uploader_name
               FROM media_items m
               LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.cycle_id = ?
              ORDER BY COALESCE(m.taken_at, m.created_at) DESC, m.id DESC',
            [$cycleId]
        );
    }

    /** Anzahl Medien je Zyklus. @return array<int,int> */
    public static function counts(): array
    {
        $out = [];
        foreach (Database::all('SELECT cycle_id, COUNT(*) AS n FROM media_items GROUP BY cycle_id') as $r) {
            $out[(int) $r['cycle_id']] = (int) $r['n'];
        }
        return $out;
    }

    // --- Berechtigungen -------------------------------------------------

    /** Verwaltung (Admin oder Projektleitung) – darf alle Beiträge bearbeiten. */
    public static function canManage(): bool
    {
        return Auth::isManager();
    }

    /** Darf der aktuelle Nutzer diesen Beitrag bearbeiten/löschen? */
    public static function canEdit(array $item): bool
    {
        if (Auth::isManager()) {
            return true;
        }
        return $item['uploaded_by'] !== null && (int) $item['uploaded_by'] === Auth::id();
    }

    /**
     * Darf der aktuelle Nutzer in dieses Wettbewerbsjahr hochladen?
     * Verwaltung überall; alle anderen in das aktive Jahr sowie in Jahre, an
     * denen sie teilnehmen („ihr Wettbewerbsjahr").
     */
    public static function canUploadTo(int $cycleId): bool
    {
        if ($cycleId <= 0) {
            return false;
        }
        if (Auth::isManager()) {
            return true;
        }
        if ($cycleId === Cycle::activeId()) {
            return true;
        }
        return in_array($cycleId, Cycle::forUser(Auth::id() ?? 0), true);
    }
}
