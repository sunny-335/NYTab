<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\AuthService;
use RuntimeException;

/**
 * HTTP entry points for /auth/*.
 *
 * login & refresh are public (whitelisted in AuthGuardMiddleware);
 * logout & me require an authenticated user (user_id attribute set by
 * AuthGuardMiddleware after JWT verification).
 */
final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function login(Request $req): void
    {
        $body = $req->body();
        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || $password === '') {
            Response::error(40001, '用户名和密码不能为空', 400);
            return;
        }
        try {
            $result = $this->auth->login($username, $password, $req->ip());
            Response::json($result);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40102 => 401,
                42901 => 429,
                default => 401,
            };
            Response::error($code !== 0 ? $code : 40101, $e->getMessage(), $http);
        }
    }

    public function refresh(Request $req): void
    {
        $body = $req->body();
        $token = (string) ($body['refresh_token'] ?? '');
        if ($token === '') {
            // Also accept the token via Authorization: Bearer <refresh>
            $auth = $req->header('Authorization');
            if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
                $token = substr($auth, 7);
            }
        }
        if ($token === '') {
            Response::error(40001, 'refresh_token 缺失', 400);
            return;
        }
        try {
            $result = $this->auth->refresh($token);
            Response::json($result);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 40101, $e->getMessage(), 401);
        }
    }

    public function logout(Request $req): void
    {
        // JWT auth is stateless: logout is a client-side token discard.
        // A server-side token blacklist (jti, exp) can be added here later
        // without changing the route shape.
        Response::json(null, 0, '已登出');
    }

    public function me(Request $req): void
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return;
        }
        try {
            $user = $this->auth->getUser($userId);
            Response::json($user);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 40401, $e->getMessage(), 404);
        }
    }
}
