<?php
/**
 * Modul-Zugriffsmatrix: je Modul und Rolle eine Stufe – 'none' | 'read' | 'write'.
 *
 * Die DEFAULTS bilden das bisher fest verdrahtete Verhalten ab. Der Admin kann
 * über die „Zugriffsmatrix" (Route `access`) einzelne Zellen überschreiben; die
 * Abweichungen liegen als JSON im settings-Eintrag `access_matrix`. Solange nichts
 * überschrieben ist, verhält sich die App exakt wie zuvor.
 *
 * Grundregeln:
 *  - Der Admin (Super-Admin) hat immer volle Schreibrechte und ist nicht sperrbar.
 *  - Das Dashboard bleibt für alle mindestens lesbar (kein Aussperren von der
 *    Startseite).
 *  - Der Editor selbst ist unabhängig von der Matrix nur für den Admin erreichbar.
 */

declare(strict_types=1);

final class Access
{
    /** Stufen aufsteigend – der Index dient dem Vergleich (none < read < write). */
    public const LEVELS = ['none', 'read', 'write'];

    /** Governte Module (Reihenfolge = Anzeige im Editor). key => Anzeigename. */
    public const MODULES = [
        'dashboard'     => 'Dashboard',
        'plans'         => 'Businesspläne',
        'materials'     => 'Material & Vorlagen',
        'contact'       => 'Kontakt',
        'teams'         => 'Teams & Schüler',
        'schools'       => 'Schulen',
        'jurors'        => 'Jury & Nutzer',
        'jury_feedback' => 'Jury-Feedback',
        'ranking'       => 'Bewertung & Ranking',
        'pitch'         => 'PitchDay (Jury)',
        'cycles'        => 'Wettbewerbsjahre',
        'event'         => 'PitchDay-Orga',
        'sponsors'      => 'Sponsoren',
        'audit'         => 'Audit-Log',
        'admin'         => 'Admin & KI',
    ];

    /** Rollen (Spalten des Editors). */
    public const ROLES = ['admin' => 'Admin', 'lead' => 'Projektleitung', 'teacher' => 'Lehrkraft', 'juror' => 'Jury'];

    /**
     * Standardstufen = bisheriges Verhalten. [module][role] => level.
     * (Die admin-Spalte ist der Vollständigkeit halber gefüllt, wird in level()
     * aber ohnehin immer als 'write' behandelt.)
     */
    private const DEFAULTS = [
        'dashboard'     => ['admin' => 'write', 'lead' => 'read',  'teacher' => 'read', 'juror' => 'read'],
        'plans'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'write', 'juror' => 'read'],
        'materials'     => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'read', 'juror' => 'read'],
        'contact'       => ['admin' => 'write', 'lead' => 'read',  'teacher' => 'read', 'juror' => 'read'],
        'teams'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'write', 'juror' => 'read'],
        'schools'       => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'read'],
        'jurors'        => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'read'],
        // Jury liest diese Ansichten; die eigentliche Bewertung läuft über die
        // Bewerten-Maske (evaluate) und die bestehende Freeze-Logik.
        'jury_feedback' => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'read'],
        'ranking'       => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'read'],
        'pitch'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'read'],
        'cycles'        => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'none'],
        'event'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'none'],
        'sponsors'      => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'none'],
        'audit'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'none'],
        'admin'         => ['admin' => 'write', 'lead' => 'write', 'teacher' => 'none', 'juror' => 'none'],
    ];

    private static ?array $overrides = null;

    private static function overrides(): array
    {
        if (self::$overrides === null) {
            $raw  = (string) Settings::get('access_matrix', '');
            $data = $raw !== '' ? json_decode($raw, true) : null;
            self::$overrides = is_array($data) ? $data : [];
        }
        return self::$overrides;
    }

    /** Effektive Stufe eines Moduls für eine einzelne Rolle. */
    public static function level(string $module, string $role): string
    {
        if ($role === 'admin') {
            return 'write'; // Super-Admin nie einschränkbar
        }
        $lvl = self::overrides()[$module][$role] ?? (self::DEFAULTS[$module][$role] ?? 'none');
        if (!in_array($lvl, self::LEVELS, true)) {
            $lvl = 'none';
        }
        // Startseite bleibt für alle mindestens lesbar.
        if ($module === 'dashboard' && $lvl === 'none') {
            $lvl = 'read';
        }
        return $lvl;
    }

    /** Höchste Stufe des aktuellen Nutzers über alle seine Rollen. */
    public static function userLevel(string $module): string
    {
        $best = 0;
        foreach (Auth::roles() as $role) {
            $idx  = (int) array_search(self::level($module, $role), self::LEVELS, true);
            $best = max($best, $idx);
        }
        return self::LEVELS[$best];
    }

    public static function canRead(string $module): bool
    {
        return self::userLevel($module) !== 'none';
    }

    public static function canWrite(string $module): bool
    {
        return self::userLevel($module) === 'write';
    }

    /** Lesezugriff erzwingen – sonst 403-Fehlerseite (wie Auth::require). */
    public static function requireRead(string $module): void
    {
        if (!self::canRead($module)) {
            http_response_code(403);
            render('error', ['title' => 'Kein Zugriff', 'message' => 'Für diesen Bereich fehlt dir die Berechtigung.']);
            exit;
        }
    }

    /**
     * Schreibaktion absichern: bei fehlendem Schreibrecht mit Hinweis zurück auf
     * das Modul leiten. Für den POST-Zweig einer Modulseite gedacht.
     */
    public static function requireWrite(string $module): void
    {
        if (!self::canWrite($module)) {
            flash('error', 'Für diesen Bereich hast du nur Leserechte.');
            redirect(url($module));
        }
    }

    /** Effektive Matrix (für den Editor): [module][role] => level. */
    public static function matrix(): array
    {
        $out = [];
        foreach (array_keys(self::MODULES) as $m) {
            foreach (array_keys(self::ROLES) as $r) {
                $out[$m][$r] = self::level($m, $r);
            }
        }
        return $out;
    }

    /**
     * Overrides aus dem Editor speichern. Die admin-Spalte wird ignoriert (immer
     * write); nur echte Abweichungen vom Default werden abgelegt, damit sich
     * Default-Änderungen später automatisch durchziehen.
     */
    public static function save(array $input): void
    {
        $ov = [];
        foreach (array_keys(self::MODULES) as $m) {
            foreach (array_keys(self::ROLES) as $r) {
                if ($r === 'admin') {
                    continue;
                }
                $lvl = (string) ($input[$m][$r] ?? (self::DEFAULTS[$m][$r] ?? 'none'));
                if (!in_array($lvl, self::LEVELS, true)) {
                    $lvl = 'none';
                }
                if ($m === 'dashboard' && $lvl === 'none') {
                    $lvl = 'read'; // Startseite nie sperrbar
                }
                if ($lvl !== (self::DEFAULTS[$m][$r] ?? 'none')) {
                    $ov[$m][$r] = $lvl;
                }
            }
        }
        Settings::set('access_matrix', $ov ? json_encode($ov, JSON_UNESCAPED_UNICODE) : null);
        self::$overrides = $ov;
    }
}
