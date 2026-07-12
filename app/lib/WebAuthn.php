<?php
/**
 * Minimale, abhängigkeitsfreie WebAuthn-/Passkey-Unterstützung.
 *
 * Deckt genau die für einen passwortlosen Geräte-Login benötigten Teile ab:
 *   - Registrierung (Attestation) mit „none"/self-Attestation (Trust on First Use).
 *     Es wird NUR der öffentliche Schlüssel aus authenticatorData extrahiert –
 *     die Attestation-Aussage selbst wird nicht geprüft (für diese Plattform
 *     ausreichend; kein Enterprise-Attestation-Bedarf).
 *   - Anmeldung (Assertion): Signaturprüfung über openssl (ES256 & RS256).
 *
 * Die Krypto-/Parsing-Teile sind bewusst DB-frei gehalten (testbar); die
 * Persistenz erledigt app/pages/passkey.php.
 */

declare(strict_types=1);

final class WebAuthnException extends RuntimeException
{
}

final class WebAuthn
{
    /** Registrierbare Domain (rp.id) aus der aktuellen Basis-URL. */
    public static function rpId(): string
    {
        $host = parse_url(base_url(), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Erwarteter Origin (Schema + Host [+ Port]). */
    public static function origin(): string
    {
        return rtrim(base_url(), '/');
    }

    public static function rpName(): string
    {
        return 'Unternehmen Plus';
    }

    // -------------------------------------------------------------- base64url
    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($s, false);
    }

    /** Neue Challenge erzeugen, in der Session ablegen und base64url zurückgeben. */
    public static function newChallenge(): string
    {
        $raw = random_bytes(32);
        $_SESSION['webauthn_challenge'] = self::b64urlEncode($raw);
        return $_SESSION['webauthn_challenge'];
    }

    public static function takeChallenge(): ?string
    {
        $c = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);
        return is_string($c) ? $c : null;
    }

    // --------------------------------------------------------- clientDataJSON
    /**
     * Prüft clientDataJSON (Typ, Challenge, Origin). Wirft bei Abweichung.
     */
    public static function checkClientData(string $clientDataJson, string $expectedType, string $expectedChallengeB64): void
    {
        $data = json_decode($clientDataJson, true);
        if (!is_array($data)) {
            throw new WebAuthnException('clientDataJSON ungültig.');
        }
        if (($data['type'] ?? '') !== $expectedType) {
            throw new WebAuthnException('Falscher Ceremony-Typ.');
        }
        // Challenge wird base64url übertragen; unabhängig von Padding vergleichen.
        $got = self::b64urlDecode((string) ($data['challenge'] ?? ''));
        $exp = self::b64urlDecode($expectedChallengeB64);
        if ($exp === '' || !hash_equals($exp, $got)) {
            throw new WebAuthnException('Challenge stimmt nicht überein.');
        }
        if (!hash_equals(self::origin(), (string) ($data['origin'] ?? ''))) {
            throw new WebAuthnException('Origin stimmt nicht überein.');
        }
    }

    // ------------------------------------------------------- authenticatorData
    /**
     * Zerlegt authenticatorData. Liefert rpIdHash, flags, signCount und – wenn
     * vorhanden (AT-Flag) – credentialId (roh) und COSE-Public-Key (Array).
     * @return array{rpIdHash:string,flags:int,signCount:int,credId:?string,cose:?array}
     */
    public static function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) {
            throw new WebAuthnException('authenticatorData zu kurz.');
        }
        $rpIdHash = substr($authData, 0, 32);
        $flags    = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $credId = null;
        $cose   = null;
        if ($flags & 0x40) { // AT: attested credential data vorhanden
            if (strlen($authData) < 55) {
                throw new WebAuthnException('attested credential data unvollständig.');
            }
            $credIdLen = unpack('n', substr($authData, 53, 2))[1];
            $credId = substr($authData, 55, $credIdLen);
            $keyOffset = 55 + $credIdLen;
            [$cose] = self::cborDecode($authData, $keyOffset);
            if (!is_array($cose)) {
                throw new WebAuthnException('COSE-Schlüssel nicht lesbar.');
            }
        }

        return [
            'rpIdHash'  => $rpIdHash,
            'flags'     => $flags,
            'signCount' => (int) $signCount,
            'credId'    => $credId,
            'cose'      => $cose,
        ];
    }

    public static function assertRpIdHash(string $rpIdHash): void
    {
        if (!hash_equals(hash('sha256', self::rpId(), true), $rpIdHash)) {
            throw new WebAuthnException('rpIdHash stimmt nicht überein.');
        }
    }

    // -------------------------------------------------- Registrierung/Attestation
    /**
     * Verifiziert eine Attestation und liefert die zu speichernden Werte.
     * @return array{credentialId:string,publicKeyPem:string,signCount:int}
     */
    public static function verifyRegistration(string $clientDataJson, string $attestationObjectRaw, string $expectedChallengeB64): array
    {
        self::checkClientData($clientDataJson, 'webauthn.create', $expectedChallengeB64);

        [$att] = self::cborDecode($attestationObjectRaw, 0);
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new WebAuthnException('attestationObject ungültig.');
        }
        $parsed = self::parseAuthData($att['authData']);
        self::assertRpIdHash($parsed['rpIdHash']);
        if (!($parsed['flags'] & 0x01)) {
            throw new WebAuthnException('User Presence fehlt.');
        }
        if ($parsed['credId'] === null || $parsed['cose'] === null) {
            throw new WebAuthnException('Kein Credential im authenticatorData.');
        }

        return [
            'credentialId' => self::b64urlEncode($parsed['credId']),
            'publicKeyPem' => self::coseToPem($parsed['cose']),
            'signCount'    => $parsed['signCount'],
        ];
    }

    // ---------------------------------------------------------- Anmeldung/Assertion
    /**
     * Verifiziert eine Assertion-Signatur gegen den gespeicherten PEM-Schlüssel.
     * @return int Neuer signCount (zum Aktualisieren)
     */
    public static function verifyAssertion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $publicKeyPem,
        string $expectedChallengeB64
    ): int {
        self::checkClientData($clientDataJson, 'webauthn.get', $expectedChallengeB64);

        $parsed = self::parseAuthData($authenticatorData);
        self::assertRpIdHash($parsed['rpIdHash']);
        if (!($parsed['flags'] & 0x01)) {
            throw new WebAuthnException('User Presence fehlt.');
        }

        $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new WebAuthnException('Gespeicherter Schlüssel ungültig.');
        }
        $ok = openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new WebAuthnException('Signatur ungültig.');
        }
        return $parsed['signCount'];
    }

    // --------------------------------------------------------------- COSE -> PEM
    /** Wandelt einen COSE-Public-Key (EC2/RSA) in ein PEM (SubjectPublicKeyInfo). */
    public static function coseToPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        if ($kty === 2) { // EC2
            $crv = $cose[-1] ?? null;
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if ($crv !== 1 || !is_string($x) || !is_string($y)) {
                throw new WebAuthnException('Nicht unterstützte EC-Kurve.');
            }
            $x = str_pad($x, 32, "\x00", STR_PAD_LEFT);
            $y = str_pad($y, 32, "\x00", STR_PAD_LEFT);
            // SubjectPublicKeyInfo-Präfix für P-256 + unkomprimierter Punkt (0x04)
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
                 . "\x04" . $x . $y;
            return self::pem($der);
        }
        if ($kty === 3) { // RSA
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e)) {
                throw new WebAuthnException('RSA-Schlüssel unvollständig.');
            }
            $rsaPubKey = self::asn1Seq(self::asn1Int($n) . self::asn1Int($e));
            $algId = self::asn1Seq(
                // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
                hex2bin('06092a864886f70d0101010500')
            );
            $der = self::asn1Seq($algId . self::asn1BitString($rsaPubKey));
            return self::pem($der);
        }
        throw new WebAuthnException('Nicht unterstützter Schlüsseltyp.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    // --------------------------------------------------------------- ASN.1 (DER)
    private static function asn1Len(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function asn1Int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) { // als positive Zahl kennzeichnen
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::asn1Len(strlen($bytes)) . $bytes;
    }

    private static function asn1Seq(string $content): string
    {
        return "\x30" . self::asn1Len(strlen($content)) . $content;
    }

    private static function asn1BitString(string $content): string
    {
        $content = "\x00" . $content; // 0 ungenutzte Bits
        return "\x03" . self::asn1Len(strlen($content)) . $content;
    }

    // ----------------------------------------------------------------- CBOR
    /**
     * Minimaler CBOR-Decoder (nur die für WebAuthn nötigen Major-Typen).
     * @return array{0:mixed,1:int} [Wert, neuer Offset]
     */
    private static function cborDecode(string $data, int $offset): array
    {
        if ($offset >= strlen($data)) {
            throw new WebAuthnException('CBOR: unerwartetes Ende.');
        }
        $ib = ord($data[$offset]);
        $offset++;
        $major = $ib >> 5;
        $ai = $ib & 0x1f;
        [$arg, $offset] = self::cborArg($data, $offset, $ai);

        switch ($major) {
            case 0: // unsigned int
                return [$arg, $offset];
            case 1: // negative int
                return [-1 - $arg, $offset];
            case 2: // byte string
            case 3: // text string
                $s = substr($data, $offset, $arg);
                return [$s, $offset + $arg];
            case 4: // array
                $arr = [];
                for ($i = 0; $i < $arg; $i++) {
                    [$v, $offset] = self::cborDecode($data, $offset);
                    $arr[] = $v;
                }
                return [$arr, $offset];
            case 5: // map
                $map = [];
                for ($i = 0; $i < $arg; $i++) {
                    [$k, $offset] = self::cborDecode($data, $offset);
                    [$v, $offset] = self::cborDecode($data, $offset);
                    $map[is_int($k) ? $k : (string) $k] = $v;
                }
                return [$map, $offset];
            case 6: // tag – Inhalt zurückgeben
                return self::cborDecode($data, $offset);
            case 7: // simple/float
                if ($ai === 20) { return [false, $offset]; }
                if ($ai === 21) { return [true, $offset]; }
                if ($ai === 22) { return [null, $offset]; }
                return [$arg, $offset];
        }
        throw new WebAuthnException('CBOR: nicht unterstützter Typ.');
    }

    /** Liest das Längen-/Wertargument eines CBOR-Items. */
    private static function cborArg(string $data, int $offset, int $ai): array
    {
        if ($ai < 24) {
            return [$ai, $offset];
        }
        if ($ai === 24) {
            return [ord($data[$offset]), $offset + 1];
        }
        if ($ai === 25) {
            return [unpack('n', substr($data, $offset, 2))[1], $offset + 2];
        }
        if ($ai === 26) {
            return [unpack('N', substr($data, $offset, 4))[1], $offset + 4];
        }
        if ($ai === 27) {
            $hi = unpack('N', substr($data, $offset, 4))[1];
            $lo = unpack('N', substr($data, $offset + 4, 4))[1];
            return [$hi * 4294967296 + $lo, $offset + 8];
        }
        throw new WebAuthnException('CBOR: unbestimmte Länge nicht unterstützt.');
    }
}
