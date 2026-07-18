<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\SetupService;
use Throwable;

/**
 * HTTP entry points for the first-run installation wizard.
 *
 * All three endpoints are guarded by SetupGuardMiddleware (which blocks
 * them with 409 once installed.lock exists); the per-method isInstalled()
 * check below is defense-in-depth in case the global pipeline is bypassed.
 */
final class SetupController
{
    private SetupService $setup;

    public function __construct()
    {
        $this->setup = new SetupService();
    }

    public function status(Request $req): void
    {
        if ($this->setup->isInstalled()) {
            $version = $this->setup->getInstalledVersion();
            Response::json(['installed' => true, 'version' => $version]);
            return;
        }
        $requirements = $this->setup->checkRequirements();
        Response::json([
            'installed' => false,
            'requirements' => $requirements,
        ]);
    }

    public function testDatabase(Request $req): void
    {
        if ($this->setup->isInstalled()) {
            Response::error(40901, '系统已安装', 409);
            return;
        }
        $body = $req->body();
        $required = ['host', 'port', 'name', 'user', 'password'];
        foreach ($required as $key) {
            if (!isset($body[$key]) || !is_string($body[$key])) {
                Response::error(40001, "参数缺失: {$key}", 400);
                return;
            }
        }
        try {
            $info = $this->setup->testDatabaseConnection($body);
            Response::json([
                'ok' => true,
                'databaseExists' => $info['databaseExists'],
                'canCreate' => $info['canCreate'],
                'server_version' => $info['server_version'],
            ], 0, '数据库连接成功');
        } catch (Throwable $e) {
            Response::error(42201, '数据库连接失败: ' . $e->getMessage(), 422);
        }
    }

    public function install(Request $req): void
    {
        if ($this->setup->isInstalled()) {
            Response::error(40901, '系统已安装', 409);
            return;
        }
        $body = $req->body();
        if (!isset($body['database']) || !isset($body['admin'])) {
            Response::error(40001, '参数缺失: database, admin', 400);
            return;
        }
        $corsOrigins = isset($body['corsOrigins']) && is_string($body['corsOrigins'])
            ? $body['corsOrigins'] : null;
        try {
            $this->setup->install($body['database'], $body['admin'], $corsOrigins);
            Response::json(['version' => '1.0.0'], 0, '安装成功', 201);
        } catch (\InvalidArgumentException $e) {
            Response::error((int) $e->getCode() ?: 42201, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error((int) $e->getCode() ?: 40901, $e->getMessage(), 409);
        } catch (Throwable $e) {
            Response::error(50001, '安装失败: ' . $e->getMessage(), 500);
        }
    }
}
