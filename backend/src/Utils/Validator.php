<?php
declare(strict_types=1);

namespace Nytab\Utils;

/**
 * Pure validation helpers used by the setup flow and (later) the auth
 * profile module. All methods are static and side-effect free.
 */
final class Validator
{
    /**
     * Password strength: length >= 8 AND contains at least 3 of the 4
     * character categories (lowercase, uppercase, digit, special).
     */
    public static function isStrongPassword(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }
        $categories = 0;
        if (preg_match('/[a-z]/', $password)) {
            $categories++;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $categories++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $categories++;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $categories++;
        }
        return $categories >= 3;
    }

    /**
     * Username: 3-64 chars of [a-zA-Z0-9_].
     */
    public static function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{3,64}$/', $username);
    }

    /**
     * Email format check via PHP's built-in validator.
     */
    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * URL safety check: only http/https schemes are allowed. Prevents
     * javascript:, data:, file: etc. (per spec bookmark validation).
     */
    public static function isSafeUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }
}
