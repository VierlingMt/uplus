<?php
/**
 * CSRF-Schutz per Session-Token.
 */

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /** Verstecktes Formularfeld. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (!hash_equals(self::token(), (string) $sent)) {
            http_response_code(419);
            exit('Sitzung abgelaufen oder ungültiges Formular (CSRF).');
        }
    }
}
