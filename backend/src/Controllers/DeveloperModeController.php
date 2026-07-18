<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\DeveloperModeService;
use Throwable;

/**
 * HTTP entry points for the developer-mode infrastructure.
 *
 * All three endpoints require authentication (they are registered inside
 * the AuthGuardMiddleware-protected route group). Toggle endpoints call
 * into DeveloperModeService to flip APP_DEV_MODE and — for enable — to
 * provision the SQLite schema + default admin/admin account.
 */
final class DeveloperModeController
{
    private DeveloperModeService $service;

    public function __construct()
    {
        $this->service = new DeveloperModeService();
    }

    /**
     * GET /api/dev-mode/status → { enabled: bool }
     */
    public function status(Request $req): void
    {
        Response::json(['enabled' => $this->service->isEnabled()]);
    }

    /**
     * POST /api/dev-mode/enable → { ok: true, enabled: true }
     *
     * Writes APP_DEV_MODE=true to .env, reloads env, then runs the SQLite
     * migrations and seeds the admin/admin account (idempotent).
     */
    public function enable(Request $req): void
    {
        try {
            $this->service->enable();
            $this->service->initSqlite();
            Response::json(['ok' => true, 'enabled' => true]);
        } catch (Throwable $e) {
            Response::error(50001, '启用开发者模式失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/dev-mode/disable → { ok: true, enabled: false }
     *
     * Writes APP_DEV_MODE=false to .env and reloads env. The SQLite file
     * is left on disk so re-enabling picks up where it left off.
     */
    public function disable(Request $req): void
    {
        try {
            $this->service->disable();
            Response::json(['ok' => true, 'enabled' => false]);
        } catch (Throwable $e) {
            Response::error(50001, '关闭开发者模式失败: ' . $e->getMessage(), 500);
        }
    }
}
