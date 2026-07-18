<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;

final class WorkspaceRepository {
    private \PDO $pdo;
    public function __construct() { $this->pdo = Database::connection(); }

    public function getLayout(int $userId): array {
        $stmt = $this->pdo->prepare('SELECT layout, settings FROM workspace_layouts WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'layout' => [],
                'settings' => ['cols' => 12, 'rowHeight' => 80, 'gap' => 12, 'theme' => 'default'],
            ];
        }
        return [
            'layout' => json_decode((string)$row['layout'], true) ?: [],
            'settings' => json_decode((string)$row['settings'], true) ?: [],
        ];
    }

    public function updateLayout(int $userId, array $layout): void {
        $sql = 'INSERT INTO workspace_layouts (user_id, layout, settings) VALUES (:uid, :layout, :settings) ' .
               'ON CONFLICT (user_id) DO UPDATE SET layout = EXCLUDED.layout';
        // 先查现有 settings,保留
        $existing = $this->getLayout($userId);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':layout' => json_encode($layout, JSON_UNESCAPED_UNICODE),
            ':settings' => json_encode($existing['settings'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function updateSettings(int $userId, array $settings): void {
        $sql = 'INSERT INTO workspace_layouts (user_id, layout, settings) VALUES (:uid, :layout, :settings) ' .
               'ON CONFLICT (user_id) DO UPDATE SET settings = EXCLUDED.settings';
        $existing = $this->getLayout($userId);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':layout' => json_encode($existing['layout'], JSON_UNESCAPED_UNICODE),
            ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
