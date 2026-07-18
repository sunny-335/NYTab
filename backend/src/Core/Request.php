<?php
declare(strict_types=1);

namespace Nytab\Core;

/**
 * Request envelope for the current HTTP request.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $routeParams = [];

    private ?string $path = null;

    private ?string $method = null;

    /** @var array<string,mixed>|null */
    private ?array $bodyCache = null;

    private ?string $rawBodyCache = null;

    /** @var array<string,mixed> */
    private array $attributes = [];

    public function method(): string
    {
        if ($this->method !== null) {
            return $this->method;
        }

        $override = $this->header('X-HTTP-Method-Override');
        if ($override !== null && $override !== '') {
            $this->method = strtoupper($override);
            return $this->method;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->method = strtoupper($method);
        return $this->method;
    }

    public function path(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parts = explode('?', $uri, 2);
        $path = $parts[0];

        // Strip the /api prefix if present.
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (str_starts_with($path, '/api')) {
            $path = substr($path, 4);
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $this->path = $path;
        return $this->path;
    }

    public function header(string $name): ?string
    {
        $normalized = strtolower($name);
        foreach ($this->allHeaders() as $key => $value) {
            if (strtolower($key) === $normalized) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @return array<string,string>
     */
    private function allHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                return $headers;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', ' ', substr($key, 5));
                $name = str_replace(' ', '-', ucwords(strtolower($name)));
                $headers[$name] = $value;
            } elseif (in_array(strtolower($key), ['content_type', 'content_length', 'authorization'], true)) {
                $name = str_replace('_', ' ', $key);
                $name = str_replace(' ', '-', ucwords(strtolower($name)));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $_GET[$name] ?? $default;
    }

    public function body(): array
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }

        $raw = $this->rawBody();
        if ($raw === '') {
            $this->bodyCache = [];
            return $this->bodyCache;
        }

        $contentType = $this->header('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->bodyCache = $decoded;
                return $this->bodyCache;
            }
        }

        $this->bodyCache = [];
        return $this->bodyCache;
    }

    public function rawBody(): string
    {
        if ($this->rawBodyCache !== null) {
            return $this->rawBodyCache;
        }
        $body = file_get_contents('php://input');
        $this->rawBodyCache = $body === false ? '' : $body;
        return $this->rawBodyCache;
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function ip(): string
    {
        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $key) {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $first = trim(explode(',', $value)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        return '0.0.0.0';
    }
}
