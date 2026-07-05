<?php
/**
 * Schlanker E-Mail-Versand ueber PHP mail() (Shared-Hosting-tauglich).
 * Ohne externe Abhaengigkeit; UTF-8. Versendet wahlweise reinen Text oder
 * multipart/alternative (Text + gebrandetes HTML).
 */

declare(strict_types=1);

final class Mailer
{
    /**
     * Versendet eine Mail. Ist $html gesetzt, wird multipart/alternative
     * (Text + HTML) verschickt, sonst reiner Text. Liefert true bei
     * erfolgreicher Uebergabe an den MTA (kein Zustellungsnachweis).
     */
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
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
            'X-Mailer: Unternehmen Plus',
            'MIME-Version: 1.0',
        ];

        if ($html !== null && $html !== '') {
            $boundary = 'uplus_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body =
                '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text)) . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html)) . "\r\n"
                . '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        }

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

    /**
     * Baut ein gebrandetes HTML-Dokument (WJD-Design) im E-Mail-tauglichen
     * Tabellen-Layout mit Inline-CSS. Optional ein Call-to-Action-Button.
     *
     * @param string      $heading   Ueberschrift im Inhaltsbereich
     * @param string      $introHtml Einleitungstext (bereits HTML, ggf. <br>)
     * @param string|null $ctaLabel  Button-Beschriftung (null = kein Button)
     * @param string|null $ctaUrl    Button-Ziel
     * @param string      $footNote  Kleiner Hinweistext unter dem Button
     */
    public static function brandedHtml(
        string $heading,
        string $introHtml,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
        string $footNote = ''
    ): string {
        $blue  = '#003594';
        $teal  = '#00a5b5';
        $ink   = '#1c2733';
        $muted = '#6b7785';
        $bg    = '#eef2f7';

        $button = '';
        if ($ctaLabel !== null && $ctaUrl !== null) {
            $button =
                '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 8px">'
                . '<tr><td style="border-radius:10px;background:' . $blue . '">'
                . '<a href="' . htmlspecialchars($ctaUrl, ENT_QUOTES) . '" '
                . 'style="display:inline-block;padding:14px 30px;font-family:Arial,Helvetica,sans-serif;'
                . 'font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">'
                . htmlspecialchars($ctaLabel, ENT_QUOTES) . '</a></td></tr></table>';
        }

        $foot = $footNote !== ''
            ? '<p style="margin:14px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;'
              . 'line-height:1.6;color:' . $muted . '">' . $footNote . '</p>'
            : '';

        return
'<!doctype html><html lang="de"><head><meta charset="utf-8">'
. '<meta name="viewport" content="width=device-width,initial-scale=1">'
. '<meta name="color-scheme" content="light"></head>'
. '<body style="margin:0;padding:0;background:' . $bg . '">'
. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';padding:24px 12px">'
. '<tr><td align="center">'
. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
. 'style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;'
. 'box-shadow:0 6px 24px rgba(0,53,148,.10)">'
// Kopfband
. '<tr><td style="background:' . $blue . ';padding:26px 32px">'
. '<div style="height:4px;width:52px;background:' . $teal . ';border-radius:4px;margin-bottom:14px"></div>'
. '<div style="font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:.3px">Unternehmen&nbsp;Plus</div>'
. '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#b9c8e6;margin-top:3px">Wirtschaftsjunioren Forchheim · Businessplanwettbewerb</div>'
. '</td></tr>'
// Inhalt
. '<tr><td style="padding:30px 32px 34px">'
. '<h1 style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:20px;color:' . $ink . '">' . $heading . '</h1>'
. '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:' . $ink . '">' . $introHtml . '</p>'
. $button
. $foot
. '</td></tr>'
// Fuss
. '<tr><td style="padding:18px 32px;background:#f6f8fb;border-top:1px solid #e6ecf3">'
. '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:' . $muted . '">'
. 'Diese Nachricht wurde automatisch von der Plattform <strong>Unternehmen Plus</strong> gesendet.</div>'
. '</td></tr>'
. '</table></td></tr></table></body></html>';
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
