<?php
declare(strict_types=1);

namespace Health;

use RuntimeException;

/**
 * Anhänge – modulübergreifend über (module, ref_id).
 *
 * Dateien liegen verschlüsselt in app/storage/files/ unter einem
 * Zufallsnamen. Der echte Dateiname steht verschlüsselt in der Datenbank:
 * "Befund_Onkologie_2026-03-14.pdf" verrät im Klartext-Dateisystem bereits
 * mehr, als einem lieb sein kann.
 *
 * Ablage in Unterverzeichnissen nach den ersten beiden Zeichen des
 * storage_key – ein Verzeichnis mit zehntausend Dateien wird auf manchen
 * Dateisystemen spürbar langsam.
 */
final class AttachmentService
{
    /** Was hochgeladen werden darf. Erweiterbar, aber bewusst knapp. */
    private const ALLOWED = [
        'application/pdf'  => ['pdf'],
        'image/jpeg'       => ['jpg', 'jpeg'],
        'image/png'        => ['png'],
        'image/heic'       => ['heic'],
        'image/tiff'       => ['tif', 'tiff'],
        'text/plain'       => ['txt'],
        'text/csv'         => ['csv'],
        'application/dicom'=> ['dcm'],
    ];

    private const MAX_BYTES = 26214400;   // 25 MiB

    public function __construct(private App $app) {}

    // =================================================================
    // Speichern
    // =================================================================

    /**
     * Übernimmt eine hochgeladene Datei aus $_FILES.
     *
     * @param array $file  Eintrag aus $_FILES, z. B. $_FILES['datei']
     */
    public function storeUpload(
        array $file,
        string $module,
        ?int $refId = null,
        ?int $ownerId = null
    ): int {
        Modules::assert($module);
        $ownerId ??= $this->app->auth->userId();

        $this->assertUploadOk($file);

        $tmp      = $file['tmp_name'];
        $origName = $this->sanitizeFilename((string)($file['name'] ?? 'datei'));
        $mime     = $this->detectMime($tmp, $origName);

        return $this->storeFile($tmp, $origName, $mime, $module, $refId, $ownerId, true);
    }

    /** Übernimmt eine Datei aus dem Dateisystem (Import, Kamera-Upload, Sync). */
    public function storeFile(
        string $sourcePath,
        string $filename,
        ?string $mime,
        string $module,
        ?int $refId = null,
        ?int $ownerId = null,
        bool $isUpload = false
    ): int {
        Modules::assert($module);
        $ownerId ??= $this->app->auth->userId();

        if (!$this->app->auth->mayAccess($ownerId, $module, 'write')) {
            throw new RuntimeException('Keine Schreibberechtigung.');
        }
        if (!is_readable($sourcePath)) {
            throw new RuntimeException('Quelldatei nicht lesbar.');
        }
        if (filesize($sourcePath) > self::MAX_BYTES) {
            throw new RuntimeException('Datei ist zu groß (max. 25 MB).');
        }

        $mime ??= $this->detectMime($sourcePath, $filename);
        $this->assertAllowed($mime, $filename);

        $storageKey = bin2hex(random_bytes(16));
        $target     = $this->pathFor($storageKey, create: true);
        $dek        = $this->app->dekFor($ownerId);

        $result = FileCrypto::encryptFile($sourcePath, $target, $dek, $storageKey);

        if ($isUpload) {
            @unlink($sourcePath);
        }

        try {
            $st = $this->app->db->pdo()->prepare(
                'INSERT INTO attachments
                    (user_id, module, ref_id, filename_enc, mime_type, size_bytes,
                     storage_key, sha256, is_encrypted, enc_format)
                 VALUES (:u, :m, :r, :fn, :mime, :size, :sk, :sha, 1, :fmt)'
            );
            $st->bindValue(':u',    $ownerId, \PDO::PARAM_INT);
            $st->bindValue(':m',    $module);
            $st->bindValue(':r',    $refId, $refId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $st->bindValue(':fn',   $this->app->crypto->enc($dek, $filename, 'attachment.filename'), \PDO::PARAM_LOB);
            $st->bindValue(':mime', $mime);
            $st->bindValue(':size', $result['bytes'], \PDO::PARAM_INT);
            $st->bindValue(':sk',   $storageKey);
            $st->bindValue(':sha',  $result['sha256']);
            $st->bindValue(':fmt',  'HDF1');
            $st->execute();

            $id = (int)$this->app->db->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            @unlink($target);        // keine verwaisten Dateien zurücklassen
            throw $e;
        }

        $this->app->audit->log('attachment.stored', $ownerId, $this->app->auth->userId(),
                               $module, $id, ['size' => $result['bytes'], 'mime' => $mime]);
        return $id;
    }

    // =================================================================
    // Lesen
    // =================================================================

    public function find(int $id, ?int $ownerId = null): ?array
    {
        $ownerId ??= $this->app->auth->userId();
        $row = $this->app->db->one(
            'SELECT * FROM attachments WHERE id = :id AND user_id = :u',
            [':id' => $id, ':u' => $ownerId]
        );
        if (!$row) {
            return null;
        }
        $row['filename'] = $this->app->crypto->dec(
            $this->app->dekFor($ownerId), $row['filename_enc'], 'attachment.filename'
        );
        unset($row['filename_enc']);
        return $row;
    }

    /** Anhänge eines Datensatzes. */
    public function forObject(string $module, ?int $refId, ?int $ownerId = null): array
    {
        $ownerId ??= $this->app->auth->userId();
        $dek = $this->app->dekFor($ownerId);

        $sql = 'SELECT * FROM attachments WHERE user_id = :u AND module = :m';
        $par = [':u' => $ownerId, ':m' => $module];
        if ($refId === null) {
            $sql .= ' AND ref_id IS NULL';
        } else {
            $sql .= ' AND ref_id = :r';
            $par[':r'] = $refId;
        }
        $sql .= ' ORDER BY created_at DESC';

        $rows = $this->app->db->all($sql, $par);
        foreach ($rows as &$r) {
            $r['filename'] = $this->app->crypto->dec($dek, $r['filename_enc'], 'attachment.filename');
            unset($r['filename_enc']);
        }
        return $rows;
    }

    /**
     * Liefert die Datei an den Browser aus.
     *
     * Entschlüsselt zuerst vollständig nach php://temp und prüft den
     * sha256 gegen die Datenbank, bevor das erste Byte rausgeht. Ein
     * abgeschnittener Ciphertext fällt formatseitig nicht auf – der Hash
     * erkennt ihn. php://temp läuft ab 2 MB auf die Platte über, das
     * memory_limit bleibt also unangetastet.
     */
    public function stream(int $id, bool $forceDownload = false, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $att = $this->find($id, $ownerId);
        if (!$att) {
            throw new RuntimeException('Anhang nicht gefunden.');
        }
        if (!$this->app->auth->mayAccess($ownerId, $att['module'], 'read')) {
            throw new RuntimeException('Kein Zugriff auf diesen Anhang.');
        }

        $path = $this->pathFor($att['storage_key']);
        if (!is_file($path)) {
            throw new RuntimeException('Die Datei fehlt im Speicher.');
        }

        $buf = fopen('php://temp/maxmemory:2097152', 'r+b');
        FileCrypto::decryptToStream($path, $buf, $this->app->dekFor($ownerId), $att['storage_key']);

        rewind($buf);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $buf);
        $actual = hash_final($hash);

        if ($att['sha256'] && !hash_equals($att['sha256'], $actual)) {
            fclose($buf);
            $this->app->audit->log('attachment.integrity_failed', $ownerId,
                                   $this->app->auth->userId(), $att['module'], $id);
            throw new RuntimeException('Integritätsprüfung fehlgeschlagen – Datei unvollständig oder verändert.');
        }

        rewind($buf);
        $disposition = $forceDownload ? 'attachment' : 'inline';
        $name = $att['filename'] ?: 'datei';

        header('Content-Type: ' . $att['mime_type']);
        header('Content-Length: ' . (string)$att['size_bytes']);
        header('Content-Disposition: ' . $disposition
             . '; filename="' . addslashes($this->asciiFallback($name)) . '"'
             . "; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        fpassthru($buf);
        fclose($buf);

        $this->app->audit->log('attachment.downloaded', $ownerId,
                               $this->app->auth->userId(), $att['module'], $id);
    }

    // =================================================================
    // Verwalten
    // =================================================================

    /** Hängt einen zunächst freien Anhang an einen Datensatz. */
    public function assign(int $id, string $module, int $refId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $this->app->db->run(
            'UPDATE attachments SET module = :m, ref_id = :r WHERE id = :id AND user_id = :u',
            [':m' => Modules::assert($module), ':r' => $refId, ':id' => $id, ':u' => $ownerId]
        );
    }

    public function deleteOne(int $id, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $att = $this->find($id, $ownerId);
        if (!$att) {
            return;
        }
        if (!$this->app->auth->mayAccess($ownerId, $att['module'], 'write')) {
            throw new RuntimeException('Keine Schreibberechtigung.');
        }

        @unlink($this->pathFor($att['storage_key']));
        $this->app->db->run('DELETE FROM attachments WHERE id = :id AND user_id = :u',
                            [':id' => $id, ':u' => $ownerId]);

        $this->app->audit->log('attachment.deleted', $ownerId, $this->app->auth->userId(),
                               $att['module'], $id);
    }

    /** Alle Anhänge eines Datensatzes – wird von Repository::delete() gerufen. */
    public function deleteFor(string $module, int $refId, ?int $ownerId = null): void
    {
        $ownerId ??= $this->app->auth->userId();
        $rows = $this->app->db->all(
            'SELECT id, storage_key FROM attachments WHERE user_id = :u AND module = :m AND ref_id = :r',
            [':u' => $ownerId, ':m' => $module, ':r' => $refId]
        );
        foreach ($rows as $r) {
            @unlink($this->pathFor($r['storage_key']));
        }
        $this->app->db->run(
            'DELETE FROM attachments WHERE user_id = :u AND module = :m AND ref_id = :r',
            [':u' => $ownerId, ':m' => $module, ':r' => $refId]
        );
    }

    /**
     * Findet Karteileichen in beide Richtungen: DB-Einträge ohne Datei und
     * Dateien ohne DB-Eintrag. Für einen gelegentlichen Cron-Lauf.
     */
    public function findOrphans(): array
    {
        $dbKeys = [];
        foreach ($this->app->db->all('SELECT id, storage_key FROM attachments') as $r) {
            $dbKeys[$r['storage_key']] = (int)$r['id'];
        }

        $missingFiles = [];
        foreach ($dbKeys as $key => $id) {
            if (!is_file($this->pathFor($key))) {
                $missingFiles[] = ['id' => $id, 'storage_key' => $key];
            }
        }

        $orphanFiles = [];
        $base = $this->baseDir();
        if (is_dir($base)) {
            foreach (glob($base . '/*/*') ?: [] as $f) {
                $key = basename($f, '.enc');
                if (!isset($dbKeys[$key])) {
                    $orphanFiles[] = $f;
                }
            }
        }

        return ['db_without_file' => $missingFiles, 'file_without_db' => $orphanFiles];
    }

    // =================================================================
    // Intern
    // =================================================================

    private function baseDir(): string
    {
        return rtrim($this->app->config['paths']['storage'], '/') . '/files';
    }

    private function pathFor(string $storageKey, bool $create = false): string
    {
        $sub = substr($storageKey, 0, 2);
        $dir = $this->baseDir() . '/' . $sub;
        if ($create && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Ablageverzeichnis {$dir} konnte nicht angelegt werden.");
        }
        return $dir . '/' . $storageKey . '.enc';
    }

    private function assertUploadOk(array $file): void
    {
        $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($code !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
                UPLOAD_ERR_PARTIAL    => 'Der Upload wurde abgebrochen.',
                UPLOAD_ERR_NO_FILE    => 'Es wurde keine Datei ausgewählt.',
                UPLOAD_ERR_NO_TMP_DIR => 'Serverfehler: kein temporäres Verzeichnis.',
                UPLOAD_ERR_CANT_WRITE => 'Serverfehler: Schreiben nicht möglich.',
                default               => 'Der Upload ist fehlgeschlagen.',
            });
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new RuntimeException('Ungültiger Upload.');
        }
    }

    /**
     * MIME-Typ aus dem Dateiinhalt, nicht aus dem Browser-Header – der ist
     * frei wählbar und damit wertlos.
     */
    private function detectMime(string $path, string $filename): string
    {
        $mime = null;
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $path) ?: null;
                finfo_close($fi);
            }
        }
        if (!$mime || $mime === 'application/octet-stream') {
            // DICOM erkennt finfo oft nicht – Magic Bytes an Offset 128
            $fh = @fopen($path, 'rb');
            if ($fh) {
                fseek($fh, 128);
                if (fread($fh, 4) === 'DICM') {
                    $mime = 'application/dicom';
                }
                fclose($fh);
            }
        }
        return $mime ?: 'application/octet-stream';
    }

    /** MIME und Endung müssen beide passen und zueinander gehören. */
    private function assertAllowed(string $mime, string $filename): void
    {
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException("Dateityp nicht erlaubt: {$mime}");
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, self::ALLOWED[$mime], true)) {
            throw new RuntimeException(
                "Dateiendung .{$ext} passt nicht zum erkannten Inhalt ({$mime})."
            );
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        return mb_substr(trim($name) ?: 'datei', 0, 180);
    }

    /** Content-Disposition verträgt keine Umlaute im einfachen filename-Teil. */
    private function asciiFallback(string $name): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $t ?: 'datei') ?: 'datei';
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }
}
