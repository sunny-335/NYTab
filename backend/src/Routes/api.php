<?php
declare(strict_types=1);

use Nytab\Core\Router;
use Nytab\Middleware\SetupGuardMiddleware;
use Nytab\Middleware\AuthMiddleware;

return function (Router $router): void {
    // Setup (no auth required; SetupGuardMiddleware returns 409 when already installed)
    $router->get('/setup/status', [\Nytab\Controllers\SetupController::class, 'status']);
    $router->post('/setup/test-database', [\Nytab\Controllers\SetupController::class, 'testDatabase']);
    $router->post('/setup/install', [\Nytab\Controllers\SetupController::class, 'install']);

    // Branding — public read endpoint so the login page / install wizard
    // can render the configured nickname/title/logo without authenticating.
    // PUT /branding and POST /branding/logo are registered below inside the
    // authenticated section and rely on AuthGuardMiddleware enforcement.
    $router->get('/branding', [\Nytab\Controllers\BrandingController::class, 'get']);

    // Background — public read endpoint so the homepage / login page can
    // render the wallpaper without authenticating. The PUT update and the
    // POST upload are registered below in the authenticated section.
    $router->get('/settings/background', [\Nytab\Controllers\SettingsController::class, 'getBackground']);

    // Auth
    $router->post('/auth/login', [\Nytab\Controllers\AuthController::class, 'login']);
    $router->post('/auth/refresh', [\Nytab\Controllers\AuthController::class, 'refresh']);
    $router->post('/auth/logout', [\Nytab\Controllers\AuthController::class, 'logout']);
    $router->get('/auth/me', [\Nytab\Controllers\AuthController::class, 'me']);
    $router->put('/profile', [\Nytab\Controllers\ProfileController::class, 'update']);
    $router->put('/profile/password', [\Nytab\Controllers\ProfileController::class, 'changePassword']);

    // Branding — authenticated write endpoints. GET /branding above is
    // public; these mutations require a valid access token. The service
    // layer hardcodes the `copyright` field, so it cannot be modified.
    $router->put('/branding', [\Nytab\Controllers\BrandingController::class, 'update']);
    $router->post('/branding/logo', [\Nytab\Controllers\BrandingController::class, 'uploadLogo']);

    // Background — authenticated write endpoints. GET /settings/background
    // above is public; these mutations require a valid access token.
    $router->put('/settings/background', [\Nytab\Controllers\SettingsController::class, 'updateBackground']);
    $router->post('/background/upload', [\Nytab\Controllers\SettingsController::class, 'uploadBackground']);

    // Bookmarks
    $router->get('/bookmarks', [\Nytab\Controllers\BookmarkController::class, 'list']);
    $router->post('/bookmarks', [\Nytab\Controllers\BookmarkController::class, 'create']);
    $router->get('/bookmarks/{id}', [\Nytab\Controllers\BookmarkController::class, 'show']);
    $router->put('/bookmarks/{id}', [\Nytab\Controllers\BookmarkController::class, 'update']);
    $router->delete('/bookmarks/{id}', [\Nytab\Controllers\BookmarkController::class, 'delete']);
    $router->post('/bookmarks/{id}/icon', [\Nytab\Controllers\BookmarkController::class, 'uploadIcon']);
    $router->post('/bookmarks/{id}/fetch-icon', [\Nytab\Controllers\BookmarkController::class, 'fetchIcon']);
    $router->put('/bookmarks/reorder', [\Nytab\Controllers\BookmarkController::class, 'reorder']);

    // Bookmark Categories
    $router->get('/bookmark-categories', [\Nytab\Controllers\BookmarkController::class, 'categoryTree']);
    $router->post('/bookmark-categories', [\Nytab\Controllers\BookmarkController::class, 'createCategory']);
    $router->put('/bookmark-categories/{id}', [\Nytab\Controllers\BookmarkController::class, 'updateCategory']);
    $router->delete('/bookmark-categories/{id}', [\Nytab\Controllers\BookmarkController::class, 'deleteCategory']);
    $router->put('/bookmark-categories/reorder', [\Nytab\Controllers\BookmarkController::class, 'reorderCategories']);

    // Workspace
    $router->get('/workspace/layout', [\Nytab\Controllers\WorkspaceController::class, 'getLayout']);
    $router->put('/workspace/layout', [\Nytab\Controllers\WorkspaceController::class, 'updateLayout']);
    $router->get('/workspace/settings', [\Nytab\Controllers\WorkspaceController::class, 'getSettings']);
    $router->put('/workspace/settings', [\Nytab\Controllers\WorkspaceController::class, 'updateSettings']);

    // Tools
    $router->get('/tools/registry', [\Nytab\Controllers\ToolController::class, 'registry']);
    $router->get('/tools/{pluginId}/state', [\Nytab\Controllers\ToolController::class, 'getState']);
    $router->put('/tools/{pluginId}/state', [\Nytab\Controllers\ToolController::class, 'updateState']);
    $router->delete('/tools/{pluginId}/state', [\Nytab\Controllers\ToolController::class, 'deleteState']);

    // Developer Mode (auth required; registered under the AuthGuard-protected group)
    $router->get('/dev-mode/status', [\Nytab\Controllers\DeveloperModeController::class, 'status']);
    $router->post('/dev-mode/enable', [\Nytab\Controllers\DeveloperModeController::class, 'enable']);
    $router->post('/dev-mode/disable', [\Nytab\Controllers\DeveloperModeController::class, 'disable']);

    // Weather — proxy to Gaode / HeFeng. All routes require auth
    // (enforced by AuthGuardMiddleware). API keys live in
    // system_settings.weather_settings and are never exposed to the
    // client in plaintext; /weather/settings returns masked keys.
    $router->get('/weather', [\Nytab\Controllers\WeatherController::class, 'show']);
    $router->get('/weather/cities', [\Nytab\Controllers\WeatherController::class, 'cities']);
    $router->get('/weather/settings', [\Nytab\Controllers\WeatherSettingsController::class, 'get']);
    $router->put('/weather/settings', [\Nytab\Controllers\WeatherSettingsController::class, 'update']);
};
