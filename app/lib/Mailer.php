<?php
/**
 * Sehr schlanker E-Mail-Versand ueber PHP mail() (Shared-Hosting-tauglich).
 * Bewusst ohne externe Abhaengigkeit; UTF-8, reiner Text.
 */

declare(strict_types=1);

final class Mailer
{
    /**
     * Versendet eine reine Text-Mail. Liefert true bei erfolgreicher Uebergabe
     * an den MTA (kein Zustellungsnachweis).
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        // Absender: bevorzugt aus den App-Einstellungen (Admin), sonst Deploy-Config.
        $fromEmail = trim((string) Settings::get('mail_from', '')) ?: (string) cfg('mail_from', '');
        if ($fromEmail === '') {
            $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $fromEmail = 'no-reply@' . $host;
        }
        $fromName = trim((string) Settings::get('mail_from_name', ''))
            ?: (string) cfg('mail_from_name', cfg('app_name', 'Unternehmen Plus'));

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Unternehmen Plus',
        ];

        $ok = @mail(
            $to,
            self::encodeHeader($subject),
            $body,
            implode("\r\n", $headers),
            '-f' . $fromEmail
        );

        if (!$ok) {
            error_log('[uplus] Mail-Versand fehlgeschlagen an ' . $to);
        }
        return (bool) $ok;
    }

    /** RFC-2047-Kodierung fuer Header mit Nicht-ASCII-Zeichen (Betreff, Absendername). */
    private static function encodeHeader(string $s): string
    {
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }
}
