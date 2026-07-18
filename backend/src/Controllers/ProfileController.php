<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\AuthService;
use RuntimeException;

/**
 * HTTP entry points for /profile/* — both require authentication
 * (enforced by AuthGuardMiddleware before the controller is reached).
 */
final class ProfileController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function update(Request $req): void
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return;
        }
        try {
            $user = $this->auth->updateProfile($userId, $req->body());
            Response::json($user);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), 500);
        }
    }

    public function changePassword(Request $req): void
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return;
        }
        $body = $req->body();
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        if ($current === '' || $new === '') {
            Response::error(40001, '参数缺失', 400);
            return;
        }
        try {
            $this->auth->changePassword($userId, $current, $new);
            Response::json(null, 0, '密码修改成功');
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), 500);
        }
    }
}
