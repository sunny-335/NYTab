<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\BookmarkService;
use Throwable;

/**
 * HTTP entry points for /bookmarks/* and /bookmark-categories/*.
 *
 * Every method pulls the authenticated user_id from the Request
 * attributes (set by AuthGuardMiddleware) — none of the bookmark /
 * category data is ever accessed without a user_id scope.
 *
 * Routing note: in Routes/api.php `/bookmarks/{id}` is registered
 * BEFORE `/bookmarks/reorder`. Since Router dispatches the first
 * matching pattern, `PUT /bookmarks/reorder` is captured by the
 * `{id}` route with id="reorder". The update() / updateCategory()
 * methods therefore detect the literal "reorder" id and delegate
 * internally — no change to api.php required. (Same story applies to
 * `/bookmark-categories/reorder`.)
 */
final class BookmarkController
{
    private BookmarkService $service;

    public function __construct()
    {
        $this->service = new BookmarkService();
    }

    // ------------------------------------------------------------------
    // Bookmarks
    // ------------------------------------------------------------------

    public function list(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        $catId = $req->query('category_id');
        $catId = $catId === null ? null : (int) $catId;
        $keyword = trim((string) ($req->query('keyword') ?? ''));

        try {
            $rows = $this->service->list($userId, $catId, $keyword);
            Response::json($rows);
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    public function create(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        try {
            $bookmark = $this->service->create($userId, $body);
            Response::json($bookmark, 0, 'ok', 201);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            $http = $code === 41301 ? 413 : 422;
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), $http);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function show(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }
        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        try {
            $bookmark = $this->service->find($userId, $id);
            if ($bookmark === null) {
                Response::error(40401, '书签不存在', 404);
                return;
            }
            Response::json($bookmark);
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    public function update(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        // Workaround for route-order: PUT /bookmarks/reorder is captured
        // by /bookmarks/{id} (registered earlier). Delegate internally.
        $params = $req->routeParams();
        $idParam = (string) ($params['id'] ?? '');
        if ($idParam === 'reorder') {
            $this->reorder($req);
            return;
        }

        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        try {
            $bookmark = $this->service->update($userId, $id, $body);
            Response::json($bookmark);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            $http = $code === 41301 ? 413 : 422;
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), $http);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function delete(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }
        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        try {
            $this->service->delete($userId, $id);
            Response::json(null, 0, '已删除');
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function uploadIcon(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }
        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        $file = $_FILES['icon'] ?? null;
        if (!is_array($file)) {
            Response::error(40001, '未上传 icon 文件', 400);
            return;
        }

        try {
            $url = $this->service->uploadIcon($userId, $id, $file);
            Response::json(['icon_url' => $url]);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                41301 => 413,
                40001 => 400,
                default => 422,
            };
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), $http);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function fetchIcon(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }
        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40401, '书签不存在', 404);
            return;
        }

        try {
            $iconUrl = $this->service->refetchIcon($userId, $id);
            Response::json(['icon_url' => $iconUrl]);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function reorder(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        $body = $req->body();
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            Response::error(40001, 'items 字段缺失或非数组', 400);
            return;
        }

        try {
            $this->service->reorder($userId, $items);
            Response::json(null, 0, 'ok');
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    // ------------------------------------------------------------------
    // Bookmark Categories
    // ------------------------------------------------------------------

    public function categoryTree(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        try {
            $tree = $this->service->categoryTree($userId);
            Response::json($tree);
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    public function createCategory(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        try {
            $category = $this->service->createCategory($userId, $body);
            Response::json($category, 0, 'ok', 201);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function updateCategory(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        // Same route-order workaround as update(): PUT
        // /bookmark-categories/reorder is captured by
        // /bookmark-categories/{id}.
        $params = $req->routeParams();
        $idParam = (string) ($params['id'] ?? '');
        if ($idParam === 'reorder') {
            $this->reorderCategories($req);
            return;
        }

        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        try {
            $category = $this->service->updateCategory($userId, $id, $body);
            Response::json($category);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function deleteCategory(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }
        $id = $this->routeId($req);
        if ($id === null) {
            Response::error(40001, 'id 参数无效', 400);
            return;
        }

        try {
            $this->service->deleteCategory($userId, $id);
            Response::json(null, 0, '已删除');
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $http = match ($code) {
                40401 => 404,
                50001 => 500,
                default => 500,
            };
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), $http);
        }
    }

    public function reorderCategories(Request $req): void
    {
        $userId = $this->userId($req);
        if ($userId === null) {
            return;
        }

        $body = $req->body();
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            Response::error(40001, 'items 字段缺失或非数组', 400);
            return;
        }

        try {
            $this->service->reorderCategories($userId, $items);
            Response::json(null, 0, 'ok');
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Extract the authenticated user_id from the Request. Returns null
     * and emits a 401 if the attribute is missing or zero — the
     * AuthGuardMiddleware should already have rejected unauthenticated
     * traffic, this is defense-in-depth.
     */
    private function userId(Request $req): ?int
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return null;
        }
        return $userId;
    }

    /**
     * Extract and validate the {id} route parameter as a positive int.
     * Returns null if absent or not numeric.
     */
    private function routeId(Request $req): ?int
    {
        $params = $req->routeParams();
        $raw = $params['id'] ?? null;
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }
}
