<?php
declare(strict_types=1);

namespace Nytab\Core;

/**
 * Minimal PSR-style router supporting GET/POST/PUT/DELETE, `{id}` path
 * parameters, and per-route middleware.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:array{0:class-string,1:string}, middleware:array<int,class-string>}> */
    private array $routes = [];

    /** @var callable(Request):void|null */
    private $notFoundHandler = null;

    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = $this->compilePattern($pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $regex,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $req): void
    {
        $method = $req->method();
        $path = $req->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_int($key)) {
                        continue;
                    }
                    $params[$key] = $value;
                }
                $req->setRouteParams($params);

                $handler = $route['handler'];
                $controllerClass = $handler[0];
                $methodName = $handler[1];

                $middlewareChain = $route['middleware'];
                $final = function (Request $request) use ($controllerClass, $methodName): Response {
                    if (method_exists($controllerClass, $methodName)) {
                        $controller = new $controllerClass();
                        $result = $controller->$methodName($request);
                        return $result instanceof Response ? $result : new class {};
                    }
                    return new class {};
                };

                $chain = $final;
                foreach (array_reverse($middlewareChain) as $middlewareClass) {
                    $previous = $chain;
                    $chain = function (Request $request) use ($middlewareClass, $previous): Response {
                        /** @var callable(Request, callable):Response $middleware */
                        $middleware = new $middlewareClass();
                        return $middleware->handle($request, $previous);
                    };
                }

                $chain($req);
                return;
            }
        }

        $this->emitNotFound();
    }

    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    private function emitNotFound(): void
    {
        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)();
            return;
        }
        Response::error(40401, 'Not Found', 404);
    }

    private function compilePattern(string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m): string {
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );
        return '#^' . $regex . '$#';
    }
}
