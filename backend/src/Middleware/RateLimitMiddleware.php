<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Database;
use Nytab\Core\Request;
use Nytab\Core\Response;

/**
 * Login brute-force guard.
 *
 * For POST /auth/login, counts failed attempts recorded in login_logs for the
 * requesting IP within the last 5 minutes; 5+ failures triggers a 429
 * (code=42901). Database operations are wrapped in try/catch so a missing
 * schema (e.g. before install completes) never blocks the login attempt.
 * Writing of failed attempts is handled by AuthService in Task 5.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const LOGIN_PATH = 'auth/login';
    private const FAILURE_THRESHOLD = 5;

    public function handle(Request $req, callable $next): Response
    {
        $path = ltrim($req->path(), '/');
        if ($req->method() !== 'POST' || $path !== self::LOGIN_PATH) {
            $next($req);
            return new Response();
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM login_logs "
                . "WHERE ip = :ip AND success = false "
                . "AND created_at > (NOW() - INTERVAL '5 minutes')"
            );
            $stmt->execute([':ip' => $req->ip()]);
            $count = (int) $stmt->fetchColumn();
            if ($count >= self::FAILURE_THRESHOLD) {
                Response::error(42901, '尝试过于频繁,请 15 分钟后再试', 429);
                return new Response();
            }
        } catch (Throwable $e) {
            // Database not ready (pre-install) — do not block login.
        }

        $next($req);
        return new Response();
    }
}
