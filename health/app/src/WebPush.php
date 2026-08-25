<?php
declare(strict_types=1);

namespace Health;

use RuntimeException;

/**
 * Web Push ohne Drittanbieter-Bibliothek.
 *
 * Composer/Packagist waren beim Bau dieser Klasse nicht erreichbar, und
 * eine handgeschriebene Krypto-Implementierung ist ein ungewöhnlicher
 * Schritt für dieses Projekt – deshalb hier ausführlicher als sonst
 * dokumentiert, mit Verweisen auf die genauen RFC-Abschnitte.
 *
 * Jeder Rechenschritt ist vor der Auslieferung gegen den offiziellen
 * Testvektor aus RFC 8291 Appendix A geprüft worden (inklusive aller
 * Zwischenwerte: ECDH-Sekret, beide HMAC-Kombinationsschritte, CEK,
 * Nonce, und das fertige Chiffrat – Übereinstimmung bis zum letzten
 * Byte). Diese Klasse überträgt exakt diese Schritte nach PHP; sie
 * selbst konnte hier nicht laufend getestet werden, da in dieser
 * Umgebung kein PHP-Interpreter zur Verfügung steht.
 *
 * Ablauf einer Nachricht (RFC 8291 + RFC 8188 "aes128gcm"):
 *   1. ECDH zwischen einem frischen, einmaligen Server-Schlüsselpaar
 *      und dem öffentlichen Schlüssel des Geräts (p256dh).
 *   2. Das ECDH-Sekret wird mit dem Auth-Secret des Geräts über zwei
 *      HMAC-SHA-256-Schritte zum eigentlichen Schlüsselmaterial (IKM)
 *      kombiniert (RFC 8291 Abschnitt 3.3).
 *   3. Aus IKM und einem zufälligen Salt werden per HKDF (RFC 8188)
 *      der AES-128-GCM-Schlüssel und die Nonce abgeleitet.
 *   4. Der Klartext bekommt ein Padding-Trennzeichen (0x02) angehängt
 *      und wird in einem einzigen Datensatz verschlüsselt.
 *   5. VAPID (RFC 8292): ein mit dem eigenen langlebigen Schlüssel
 *      signiertes JWT beweist dem Push-Dienst, welcher Absender schickt.
 */
final class WebPush
{
    private const SPKI_PREFIX_P256 = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                                    . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    private $vapidPrivateKey; // OpenSSLAsymmetricKey
    private string $vapidPublicRaw; // 65 Byte, unkomprimierter Punkt

    public function __construct(private string $keyDir, private string $subject)
    {
        $pem = $this->loadOrCreateVapidKey();
        $this->vapidPrivateKey = openssl_pkey_get_private($pem);
        if ($this->vapidPrivateKey === false) {
            throw new RuntimeException('VAPID-Schlüssel konnte nicht geladen werden.');
        }

        $details = openssl_pkey_get_details($this->vapidPrivateKey);
        if (!isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('VAPID-Schlüssel ist kein EC-P-256-Schlüssel.');
        }
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $this->vapidPublicRaw = "\x04" . $x . $y;

        // Selbstprüfung: den gerade abgeleiteten Punkt zurück in ein
        // ladbares Schlüsselobjekt verpacken. Schlägt das fehl, ist der
        // Punkt fehlerhaft (z.B. falsches Padding von x/y) – das würde
        // sonst erst beim Browser als kryptische Meldung auffallen,
        // und zwar bei jedem Versuch gleich, weil der VAPID-Schlüssel
        // nur einmal erzeugt und danach dauerhaft wiederverwendet wird.
        if (openssl_pkey_get_public(self::pemFromRawPoint($this->vapidPublicRaw)) === false) {
            throw new RuntimeException(
                'VAPID-Schlüssel ist fehlerhaft (ungültiger EC-Punkt). '
                . 'app/keys/vapid_private.key.php löschen, damit beim nächsten '
                . 'Aufruf ein neuer Schlüssel erzeugt wird.'
            );
        }
    }

    /** Base64url – für den öffentlichen VAPID-Schlüssel im Frontend. */
    public function publicKeyBase64Url(): string
    {
        return self::b64url($this->vapidPublicRaw);
    }

    // =================================================================
    // Versand
    // =================================================================

    /**
     * @param array $subscription ['endpoint'=>string,'p256dh'=>base64url,'auth'=>base64url]
     * @return array{ok: bool, status: ?int, gone: bool, error: ?string}
     */
    /**
     * @param string $urgency 'very-low'|'low'|'normal'|'high' (RFC 8030 §5.3).
     *                        Push-Dienste dürfen Zustellungen mit niedrigerer
     *                        Dringlichkeit verzögern oder bündeln, um Akku zu
     *                        sparen - bei zeitkritischen Erinnerungen (Medikamente,
     *                        Termine) deshalb bewusst "high" statt der Vorgabe.
     */
    public function send(array $subscription, string $payloadJson, int $ttl = 3600, string $urgency = 'normal'): array
    {
        if (!in_array($urgency, ['very-low', 'low', 'normal', 'high'], true)) $urgency = 'normal';

        try {
            $body = $this->encryptPayload(
                $payloadJson,
                self::b64urlDecode($subscription['p256dh']),
                self::b64urlDecode($subscription['auth'])
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'gone' => false, 'error' => 'Verschlüsselung: ' . $e->getMessage()];
        }

        $endpoint = $subscription['endpoint'];
        $origin   = self::originOf($endpoint);
        $jwt      = $this->buildVapidJwt($origin);

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: ' . $urgency,
            'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKeyBase64Url(),
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['ok' => false, 'status' => null, 'gone' => false, 'error' => $curlErr];
        }

        // 404/410 = der Push-Dienst kennt diese Anmeldung nicht mehr
        // (App deinstalliert, Browserdaten gelöscht) – Abonnement entfernen.
        $gone = in_array($status, [404, 410], true);
        $ok   = $status >= 200 && $status < 300;

        // Der Antworttext ging bisher verloren (curl_exec()-Rückgabe wurde
        // nicht aufgefangen) - ein "ok" bedeutet nur, dass der Push-Dienst
        // die verschlüsselte Nachricht angenommen hat, nicht dass sie beim
        // Gerät tatsächlich entschlüsselt und angezeigt wurde. Bei einem
        // Fehlschlag jetzt zumindest den Antworttext mitloggen, statt nur
        // "HTTP 4xx" ohne jeden weiteren Anhaltspunkt.
        $error = null;
        if (!$ok) {
            $error = 'HTTP ' . $status;
            if (is_string($responseBody) && trim($responseBody) !== '') {
                $error .= ': ' . mb_substr(trim($responseBody), 0, 300);
            }
        }

        return ['ok' => $ok, 'status' => $status, 'gone' => $gone, 'error' => $error];
    }

    // =================================================================
    // Payload-Verschlüsselung (RFC 8291 / RFC 8188 aes128gcm)
    // =================================================================

    private function encryptPayload(string $plaintext, string $p256dhRaw, string $authRaw): string
    {
        if (strlen($p256dhRaw) !== 65 || $p256dhRaw[0] !== "\x04") {
            throw new RuntimeException('Ungültiger p256dh-Schlüssel des Geräts.');
        }

        // Frisches, einmaliges Schlüsselpaar für diese eine Nachricht
        // (RFC 8291 Abschnitt 3.1) – nicht wiederverwenden.
        $ephemeral = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($ephemeral === false) throw new RuntimeException('Konnte kein Schlüsselpaar erzeugen.');
        $ephemeralDetails = openssl_pkey_get_details($ephemeral);
        $asX = str_pad($ephemeralDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $asY = str_pad($ephemeralDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $asPublicRaw = "\x04" . $asX . $asY;

        $devicePublicKey = openssl_pkey_get_public(self::pemFromRawPoint($p256dhRaw));
        if ($devicePublicKey === false) throw new RuntimeException('Geräteschlüssel ungültig.');

        $ecdhSecret = openssl_pkey_derive($devicePublicKey, $ephemeral, 32);
        if ($ecdhSecret === false || strlen($ecdhSecret) !== 32) {
            throw new RuntimeException('ECDH-Berechnung fehlgeschlagen.');
        }

        // -- RFC 8291 §3.3: ECDH-Sekret und Auth-Secret kombinieren --
        $prkKey = hash_hmac('sha256', $ecdhSecret, $authRaw, true);
        $keyInfo = "WebPush: info\x00" . $p256dhRaw . $asPublicRaw;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);

        // -- RFC 8188 §2.1: CEK und Nonce aus Salt + IKM ableiten --
        $salt = random_bytes(16);
        $prk  = hash_hmac('sha256', $ikm, $salt, true);
        $cek  = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // -- Header: Salt(16) + Recordgröße(4, big-endian) + Schlüssel --
        $recordSize = 4096;
        $header = $salt . pack('N', $recordSize) . chr(strlen($asPublicRaw)) . $asPublicRaw;

        // -- Ein einziger Datensatz, Klartext + 0x02-Padding-Trenner --
        $padded = $plaintext . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) throw new RuntimeException('AES-GCM-Verschlüsselung fehlgeschlagen.');

        return $header . $ciphertext . $tag;
    }

    /** Verpackt einen rohen 65-Byte-EC-Punkt in eine ladbare DER-SPKI-Struktur. */
    private static function pemFromRawPoint(string $raw65): string
    {
        $der = self::SPKI_PREFIX_P256 . $raw65;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    // =================================================================
    // VAPID (RFC 8292)
    // =================================================================

    private function buildVapidJwt(string $audience): string
    {
        $header  = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $payload = self::b64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200, // max. 24h laut RFC, wir nehmen 12h
            'sub' => $this->subject,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $payload;

        $derSig = '';
        if (!openssl_sign($signingInput, $derSig, $this->vapidPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID-Signatur fehlgeschlagen.');
        }
        $rawSig = self::derToRawSignature($derSig, 32);

        return $signingInput . '.' . self::b64url($rawSig);
    }

    /** ECDSA-Signatur von DER (was openssl_sign liefert) nach raw r||s. */
    private static function derToRawSignature(string $der, int $size): string
    {
        $offset = 2; // 0x30 <Gesamtlänge>
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f; // Länge über mehrere Bytes
        }

        $readInt = function (string $der, int $i) use ($size): array {
            // erwartet 0x02 <Länge> <Wert>
            $len = ord($der[$i + 1]);
            $val = substr($der, $i + 2, $len);
            $val = ltrim($val, "\x00");
            $val = str_pad($val, $size, "\x00", STR_PAD_LEFT);
            return [$val, $i + 2 + $len];
        };

        [$r, $offset] = $readInt($der, $offset);
        [$s, $offset] = $readInt($der, $offset);

        return $r . $s;
    }

    // =================================================================
    // VAPID-Schlüssel: einmalig erzeugen, danach aus Datei laden
    // =================================================================

    private function loadOrCreateVapidKey(): string
    {
        $path = rtrim($this->keyDir, '/') . '/vapid_private.key.php';
        if (is_file($path)) {
            $pem = (string)(include $path);
            if ($pem !== '') return $pem;
        }

        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) throw new RuntimeException('VAPID-Schlüssel konnte nicht erzeugt werden.');
        openssl_pkey_export($key, $pem);

        $content = "<?php\n// VAPID-Schlüssel für Web-Push-Erinnerungen. Nicht ins Repository,\n"
                 . "// nicht gemeinsam mit dem Datenbank-Backup ablegen.\n"
                 . "return " . var_export($pem, true) . ";\n";
        file_put_contents($path, $content, LOCK_EX);
        @chmod($path, 0400);

        return $pem;
    }

    // =================================================================

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return (string)base64_decode(str_pad($s, strlen($s) + (4 - strlen($s) % 4) % 4, '=', STR_PAD_RIGHT), true);
    }
}
