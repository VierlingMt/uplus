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
     * Maximale Dateigröße je Upload. Orientiert sich an den PHP-Limits der
     * .user.ini (upload_max_filesize = 32M), damit die Meldung zur Realität passt.
     */
    public static function maxBytes(): int
    {
        return 32 * 1024 * 1024;
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
        return 'Bilder (JPG, PNG, GIF, WEBP) und Videos (MP4, WEBM, MOV) · max. '
            . human_size(self::maxBytes()) . ' je Datei';
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM media_items WHERE id = ?', [$id]);
    }

    /** Medien eines Zyklus (neueste zuerst) samt Name der hochladenden Person. */
    public static function forCycle(int $cycleId): array
    {
        return Database::all(
            'SELECT m.*, u.name AS uploader_name
               FROM media_items m
               LEFT JOIN users u ON u.id = m.uploaded_by
              WHERE m.cycle_id = ?
              ORDER BY m.created_at DESC, m.id DESC',
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
