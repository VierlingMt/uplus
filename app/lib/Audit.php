<?php
/**
 * Audit-Log: protokolliert, wer wann was geändert hat – inkl. Login und
 * Login-Versuchen. Bewusst „fire and forget": Logging darf niemals einen
 * Request abbrechen, daher ist alles in try/catch gekapselt.
 *
 * Verwendung:
 *   Audit::log('team.update', 'Team „App" bearbeitet', 'team', $id);
 *   Audit::event('login.fail', 'Login fehlgeschlagen (E-Mail)', $userRow, ['email'=>$e]);
 */

declare(strict_types=1);

final class Audit
{
    /** Aktion des aktuell angemeldeten Nutzers protokollieren. */
    public static function log(string $action, ?string $summary = null, ?string $entity = null, ?int $entityId = null, ?array $meta = null): void
    {
        $u = null;
        try { $u = Auth::check() ? Auth::user() : null; } catch (\Throwable $e) { $u = null; }
        self::write($u, $action, $summary, $entity, $entityId, $meta);
    }

    /**
     * Ereignis mit explizitem Akteur protokollieren (z. B. Login/Login-Versuch,
     * wenn noch keine Session besteht). $user darf null sein (unbekannter Akteur).
     */
    public static function event(string $action, ?string $summary = null, ?array $user = null, ?array $meta = null): void
    {
        self::write($user, $action, $summary, null, null, $meta);
    }

    private static function write(?array $user, string $action, ?string $summary, ?string $entity, ?int $entityId, ?array $meta): void
    {
        try {
            $actor = null;
            $uid = null;
            if ($user) {
                $uid   = isset($user['id']) ? (int) $user['id'] : null;
                $name  = trim((string) ($user['name'] ?? ''));
                $email = trim((string) ($user['email'] ?? ''));
                $actor = $name !== '' && $email !== '' ? "$name <$email>" : ($name ?: ($email ?: null));
            }
            Database::run(
                'INSERT INTO audit_log (user_id, actor, action, entity, entity_id, summary, ip, meta)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $uid,
                    $actor !== null ? mb_substr($actor, 0, 190) : null,
                    mb_substr($action, 0, 64),
                    $entity !== null ? mb_substr($entity, 0, 40) : null,
                    $entityId,
                    $summary !== null ? mb_substr($summary, 0, 500) : null,
                    self::ip(),
                    $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
        } catch (\Throwable $e) {
            // Logging darf den eigentlichen Vorgang nie stören.
        }
    }

    private static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip ? mb_substr((string) $ip, 0, 45) : null;
    }
}
