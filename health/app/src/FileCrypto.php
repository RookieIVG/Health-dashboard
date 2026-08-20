<?php
declare(strict_types=1);

namespace Health;

use RuntimeException;

/**
 * Datei-Verschlüsselung in Blöcken.
 *
 * Warum nicht einfach die ganze Datei durch openssl_encrypt(): ein 25-MB-PDF
 * läge dann dreimal gleichzeitig im Speicher (Klartext, Ciphertext, Rückgabe)
 * und reißt auf Shared Hosting das memory_limit. Deshalb blockweise, mit
 * eigenem IV und eigenem GCM-Tag je Block.
 *
 * Format:
 *
 *   Header:  "HDF1" (4 Byte) | chunkSize (uint32 BE)
 *   Block:   IV (12) | Tag (16) | Länge Ciphertext (uint32 BE) | Ciphertext
 *   ...
 *
 * Als AAD dient "storageKey:blockIndex". Damit sind zwei Angriffe
 * ausgeschlossen, die bei blockweiser Verschlüsselung sonst offenstehen:
 * das Vertauschen von Blöcken innerhalb einer Datei und das Austauschen
 * ganzer Dateien auf der Platte gegen eine andere, ebenfalls gültige.
 */
final class FileCrypto
{
    private const MAGIC      = 'HDF1';
    private const CIPHER     = 'aes-256-gcm';
    private const IV_LEN     = 12;
    private const TAG_LEN    = 16;
    private const CHUNK_SIZE = 1048576;   // 1 MiB Klartext je Block

    /**
     * Verschlüsselt $sourcePath nach $targetPath.
     *
     * @return array{bytes:int, sha256:string} Größe und Hash des Klartexts
     */
    public static function encryptFile(
        string $sourcePath,
        string $targetPath,
        string $key,
        string $storageKey
    ): array {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Quelldatei nicht lesbar.');
        }
        $out = @fopen($targetPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Zieldatei nicht beschreibbar.');
        }

        $hash  = hash_init('sha256');
        $bytes = 0;
        $index = 0;

        try {
            fwrite($out, self::MAGIC . pack('N', self::CHUNK_SIZE));

            while (!feof($in)) {
                $plain = fread($in, self::CHUNK_SIZE);
                if ($plain === false || $plain === '') {
                    break;
                }
                hash_update($hash, $plain);
                $bytes += strlen($plain);

                $iv  = random_bytes(self::IV_LEN);
                $tag = '';
                $ct  = openssl_encrypt(
                    $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag,
                    $storageKey . ':' . $index, self::TAG_LEN
                );
                if ($ct === false) {
                    throw new RuntimeException('Verschlüsselung des Blocks fehlgeschlagen.');
                }

                fwrite($out, $iv . $tag . pack('N', strlen($ct)) . $ct);
                $index++;
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    /**
     * Entschlüsselt blockweise auf einen Stream (in der Regel php://output).
     * Gibt nichts zurück, was nicht vorher den Tag-Test bestanden hat.
     */
    public static function decryptToStream(
        string $sourcePath,
        $target,
        string $key,
        string $storageKey
    ): void {
        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Verschlüsselte Datei nicht lesbar.');
        }

        try {
            $header = fread($in, 8);
            if ($header === false || strlen($header) !== 8
                || substr($header, 0, 4) !== self::MAGIC) {
                throw new RuntimeException('Unbekanntes Dateiformat.');
            }

            $index = 0;
            while (true) {
                $meta = fread($in, self::IV_LEN + self::TAG_LEN + 4);
                if ($meta === false || $meta === '' || strlen($meta) < self::IV_LEN + self::TAG_LEN + 4) {
                    break;      // regulär am Ende
                }

                $iv  = substr($meta, 0, self::IV_LEN);
                $tag = substr($meta, self::IV_LEN, self::TAG_LEN);
                $len = unpack('N', substr($meta, self::IV_LEN + self::TAG_LEN, 4))[1];

                if ($len <= 0 || $len > self::CHUNK_SIZE + 1024) {
                    throw new RuntimeException('Blocklänge unplausibel – Datei beschädigt.');
                }

                $ct = fread($in, $len);
                if ($ct === false || strlen($ct) !== $len) {
                    throw new RuntimeException('Datei unvollständig.');
                }

                $plain = openssl_decrypt(
                    $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag,
                    $storageKey . ':' . $index
                );
                if ($plain === false) {
                    throw new RuntimeException(
                        "Block {$index} konnte nicht entschlüsselt werden – "
                        . 'Datei wurde verändert oder gehört zu einem anderen Datensatz.'
                    );
                }

                fwrite($target, $plain);
                $index++;
            }
        } finally {
            fclose($in);
        }
    }

    /** Für kleine Dateien: komplett in den Speicher entschlüsseln. */
    public static function decryptToString(string $sourcePath, string $key, string $storageKey): string
    {
        $tmp = fopen('php://temp', 'r+b');
        self::decryptToStream($sourcePath, $tmp, $key, $storageKey);
        rewind($tmp);
        $data = (string)stream_get_contents($tmp);
        fclose($tmp);
        return $data;
    }
}
