<?php
declare(strict_types=1);

namespace Health;

/**
 * TOTP nach RFC 6238 (SHA1, 6 Stellen, 30 s) – kompatibel mit
 * Google Authenticator, Authy, 1Password, iOS-Passwörter.
 * Bewusst ohne externe Abhängigkeit, damit es auf Shared Hosting
 * ohne Composer läuft.
 */
final class Totp
{
    private const PERIOD  = 30;
    private const DIGITS  = 6;
    private const ALGO    = 'sha1';
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Erzeugt ein neues Secret (Base32, 32 Zeichen = 160 Bit). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** Aktueller Zeitschritt – wird gespeichert, um Replay zu verhindern. */
    public static function timeStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function codeAt(string $secretBase32, int $timeStep): string
    {
        $key = self::base32Decode($secretBase32);
        $bin = pack('N*', 0, $timeStep);                  // 64-Bit Big Endian
        $hash = hash_hmac(self::ALGO, $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft einen Code innerhalb des Toleranzfensters.
     * Liefert den verwendeten Zeitschritt zurück (für die Replay-Sperre)
     * oder null, wenn ungültig.
     */
    public static function verify(string $secretBase32, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $current = self::timeStep($timestamp);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if (hash_equals(self::codeAt($secretBase32, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** otpauth://-URI für QR-Code bzw. manuelle Eingabe. */
    public static function provisioningUri(string $secretBase32, string $label, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?' . http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Secret in 4er-Gruppen für die manuelle Eingabe. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // -----------------------------------------------------------------

    public static function base32Encode(string $data): string
    {
        if ($data === '') return '';
        $bits = '';
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        if ($b32 === '') return '';
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $bits .= str_pad(decbin((int)strpos(self::ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
