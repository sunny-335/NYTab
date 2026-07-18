<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;

final class ToolStateRepository {
    private \PDO $pdo;
    public function __construct() { $this->pdo = Database::connection(); }

    public function getState(int $userId, string $pluginId): ?array {
        $stmt = $this->pdo->prepare('SELECT state FROM tool_states WHERE user_id = :uid AND plugin_id = :pid');
        $stmt->execute([':uid' => $userId, ':pid' => $pluginId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $state = json_decode((string)$row['state'], true);
        return is_array($state) ? $state : [];
    }

    public function upsertState(int $userId, string $pluginId, array $state): void {
        $sql = 'INSERT INTO tool_states (user_id, plugin_id, state) VALUES (:uid, :pid, :state) ' .
               'ON CONFLICT (user_id, plugin_id) DO UPDATE SET state = EXCLUDED.state, updated_at = NOW()';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':pid' => $pluginId,
            ':state' => json_encode($state, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function deleteState(int $userId, string $pluginId): void {
        $stmt = $this->pdo->prepare('DELETE FROM tool_states WHERE user_id = :uid AND plugin_id = :pid');
        $stmt->execute([':uid' => $userId, ':pid' => $pluginId]);
    }

    public function listStates(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT plugin_id, state FROM tool_states WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $state = json_decode((string)$row['state'], true);
            $out[] = [(string)$row['plugin_id'], is_array($state) ? $state : []];
        }
        return $out;
    }
}
