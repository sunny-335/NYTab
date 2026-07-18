<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Env;
use Nytab\Repositories\LoginLogRepository;
use Nytab\Repositories\UserRepository;
use Nytab\Utils\Hasher;
use Nytab\Utils\Jwt;
use Nytab\Utils\Validator;
use RuntimeException;

/**
 * Authentication & profile domain service.
 *
 * Brute-force protection is layered:
 *  - RateLimitMiddleware (global) short-circuits POST /auth/login after
 *    5 failed attempts from one IP in 5 minutes (HTTP 429).
 *  - AuthService.login() re-checks the same count before touching the
 *    user row — defense in depth if the middleware chain is reordered.
 *
 * No default/admin account is ever auto-created here; the only way users
 * enter the system is via the setup wizard (see SetupService).
 */
final class AuthService
{
    private UserRepository $users;

    private LoginLogRepository $logs;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->logs = new LoginLogRepository();
    }

    /**
     * Authenticate and issue access + refresh tokens.
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,user:array<string,mixed>}
     *
     * @throws RuntimeException on auth failure (code 40102) or rate limit (42901)
     */
    public function login(string $username, string $password, string $ip): array
    {
        // 1. IP-level rate limit (defense-in-depth; RateLimitMiddleware
        //    already returns 429 — this catches direct callers too).
        $failures = $this->logs->countRecentFailures($ip, 5);
        if ($failures >= 5) {
            throw new RuntimeException('尝试过于频繁,请 15 分钟后再试', 42901);
        }

        $user = $this->users->findByUsername($username);

        // 2. User not found OR wrong password — same error to avoid user
        //    enumeration. Always log the failure for rate-limit accounting.
        if ($user === null || !Hasher::verify($password, (string) $user['password_hash'])) {
            $this->logs->record($username, $ip, false);
            throw new RuntimeException('用户名或密码错误', 40102);
        }

        // 3. Account-level lock (set by an admin or future lockout policy).
        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new RuntimeException('账号已锁定,请稍后再试', 42901);
        }

        // 4. Success: audit log + last_login + reset failed_attempts.
        $this->logs->record($username, $ip, true);
        $this->users->updateLastLogin((int) $user['id'], $ip);

        // 5. Issue tokens (Env-sourced secret + TTLs).
        $access = Jwt::issueAccessToken((int) $user['id'], (string) $user['username']);
        $refresh = Jwt::issueRefreshToken((int) $user['id'], (string) $user['username']);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => (int) Env::get('JWT_ACCESS_TTL', 3600),
            'user' => $this->publicUser((array) $user),
        ];
    }

    /**
     * Exchange a valid refresh token for a new access token.
     *
     * @return array{access_token:string,expires_in:int}
     *
     * @throws RuntimeException when the refresh token is invalid (40101)
     */
    public function refresh(string $refreshToken): array
    {
        $payload = Jwt::verify($refreshToken, (string) Env::get('JWT_SECRET'));
        if ($payload === null || ($payload['type'] ?? '') !== 'refresh') {
            throw new RuntimeException('refresh_token 无效', 40101);
        }
        $user = $this->users->findById((int) ($payload['sub'] ?? 0));
        if ($user === null) {
            throw new RuntimeException('用户不存在', 40101);
        }
        $access = Jwt::issueAccessToken((int) $user['id'], (string) $user['username']);
        return [
            'access_token' => $access,
            'expires_in' => (int) Env::get('JWT_ACCESS_TTL', 3600),
        ];
    }

    /**
     * Fetch the public projection of a user by id.
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException when the user is missing (40401)
     */
    public function getUser(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            throw new RuntimeException('用户不存在', 40401);
        }
        return $this->publicUser((array) $user);
    }

    /**
     * Verify the current password, validate the new one, and persist the
     * new bcrypt hash.
     *
     * @throws RuntimeException        when the user is missing (40401)
     * @throws \InvalidArgumentException when the current password is wrong
     *                                   (42201) or the new one is weak (42201)
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('用户不存在', 40401);
        }
        if (!Hasher::verify($currentPassword, (string) $user['password_hash'])) {
            throw new \InvalidArgumentException('当前密码不正确', 42201);
        }
        if (!Validator::isStrongPassword($newPassword)) {
            throw new \InvalidArgumentException(
                '新密码强度不足(≥8 字符且包含大小写/数字/特殊字符中至少 3 类)',
                42201
            );
        }
        $this->users->updatePassword($userId, Hasher::hash($newPassword));
    }

    /**
     * Update mutable profile fields. Username uniqueness and format are
     * validated before write.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed> The updated public user projection.
     *
     * @throws RuntimeException        when the user is missing (40401)
     * @throws \InvalidArgumentException on invalid username/email/uniqueness (42201)
     */
    public function updateProfile(int $userId, array $data): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('用户不存在', 40401);
        }

        if (isset($data['username']) && $data['username'] !== $user['username']) {
            if (!Validator::isValidUsername((string) $data['username'])) {
                throw new \InvalidArgumentException('用户名格式无效', 42201);
            }
            $existing = $this->users->findByUsername((string) $data['username']);
            if ($existing !== null && (int) $existing['id'] !== $userId) {
                throw new \InvalidArgumentException('用户名已存在', 42201);
            }
        }

        if (isset($data['email']) && $data['email'] !== null && !Validator::isValidEmail((string) $data['email'])) {
            throw new \InvalidArgumentException('邮箱格式无效', 42201);
        }

        $this->users->updateProfile($userId, $data);

        $refreshed = $this->users->findById($userId);
        return $this->publicUser((array) $refreshed);
    }

    /**
     * Project a users row into the safe public shape returned by all
     * auth endpoints (no password_hash, no internal lock fields).
     *
     * @param array<string,mixed> $user
     *
     * @return array<string,mixed>
     */
    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'email' => $user['email'] ?? null,
            'display_name' => $user['display_name'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'preferences' => json_decode((string) ($user['preferences'] ?? '{}'), true),
        ];
    }
}
