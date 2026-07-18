<?php
declare(strict_types=1);

namespace Nytab\Core;

/**
 * Static helper for emitting the unified JSON envelope `{ code, message, data }`.
 */
final class Response
{
    public static function json(mixed $data, int $code = 0, string $message = 'ok', int $httpStatus = 200): void
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(int $code, string $message, int $httpStatus): void
    {
        self::json(null, $code, $message, $httpStatus);
    }
}
