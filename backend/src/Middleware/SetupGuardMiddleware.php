<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Database;
use Nytab\Core\Request;
use Nytab\Core\Response;

/**
 * First-run installation guard.
 *
 * When config/installed.lock is absent the system is considered uninstalled:
 * only /setup/* endpoints are reachable, every other path returns 503
 * (code=50301). Once installed, /setup/* is permanently disabled with 409
 * (code=40901) to prevent reinstall attacks.
 *
 * Exception: GET /branding is always reachable so the install wizard and
 * the login page can render the configured nickname/title/logo without
 * a database or auth token in place.
 *
 * In developer mode (APP_DEV_MODE enabled) the install guard is bypassed
 * entirely — all routes are reachable without running the setup wizard,
 * since Nytab\Services\DeveloperModeService::initSqlite() provisions the
 * SQLite schema directly.
 */
final class SetupGuardMiddleware implements MiddlewareInterface
{
    /**
     * Method-scoped paths allowed even when the system is not yet
     * installed. Each entry is [METHOD, PATH] (PATH relative to /api).
     */
    private const PRE_INSTALL_PUBLIC = [
        ['GET', 'branding'],
        ['GET', 'settings/background'],
    ];

    public function handle(Request $req, callable $next): Response
    {
        // Developer mode short-circuits the install guard: the SQLite DB is
        // provisioned by DeveloperModeService, so /setup is not required.
        if (Database::isDevMode()) {
            $next($req);
            return new Response();
        }

        $installed = is_file(dirname(__DIR__, 2) . '/config/installed.lock');
        $method = $req->method();
        $path = ltrim($req->path(), '/');
        $isSetup = str_starts_with($path, 'setup/');

        if ($installed) {
            if ($isSetup) {
                Response::error(40901, '系统已安装', 409);
                return new Response();
            }
            $next($req);
            return new Response();
        }

        if ($isSetup) {
            $next($req);
            return new Response();
        }

        foreach (self::PRE_INSTALL_PUBLIC as [$pubMethod, $pubPath]) {
            if ($method === $pubMethod && $path === $pubPath) {
                $next($req);
                return new Response();
            }
        }

        Response::error(50301, '系统未安装', 503);
        return new Response();
    }
}
