<?php
declare(strict_types=1);

namespace Health;

use PDO;
use PDOStatement;

final class Db
{
    private PDO $pdo;

    public function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], (int)$cfg['port'], $cfg['name'], $cfg['charset']
        );
        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Serverseitig ebenfalls UTC, damit DATETIME und PHP zusammenpassen.
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = [])
    {
        $st = $this->run($sql, $params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
