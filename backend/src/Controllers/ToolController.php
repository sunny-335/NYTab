<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\ToolStateService;

final class ToolController {
    private ToolStateService $svc;
    public function __construct() { $this->svc = new ToolStateService(); }

    public function getState(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $pluginId = $this->pluginId($req);
        try {
            $state = $this->svc->get($userId, $pluginId);
            Response::json(['pluginId' => $pluginId, 'state' => $state]);
        } catch (\InvalidArgumentException $e) {
            Response::error((int)$e->getCode() ?: 42201, $e->getMessage(), 422);
        }
    }

    public function updateState(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $pluginId = $this->pluginId($req);
        $body = $req->body();
        $state = $body['state'] ?? null;
        if (!is_array($state)) { Response::error(40001, 'state 必须为对象', 400); return; }
        try {
            $this->svc->save($userId, $pluginId, $state);
            Response::json(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode() ?: 42201;
            $http = $code === 41301 ? 413 : 422;
            Response::error($code, $e->getMessage(), $http);
        }
    }

    public function deleteState(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        $pluginId = $this->pluginId($req);
        try {
            $this->svc->delete($userId, $pluginId);
            Response::json(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            Response::error((int)$e->getCode() ?: 42201, $e->getMessage(), 422);
        }
    }

    public function registry(Request $req): void {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) { Response::error(40101, '未登录', 401); return; }
        Response::json(['tools' => self::TOOLS]);
    }

    private function pluginId(Request $req): string {
        $params = $req->routeParams();
        return (string) ($params['pluginId'] ?? '');
    }

    private const TOOLS = [
        // efficiency
        ['pluginId' => 'pomodoro', 'name' => '番茄钟', 'category' => 'efficiency', 'icon' => 'timer', 'description' => '专注计时器，支持工作/休息循环'],
        ['pluginId' => 'markdown', 'name' => 'Markdown 编辑器', 'category' => 'efficiency', 'icon' => 'edit', 'description' => '所见即所得的 Markdown 编辑器'],
        ['pluginId' => 'notes', 'name' => '便签', 'category' => 'efficiency', 'icon' => 'sticky-note', 'description' => '全局便签与备忘录'],
        ['pluginId' => 'clock', 'name' => '时钟', 'category' => 'efficiency', 'icon' => 'clock', 'description' => '全屏时钟与天气组件'],
        // developer
        ['pluginId' => 'code-format', 'name' => '代码格式化', 'category' => 'developer', 'icon' => 'code', 'description' => '多语言代码格式化与美化'],
        ['pluginId' => 'json-xml', 'name' => 'JSON/XML 转换', 'category' => 'developer', 'icon' => 'brackets', 'description' => 'JSON 与 XML 互转'],
        ['pluginId' => 'base64', 'name' => 'Base64', 'category' => 'developer', 'icon' => 'binary', 'description' => 'Base64 编解码'],
        ['pluginId' => 'regex', 'name' => '正则测试', 'category' => 'developer', 'icon' => 'search', 'description' => '正则表达式实时测试'],
        ['pluginId' => 'color-picker', 'name' => '颜色拾取', 'category' => 'developer', 'icon' => 'palette', 'description' => '颜色拾取与调色板'],
        // lifestyle
        ['pluginId' => 'exchange', 'name' => '汇率转换', 'category' => 'lifestyle', 'icon' => 'dollar', 'description' => '实时汇率换算'],
        ['pluginId' => 'unit-convert', 'name' => '单位换算', 'category' => 'lifestyle', 'icon' => 'ruler', 'description' => '长度/重量/温度等单位换算'],
        ['pluginId' => 'password-gen', 'name' => '密码生成', 'category' => 'lifestyle', 'icon' => 'key', 'description' => '随机密码生成器'],
        ['pluginId' => 'qrcode', 'name' => '二维码', 'category' => 'lifestyle', 'icon' => 'qr-code', 'description' => '二维码生成器'],
    ];
}
