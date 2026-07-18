<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Request;
use Nytab\Core\Response;

/**
 * JSON request body validator.
 *
 * For POST/PUT/PATCH requests that declare an application/json Content-Type,
 * verifies the raw body parses as valid JSON. A syntax error yields 422
 * (code=42201). Positioned after CorsMiddleware and before AuthMiddleware.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function handle(Request $req, callable $next): Response
    {
        $method = $req->method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $next($req);
            return new Response();
        }

        $contentType = $req->header('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $next($req);
            return new Response();
        }

        $raw = $req->rawBody();
        if ($raw !== '') {
            json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error(42201, '请求体 JSON 格式错误', 422);
                return new Response();
            }
        }

        $next($req);
        return new Response();
    }
}
