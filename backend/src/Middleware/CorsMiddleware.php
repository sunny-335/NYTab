<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Env;
use Nytab\Core\Request;
use Nytab\Core\Response;

/**
 * CORS guard.
 *
 * Reads the comma-separated whitelist from the CORS_ORIGINS env var and emits
 * the Access-Control-* headers when the request Origin is allowed. Preflight
 * OPTIONS requests are short-circuited with a 204. Headers are emitted via
 * header() before the downstream Response::json() call writes the body.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $req, callable $next): Response
    {
        $origin = $req->header('Origin') ?? '';
        $allowed = $this->allowedOrigins();

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            if (!headers_sent()) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization');
                header('Access-Control-Max-Age: 86400');
            }
        }

        if ($req->method() === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(204);
            }
            return new Response();
        }

        $next($req);
        return new Response();
    }

    /**
     * @return array<int,string>
     */
    private function allowedOrigins(): array
    {
        $raw = (string) (Env::get('CORS_ORIGINS', ''));
        if ($raw === '') {
            return [];
        }
        $origins = array_map('trim', explode(',', $raw));
        return array_values(array_filter(
            $origins,
            static fn (string $value): bool => $value !== ''
        ));
    }
}
