<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\WorkspaceService;

final class WorkspaceController {
    private WorkspaceService $svc;
    public function __construct() { $this->svc = new WorkspaceService(); }

    public function getLayout(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $data = $this->svc->getLayout($userId);
        Response::json($data);
    }

    public function updateLayout(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $body = $req->body();
        $layout = $body['layout'] ?? null;
        if (!is_array($layout)) { Response::error(40001, 'layout 必须为数组', 400); return; }
        try {
            $this->svc->updateLayout($userId, $layout);
            Response::json(null);
        } catch (\InvalidArgumentException $e) {
            Response::error((int)$e->getCode() ?: 42201, $e->getMessage(), 422);
        }
    }

    public function getSettings(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $settings = $this->svc->getSettings($userId);
        Response::json(['settings' => $settings]);
    }

    public function updateSettings(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $body = $req->body();
        $settings = $body['settings'] ?? $body;
        if (!is_array($settings)) { Response::error(40001, 'settings 必须为对象', 400); return; }
        try {
            $this->svc->updateSettings($userId, $settings);
            Response::json(null);
        } catch (\InvalidArgumentException $e) {
            Response::error((int)$e->getCode() ?: 42201, $e->getMessage(), 422);
        }
    }
}
