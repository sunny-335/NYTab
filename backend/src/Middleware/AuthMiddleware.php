<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Env;
use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Utils\Jwt;

/**
 * Bearer token authentication.
 *
 * Extracts the Authorization: Bearer <token> header, verifies it via
 * Nytab\Utils\Jwt, and injects the authenticated user_id into the Request.
 * On missing/invalid tokens returns 401 (code=40101).
 *
 * Note: global enforcement is handled by AuthGuardMiddleware; this class
 * is retained for per-route attachment if finer-grained control is needed.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $req, callable $next): Response
    {
        $auth = $req->header('Authorization') ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            Response::error(40101, '未登录或 token 失效', 401);
            return new Response();
        }

        $payload = Jwt::verify($m[1], (string) Env::get('JWT_SECRET'));
        if ($payload === null) {
            Response::error(40101, '未登录或 token 失效', 401);
            return new Response();
        }

        $userId = is_array($payload)
            ? ($payload['user_id'] ?? ($payload['sub'] ?? null))
            : null;
        $req->setAttribute('user_id', $userId);

        $next($req);
        return new Response();
    }
}
