<?php
declare(strict_types=1);

namespace Nytab\Utils;

use Nytab\Core\Env;

/**
 * Self-contained HS256 JWT implementation (no third-party deps).
 *
 * Token layout: "<headerB64>.<payloadB64>.<signatureB64>" where every
 * segment is base64url-encoded. The signature is the raw HMAC-SHA256 of
 * "<headerB64>.<payloadB64>" keyed with the shared secret.
 *
 * Standard claims used:
 *  - iss: 'nytab'
 *  - sub: user_id (int)
 *  - iat: issued-at (unix seconds)
 *  - exp: expiry    (unix seconds)
 *  - jti: unique token id (16 hex chars)
 *  - type: 'access' | 'refresh'
 *  - username: the user's login name
 *
 * The secret and TTLs are sourced from Env (JWT_SECRET, JWT_ACCESS_TTL,
 * JWT_REFRESH_TTL) per spec 5.1.
 */
final class Jwt
{
    private const ALG = 'HS256';
    private const TYP = 'JWT';
    private const ISS = 'nytab';

    /**
     * Sign a payload with the shared secret, auto-injecting iat/exp/jti.
     *
     * @param array<string,mixed> $payload
     */
    public static function sign(array $payload, string $secret, int $ttl): string
    {
        $now = time();
        $payload['iss'] = self::ISS;
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;
        $payload['jti'] = bin2hex(random_bytes(8));

        $headerJson = json_encode(
            ['alg' => self::ALG, 'typ' => self::TYP],
            JSON_UNESCAPED_SLASHES
        );
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $headerB64 = self::base64UrlEncode((string) $headerJson);
        $payloadB64 = self::base64UrlEncode((string) $payloadJson);
        $sigB64 = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );

        return "{$headerB64}.{$payloadB64}.{$sigB64}";
    }

    /**
     * Verify the signature and expiry. Returns the payload on success or
     * null if the token is malformed, signed incorrectly, or expired.
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
        );
        // Timing-safe comparison to resist signature oracles.
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $payload = self::decodeJson($payloadB64);
        if ($payload === null) {
            return null;
        }

        $exp = $payload['exp'] ?? null;
        if (!is_numeric($exp) || (int) $exp < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Convenience: issue an access token using Env-sourced secret + TTL.
     */
    public static function issueAccessToken(int $userId, string $username): string
    {
        return self::sign(
            [
                'sub' => $userId,
                'username' => $username,
                'type' => 'access',
            ],
            (string) Env::get('JWT_SECRET', ''),
            (int) Env::get('JWT_ACCESS_TTL', 3600)
        );
    }

    /**
     * Convenience: issue a refresh token using Env-sourced secret + TTL.
     */
    public static function issueRefreshToken(int $userId, string $username): string
    {
        return self::sign(
            [
                'sub' => $userId,
                'username' => $username,
                'type' => 'refresh',
            ],
            (string) Env::get('JWT_SECRET', ''),
            (int) Env::get('JWT_REFRESH_TTL', 604800)
        );
    }

    /**
     * Decode the payload without verifying the signature. Intended for
     * debugging/inspection only — never trust the result for authz.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        return self::decodeJson($parts[1]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function decodeJson(string $b64): ?array
    {
        $raw = self::base64UrlDecode($b64);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $padded = strtr($data, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
