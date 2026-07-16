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

    // --- Vorschau-/Ansichtsvarianten (Thumbnails) -----------------------

    /** Varianten und ihre maximale Kantenlänge (Original bleibt unangetastet). */
    public const VARIANTS = [
        'thumb' => 500,   // Kacheln in der Galerie
        'view'  => 1600,  // Lightbox / Großansicht
    ];

    /** Dateiendung der generierten Varianten (WEBP, sonst JPEG). */
    public static function derivExt(): string
    {
        return function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    public static function variantDir(string $variant): string
    {
        return self::dir() . '/' . $variant;
    }

    /** Zielpfad einer Variante zu einem Medien-Datensatz. */
    public static function variantPath(array $item, string $variant): string
    {
        $base = pathinfo((string) $item['stored_name'], PATHINFO_FILENAME);
        return self::variantDir($variant) . '/' . $base . '.' . self::derivExt();
    }

    /**
     * Liefert den Pfad zur gewünschten Bildvariante und erzeugt sie bei Bedarf.
     * Nur für Bilder; sonst (oder bei Fehlern) null → der Aufrufer liefert das
     * Original aus. Die Variante wird auf der Platte gecacht (einmalig erzeugt).
     */
    public static function ensureDerivative(array $item, string $variant): ?string
    {
        if (($item['kind'] ?? '') !== self::KIND_IMAGE || !isset(self::VARIANTS[$variant])) {
            return null;
        }
        if (!extension_loaded('gd')) {
            return null;
        }
        $orig = self::dir() . '/' . basename((string) $item['stored_name']);
        if (!is_file($orig)) {
            return null;
        }
        $dest = self::variantPath($item, $variant);
        if (is_file($dest) && @filemtime($dest) >= @filemtime($orig)) {
            return $dest; // bereits aktuell
        }
        $dir = self::variantDir($variant);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        try {
            return self::generateImageVariant($orig, $dest, self::VARIANTS[$variant]) ? $dest : null;
        } catch (\Throwable $e) {
            @unlink($dest);
            return null;
        }
    }

    /** Alle Vorschau-/Ansichtsvarianten eines Bildes (idempotent) erzeugen. */
    public static function buildDerivatives(array $item): void
    {
        if (($item['kind'] ?? '') !== self::KIND_IMAGE) {
            return;
        }
        foreach (array_keys(self::VARIANTS) as $v) {
            self::ensureDerivative($item, $v);
        }
    }

    /** Verkleinerte Variante mit GD erzeugen (EXIF-Ausrichtung berücksichtigt). */
    private static function generateImageVariant(string $src, string $dest, int $maxDim): bool
    {
        $info = @getimagesize($src);
        if ($info === false) {
            return false;
        }
        [$w, $h] = $info;
        $type = $info[2];
        if ($w < 1 || $h < 1) {
            return false;
        }

        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            default        => false,
        };
        if (!$srcImg) {
            return false;
        }

        $scale = min(1.0, $maxDim / max($w, $h)); // nie hochskalieren
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $useWebp = self::derivExt() === 'webp';
        $dst = imagecreatetruecolor($nw, $nh);
        if ($useWebp) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        } else {
            // JPEG kennt keine Transparenz → weißer Hintergrund.
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($srcImg);

        // Handy-Fotos: Ausrichtung aus EXIF korrigieren (nur JPEG).
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex = @exif_read_data($src);
            $angle = match ((int) ($ex['Orientation'] ?? 0)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0 && function_exists('imagerotate')) {
                $bg = $useWebp ? imagecolorallocatealpha($dst, 0, 0, 0, 127) : imagecolorallocate($dst, 255, 255, 255);
                $rot = imagerotate($dst, $angle, $bg);
                if ($rot !== false) {
                    imagedestroy($dst);
                    $dst = $rot;
                    if ($useWebp) { imagealphablending($dst, false); imagesavealpha($dst, true); }
                }
            }
        }

        $ok = $useWebp ? @imagewebp($dst, $dest, 82) : @imagejpeg($dst, $dest, 82);
        imagedestroy($dst);
        return (bool) $ok;
    }

    /** Original samt aller generierten Varianten löschen. */
    public static function deleteFiles(array $item): void
    {
        @unlink(self::dir() . '/' . basename((string) $item['stored_name']));
        foreach (array_keys(self::VARIANTS) as $v) {
            @unlink(self::variantPath($item, $v));
        }
    }

    // --- ZIP-Erstellung (ganze Galerie / Auswahl / Share) ---------------

    /**
     * Medien anhand einer ID-Liste laden (nur existierende), sortiert nach
     * Aufnahmedatum. Für Auswahl-Downloads und geteilte Links.
     */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return Database::all(
            "SELECT m.*, u.name AS uploader_name
               FROM media_items m
               LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.id IN ($in)
              ORDER BY COALESCE(m.taken_at, m.created_at) DESC, m.id DESC",
            $ids
        );
    }

    /**
     * Baut aus den übergebenen Medien ein ZIP (Originale, ohne erneute
     * Kompression) im Temp-Ordner und liefert den Pfad – oder null bei Fehler
     * bzw. wenn keine Datei gepackt werden konnte. Alte ZIP-Reste (>1 h) werden
     * dabei aufgeräumt. Der Aufrufer streamt die Datei und löscht sie danach.
     */
    public static function buildZip(array $items): ?string
    {
        if (!class_exists('ZipArchive') || !$items || !self::ensureTmpDir()) {
            return null;
        }
        foreach (glob(self::tmpDir() . '/zip_*.zip') ?: [] as $old) {
            if (@filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $zipPath = self::tmpDir() . '/zip_' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $used = [];
        $added = 0;
        foreach ($items as $m) {
            $path = self::dir() . '/' . basename((string) $m['stored_name']);
            if (!is_file($path)) {
                continue;
            }
            $entry = self::zipEntryName($m, $used);
            if ($zip->addFile($path, $entry)) {
                if (method_exists($zip, 'setCompressionName')) {
                    @$zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
                $added++;
            }
        }
        if ($added === 0 || !$zip->close()) {
            @$zip->close();
            @unlink($zipPath);
            return null;
        }
        return $zipPath;
    }

    /** Sprechenden, eindeutigen ZIP-Eintragsnamen bilden: „JJJJ-MM-TT_Person_Original.ext". */
    private static function zipEntryName(array $m, array &$used): string
    {
        $src  = !empty($m['taken_at']) ? (string) $m['taken_at'] : (string) ($m['created_at'] ?? '');
        $ts   = $src !== '' ? strtotime($src) : false;
        $date = $ts ? date('Y-m-d', $ts) : '0000-00-00';
        $orig = (string) ($m['original_name'] ?: $m['stored_name']);
        $ext  = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $base = pathinfo($orig, PATHINFO_FILENAME);
        $who  = (string) ($m['uploader_name'] ?? '');
        $san  = static fn(string $s): string => trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $s), '_');
        $name = mb_substr($date . ($who !== '' ? '_' . $san($who) : '') . '_' . ($san($base) ?: 'medium'), 0, 120);
        $file = $name . ($ext !== '' ? '.' . $ext : '');
        $try = $file;
        $i = 2;
        while (isset($used[mb_strtolower($try)])) {
            $try = $name . '_' . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        }
        $used[mb_strtolower($try)] = true;
        return $try;
    }

    // --- Teilbare, temporäre Download-Links -----------------------------

    public const SHARE_DEFAULT_DAYS      = 7;
    public const SHARE_MAX_DAYS          = 90;
    public const SHARE_DEFAULT_DOWNLOADS = 2;

    /**
     * Teilbaren Download-Link anlegen. Der Link ist über ein zufälliges Token
     * öffentlich erreichbar (zum Weitergeben), läuft nach $days Tagen ab und ist
     * höchstens $maxDownloads-mal nutzbar (danach löscht er sich).
     * @return array{token:string,expires_at:string,max_downloads:int}
     */
    public static function createShare(array $ids, ?int $cycleId, int $days, int $maxDownloads, ?int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $days = max(1, min(self::SHARE_MAX_DAYS, $days));
        $maxDownloads = max(1, min(100, $maxDownloads));
        $token = bin2hex(random_bytes(24)); // 48 Hex-Zeichen
        // Ablauf in SQL berechnen (wie MagicLink) – vermeidet PHP/MySQL-Zeitzonenversatz.
        Database::run(
            'INSERT INTO media_shares (token, cycle_id, item_ids, created_by, max_downloads, expires_at)
             VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$token, $cycleId ?: null, json_encode($ids), $userId, $maxDownloads, $days]
        );
        $row = Database::one('SELECT expires_at FROM media_shares WHERE token = ?', [$token]);
        return [
            'token'         => $token,
            'expires_at'    => (string) ($row['expires_at'] ?? date('Y-m-d H:i:s', time() + $days * 86400)),
            'max_downloads' => $maxDownloads,
        ];
    }

    /**
     * Einen erfolgreichen Download eines Share-Links verbuchen. Erhöht den Zähler
     * und löscht den Link, sobald die erlaubte Anzahl erreicht ist.
     * @return int Verbleibende Downloads nach diesem Zugriff.
     */
    public static function registerShareDownload(array $share): int
    {
        Database::run('UPDATE media_shares SET downloads = downloads + 1 WHERE id = ?', [(int) $share['id']]);
        $done = (int) $share['downloads'] + 1;
        $max  = max(1, (int) ($share['max_downloads'] ?? self::SHARE_DEFAULT_DOWNLOADS));
        $remaining = max(0, $max - $done);
        if ($remaining <= 0) {
            self::deleteShare((int) $share['id']);
        }
        return $remaining;
    }

    /** Gültigen (nicht abgelaufenen) Share zu einem Token finden. */
    public static function findShareByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return Database::one(
            'SELECT * FROM media_shares WHERE token = ? AND expires_at > NOW()',
            [$token]
        );
    }

    /** Zu einem Share gehörende, noch existierende Medien laden. */
    public static function shareItems(array $share): array
    {
        $ids = json_decode((string) $share['item_ids'], true);
        return is_array($ids) ? self::byIds($ids) : [];
    }

    public static function deleteShare(int $id): void
    {
        Database::run('DELETE FROM media_shares WHERE id = ?', [$id]);
    }

    /** Abgelaufene Share-Links aufräumen (bei jedem Zugriff aufgerufen). */
    public static function deleteExpiredShares(): void
    {
        Database::run('DELETE FROM media_shares WHERE expires_at < NOW()');
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
