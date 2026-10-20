<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use SessionHandlerInterface;

/**
 * Sessions stockées dans la table `sessions` (et non dans /var/lib/php/sessions) :
 * elles survivent à un changement de serveur et peuvent être révoquées côté serveur
 * (ex. DELETE FROM sessions WHERE user_id = 12 pour déconnecter un compte).
 */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $st = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id');
        $st->execute(['id' => $id]);
        $data = $st->fetchColumn();
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        // Format PHP par défaut : user_id|i:12;  -> on en extrait l'id pour pouvoir révoquer par utilisateur.
        $userId = preg_match('/(?:^|;)user_id\|i:(\d+);/', $data, $m) ? (int) $m[1] : null;
        $now = time();

        $st = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, last_activity) VALUES (:id, :uid, :data, :t)
             ON DUPLICATE KEY UPDATE user_id = :uid2, data = :data2, last_activity = :t2'
        );
        $st->bindValue(':id', $id);
        $st->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':data', $data);
        $st->bindValue(':t', $now, PDO::PARAM_INT);
        $st->bindValue(':uid2', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(':data2', $data);
        $st->bindValue(':t2', $now, PDO::PARAM_INT);
        return $st->execute();
    }

    public function destroy(string $id): bool
    {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        return $st->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $st = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < :t');
        $st->bindValue(':t', time() - $max_lifetime, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }
}
