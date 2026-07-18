<?php
declare(strict_types=1);

namespace Nytab\Utils;

/**
 * Password hashing helper around PHP's native bcrypt implementation.
 *
 * Uses a fixed cost of 12 (per spec 5.1) — strong enough for modern
 * hardware while still keeping verification under ~250ms.
 */
final class Hasher
{
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
