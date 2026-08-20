<?php
declare(strict_types=1);

namespace Health;

final class Audit
{
    public function __construct(
        private Db $db,
        private Crypto $crypto
    ) {}

    public function log(
        string $action,
        ?int $userId = null,
        ?int $actorId = null,
        ?string $module = null,
        ?int $refId = null,
        array $detail = []
    ): void {
        $detailEnc = $detail ? $this->crypto->encSystem(json_encode($detail, JSON_UNESCAPED_UNICODE)) : null;

        $st = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (user_id, actor_id, action, module, ref_id, ip, user_agent, detail_enc)
             VALUES (:uid, :aid, :action, :module, :ref, :ip, :ua, :detail)'
        );
        $st->bindValue(':uid',    $userId, $userId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $st->bindValue(':aid',    $actorId, $actorId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $st->bindValue(':action', $action);
        $st->bindValue(':module', $module);
        $st->bindValue(':ref',    $refId, $refId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $st->bindValue(':ip',     self::ipBinary(), \PDO::PARAM_LOB);
        $st->bindValue(':ua',     mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
        $st->bindValue(':detail', $detailEnc, $detailEnc === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $st->execute();
    }

    public static function ipBinary(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!$ip) return null;
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    public static function ipString(?string $binary): ?string
    {
        if ($binary === null || $binary === '') return null;
        if (is_resource($binary)) $binary = stream_get_contents($binary);
        $s = @inet_ntop($binary);
        return $s === false ? null : $s;
    }
}
