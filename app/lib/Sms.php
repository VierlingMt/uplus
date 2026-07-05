<?php
/**
 * SMS-Versand über seven.io (https://seven.io).
 *
 * API-Key und Absender-ID liegen in den App-Einstellungen (Admin → Sicherheit),
 * werden also in der Tabelle `settings` gehalten – kein Redeploy nötig.
 */

declare(strict_types=1);

final class Sms
{
    private const API_URL = 'https://gateway.seven.io/api/sms';

    /** Ist ein seven.io-API-Key hinterlegt (→ SMS-Login verfügbar)? */
    public static function isConfigured(): bool
    {
        return trim((string) Settings::get('seven_api_key', '')) !== '';
    }

    /**
     * SMS versenden. Liefert true bei Erfolg (seven.io-Statuscode 100).
     * Die Empfängernummer wird nach E.164 (ohne führendes +) normalisiert.
     */
    public static function send(string $to, string $text): bool
    {
        $key = trim((string) Settings::get('seven_api_key', ''));
        if ($key === '') {
            error_log('[uplus] seven.io: kein API-Key hinterlegt');
            return false;
        }
        $number = self::normalizeNumber($to);
        if ($number === null) {
            error_log('[uplus] seven.io: ungültige Zielnummer');
            return false;
        }
        $from = trim((string) Settings::get('sms_from', '')) ?: 'UPlus';

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'X-Api-Key: ' . $key,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => http_build_query([
                'to'   => $number,
                'text' => $text,
                'from' => $from,
                'json' => 1,
            ]),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http < 200 || $http >= 300) {
            error_log('[uplus] seven.io: HTTP ' . $http . ' ' . $err);
            return false;
        }

        $json = json_decode((string) $body, true);
        // seven.io liefert 200 auch bei Logikfehlern; Erfolg = Statuscode "100".
        $success = is_array($json) ? (string) ($json['success'] ?? '') : trim((string) $body);
        if ($success !== '100') {
            error_log('[uplus] seven.io: Versand fehlgeschlagen (success=' . $success . ')');
            return false;
        }
        return true;
    }

    /**
     * Telefonnummer in E.164-Ziffern ohne führendes „+" bringen
     * (seven.io akzeptiert z. B. „491701234567"). Standard-Land: Deutschland (49).
     * Liefert null, wenn keine plausible Nummer erkennbar ist.
     */
    public static function normalizeNumber(string $phone): ?string
    {
        $cc = preg_replace('/\D/', '', (string) Settings::get('sms_default_cc', '49')) ?: '49';
        $p  = preg_replace('/[^\d+]/', '', $phone);
        if ($p === '' || $p === null) {
            return null;
        }
        if (str_starts_with($p, '+')) {
            $p = substr($p, 1);
        } elseif (str_starts_with($p, '00')) {
            $p = substr($p, 2);
        } elseif (str_starts_with($p, '0')) {
            $p = $cc . substr($p, 1);
        }
        return (ctype_digit($p) && strlen($p) >= 8 && strlen($p) <= 15) ? $p : null;
    }
}
