<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Env;
use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Utils\Jwt;

/**
 * Global authentication guard.
 *
 * Position in the pipeline: Cors → SetupGuard → JsonBody → AuthGuard → Router.
 *
 * Paths under setup/, auth/login, auth/refresh are whitelisted (public).
 * Every other path requires a valid Authorization: Bearer <access_token>
 * header; the verified user_id / username are injected into the Request
 * attributes for downstream controllers.
 *
 * On missing or invalid tokens returns 401 (code=40101).
 */
final class AuthGuardMiddleware implements MiddlewareInterface
{
    /**
     * Path prefixes (relative to /api, already stripped by Request::path())
     * that bypass authentication.
     */
    private const WHITELIST_PREFIXES = [
        'setup/',
        'auth/login',
        'auth/refresh',
    ];

    /**
     * Method-scoped public paths. Each entry is [METHOD, PATH] where PATH
     * is the exact path (relative to /api) — used when only a specific
     * verb on a path should be public (e.g. GET /branding is public while
     * PUT /branding and POST /branding/logo require auth).
     */
    private const PUBLIC_METHOD_PATHS = [
        ['GET', 'branding'],
        ['GET', 'settings/background'],
    ];

    public function handle(Request $req, callable $next): Response
    {
        $method = $req->method();
        $path = ltrim($req->path(), '/');

        foreach (self::PUBLIC_METHOD_PATHS as [$pubMethod, $pubPath]) {
            if ($method === $pubMethod && $path === $pubPath) {
                return $next($req);
            }
        }

        foreach (self::WHITELIST_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($req);
            }
        }

        $auth = $req->header('Authorization');
        if ($auth === null || !str_starts_with($auth, 'Bearer ')) {
            Response::error(40101, '未登录或 token 失效', 401);
            return new Response();
        }

        $token = substr($auth, 7);
        $payload = Jwt::verify($token, (string) Env::get('JWT_SECRET'));
        if ($payload === null || ($payload['type'] ?? '') !== 'access') {
            Response::error(40101, '未登录或 token 失效', 401);
            return new Response();
        }

        $req->setAttribute('user_id', (int) ($payload['sub'] ?? 0));
        $req->setAttribute('username', (string) ($payload['username'] ?? ''));

        return $next($req);
    }
}
