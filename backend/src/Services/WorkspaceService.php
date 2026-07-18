<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Repositories\WorkspaceRepository;

final class WorkspaceService {
    private WorkspaceRepository $repo;
    public function __construct() { $this->repo = new WorkspaceRepository(); }

    public function getLayout(int $userId): array {
        return $this->repo->getLayout($userId);
    }

    public function updateLayout(int $userId, array $layout): void {
        // 校验每个布局项
        foreach ($layout as $item) {
            $this->validateLayoutItem($item);
        }
        $this->repo->updateLayout($userId, $layout);
    }

    public function getSettings(int $userId): array {
        return $this->repo->getLayout($userId)['settings'];
    }

    public function updateSettings(int $userId, array $settings): void {
        // 校验 settings 字段
        $validKeys = ['cols', 'rowHeight', 'gap', 'theme'];
        $clean = [];
        foreach ($validKeys as $k) {
            if (isset($settings[$k])) $clean[$k] = $settings[$k];
        }
        if (isset($clean['cols'])) {
            $clean['cols'] = max(1, min(24, (int)$clean['cols']));
        }
        if (isset($clean['rowHeight'])) {
            $clean['rowHeight'] = max(40, min(300, (int)$clean['rowHeight']));
        }
        if (isset($clean['gap'])) {
            $clean['gap'] = max(0, min(40, (int)$clean['gap']));
        }
        // 合并到现有 settings(保留未传入的字段)
        $existing = $this->repo->getLayout($userId)['settings'];
        $merged = array_merge($existing, $clean);
        $this->repo->updateSettings($userId, $merged);
    }

    private function validateLayoutItem(array $item): void {
        if (!isset($item['pluginId']) || !is_string($item['pluginId'])) {
            throw new \InvalidArgumentException('布局项缺少 pluginId', 42201);
        }
        foreach (['x', 'y', 'w', 'h'] as $field) {
            if (!isset($item[$field]) || !is_int($item[$field]) || $item[$field] < 0) {
                throw new \InvalidArgumentException("布局项 {$field} 无效", 42201);
            }
        }
        if (isset($item['enabled']) && !is_bool($item['enabled'])) {
            throw new \InvalidArgumentException('enabled 必须为布尔', 42201);
        }
    }
}
