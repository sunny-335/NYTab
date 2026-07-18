<?php
declare(strict_types=1);

use Nytab\Core\Container;
use Nytab\Core\Database;
use Nytab\Core\Env;
use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Core\Router;
use Nytab\Middleware\AuthGuardMiddleware;
use Nytab\Middleware\CorsMiddleware;
use Nytab\Middleware\JsonBodyMiddleware;
use Nytab\Middleware\SetupGuardMiddleware;

// 1. Autoloader: prefer composer, fall back to a PSR-4 manual loader.
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Nytab\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        $file = $base . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// 2. Load environment configuration.
Env::setPath(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

// 3. CORS / preflight, install-state guard, JSON body validation, and
//    authentication are handled by the global middleware pipeline below
//    (CorsMiddleware -> SetupGuardMiddleware -> JsonBodyMiddleware ->
//    AuthGuardMiddleware) before the router dispatches.

// 4. Build the container and register core services.
$container = new Container();

$container->singleton(Database::class, static function (Container $c): Database {
    return new Database();
});

$container->singleton(Request::class, static function (Container $c): Request {
    return new Request();
});

$container->singleton(Router::class, static function (Container $c): Router {
    return new Router();
});

// 5. Build router and load routes.
$router = $container->get(Router::class);
$routesLoader = require __DIR__ . '/../src/Routes/api.php';
if ($routesLoader instanceof Closure) {
    $routesLoader($router);
}

// 6. Dispatch the request through the global middleware pipeline.
//    Order: CorsMiddleware (preflight/headers) -> SetupGuardMiddleware
//    (install state) -> JsonBodyMiddleware (body validation) ->
//    AuthGuardMiddleware (JWT auth) -> Router.
try {
    $request = $container->get(Request::class);

    $pipeline = static function (Request $req) use ($router): void {
        $router->dispatch($req);
    };
    $middlewares = [
        new CorsMiddleware(),
        new SetupGuardMiddleware(),
        new JsonBodyMiddleware(),
        new AuthGuardMiddleware(),
    ];
    foreach (array_reverse($middlewares) as $mw) {
        $next = $pipeline;
        $pipeline = static function (Request $req) use ($mw, $next): Response {
            return $mw->handle($req, $next);
        };
    }
    $pipeline($request);
} catch (Throwable $e) {
    // All uncaught errors become a unified 50001 envelope.
    $appEnv = (string) (Env::get('APP_ENV', 'production'));
    $detail = $appEnv === 'development' ? ' | ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : '';
    Response::error(50001, 'Internal Server Error' . $detail, 500);
}
