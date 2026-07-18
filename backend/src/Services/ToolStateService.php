<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Repositories\ToolStateRepository;

final class ToolStateService {
    private ToolStateRepository $repo;
    public function __construct() { $this->repo = new ToolStateRepository(); }

    public function get(int $userId, string $pluginId): ?array {
        $state = $this->repo->getState($userId, $pluginId);
        return $state;
    }

    public function save(int $userId, string $pluginId, array $state): void {
        $this->validatePluginId($pluginId);
        $payload = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($payload === false || strlen($payload) > 1048576) {
            throw new \InvalidArgumentException('state 载荷超过 1MB 限制', 41301);
        }
        $this->repo->upsertState($userId, $pluginId, $state);
    }

    public function delete(int $userId, string $pluginId): void {
        $this->repo->deleteState($userId, $pluginId);
    }

    public function listAll(int $userId): array {
        $rows = $this->repo->listStates($userId);
        $map = [];
        foreach ($rows as $row) {
            $map[$row[0]] = $row[1];
        }
        return $map;
    }

    private function validatePluginId(string $pluginId): void {
        $len = strlen($pluginId);
        if ($len < 1 || $len > 64 ||
            !preg_match('/^[a-zA-Z0-9_-]+$/', $pluginId)) {
            throw new \InvalidArgumentException('pluginId 格式无效', 42201);
        }
    }
}
