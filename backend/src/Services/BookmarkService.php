<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Repositories\BookmarkCategoryRepository;
use Nytab\Repositories\BookmarkRepository;
use Nytab\Utils\Validator;
use RuntimeException;

/**
 * Bookmark domain service.
 *
 * Orchestrates BookmarkRepository + BookmarkCategoryRepository +
 * IconFetcherService. Enforces:
 *   - URL scheme safety (http/https only — blocks javascript:, data:,
 *     file: etc. via Utils\Validator::isSafeUrl).
 *   - User isolation (every call carries the authenticated user_id).
 *   - Best-effort favicon auto-fetch on create: deferred to a shutdown
 *     handler so the response is returned before the network round-trip.
 *   - Multipart icon upload validation (image/* mime, ≤ 2 MiB).
 *
 * Validation errors throw \InvalidArgumentException (code 42201 / 40001);
 * missing resources throw RuntimeException (code 40401); server-side
 * failures throw RuntimeException (code 50001).
 */
final class BookmarkService
{
    private const MAX_ICON_BYTES = 2 * 1024 * 1024; // 2 MiB

    private BookmarkRepository $bookmarks;

    private BookmarkCategoryRepository $categories;

    private IconFetcherService $iconFetcher;

    private string $iconsDir;

    private string $iconsUrlPrefix;

    public function __construct()
    {
        $this->bookmarks = new BookmarkRepository();
        $this->categories = new BookmarkCategoryRepository();
        $this->iconFetcher = new IconFetcherService();
        $this->iconsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;
        $this->iconsUrlPrefix = '/uploads/icons/';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(int $userId, ?int $catId, string $keyword): array
    {
        return $this->bookmarks->list($userId, $catId, $keyword);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $userId, int $id): ?array
    {
        return $this->bookmarks->find($id, $userId);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function create(int $userId, array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('title 不能为空', 42201);
        }
        if ($url === '' || !Validator::isSafeUrl($url)) {
            throw new \InvalidArgumentException('url 无效或 scheme 不被允许(仅支持 http/https)', 42201);
        }

        $payload = $data;
        $payload['title'] = $title;
        $payload['url'] = $url;

        $id = $this->bookmarks->create($userId, $payload);
        $bookmark = $this->bookmarks->find($id, $userId);
        if ($bookmark === null) {
            throw new RuntimeException('书签创建失败', 50001);
        }

        // If no icon_url was supplied, schedule a best-effort favicon
        // fetch in the shutdown phase so the HTTP response is returned
        // before the (5s-timeout) network round-trip completes. The
        // shutdown handler re-queries the DB on its own PDO connection.
        $providedIcon = $data['icon_url'] ?? null;
        if ($providedIcon === null || $providedIcon === '') {
            $this->scheduleFaviconFetch($userId, $id, $url);
        }

        return $bookmark;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function update(int $userId, int $id, array $data): array
    {
        $existing = $this->bookmarks->find($id, $userId);
        if ($existing === null) {
            throw new RuntimeException('书签不存在', 40401);
        }

        if (isset($data['url'])) {
            $url = trim((string) $data['url']);
            if ($url === '' || !Validator::isSafeUrl($url)) {
                throw new \InvalidArgumentException(
                    'url 无效或 scheme 不被允许(仅支持 http/https)',
                    42201
                );
            }
            $data['url'] = $url;
        }
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('title 不能为空', 42201);
            }
            $data['title'] = $title;
        }

        $this->bookmarks->update($id, $userId, $data);
        $refreshed = $this->bookmarks->find($id, $userId);
        return $refreshed ?? $existing;
    }

    public function delete(int $userId, int $id): void
    {
        $existing = $this->bookmarks->find($id, $userId);
        if ($existing === null) {
            throw new RuntimeException('书签不存在', 40401);
        }
        $this->bookmarks->delete($id, $userId);
    }

    /**
     * @param array<int,array{id:int,sort_order:int}> $items
     */
    public function reorder(int $userId, array $items): void
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('items 必须是数组', 40001);
        }
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || !array_key_exists('sort_order', $item)) {
                throw new \InvalidArgumentException(
                    'items 中每个元素需包含 id 与 sort_order',
                    40001
                );
            }
        }
        $this->bookmarks->reorder($userId, $items);
    }

    /**
     * Accept a multipart-uploaded icon (($_FILES['icon']) for bookmark
     * $id. Validates upload status, size (≤ 2 MiB), and detected MIME
     * (image/*). Persists the file under uploads/icons/ and updates the
     * bookmark's icon_url. Returns the public URL path.
     *
     * @param array<string,mixed> $file $_FILES entry
     */
    public function uploadIcon(int $userId, int $id, array $file): string
    {
        $existing = $this->bookmarks->find($id, $userId);
        if ($existing === null) {
            throw new RuntimeException('书签不存在', 40401);
        }

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(
                $this->uploadErrorMessage($err),
                $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 41301 : 40001
            );
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('上传文件无效', 40001);
        }

        $size = (int) ($file['size'] ?? filesize($tmpName) ?: 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('上传文件为空', 40001);
        }
        if ($size > self::MAX_ICON_BYTES) {
            throw new \InvalidArgumentException(
                '图标大小不能超过 2MB',
                41301
            );
        }

        // Prefer the server-detected MIME over the client-supplied one
        // (the client value is trivially spoofable).
        $detectedMime = function_exists('mime_content_type')
            ? (string) (mime_content_type($tmpName) ?: '')
            : '';
        $clientMime = (string) ($file['type'] ?? '');
        $mime = $detectedMime !== '' ? $detectedMime : $clientMime;
        if ($mime === '' || !preg_match('#^image/#i', $mime)) {
            throw new \InvalidArgumentException('仅允许图片文件(image/*)', 42201);
        }

        $ext = $this->extensionForMime($mime);
        $contents = file_get_contents($tmpName);
        if ($contents === false) {
            throw new RuntimeException('读取上传文件失败', 50001);
        }

        if (!$this->ensureDir($this->iconsDir)) {
            throw new RuntimeException('图标目录不可写', 50001);
        }

        $filename = sprintf('icon_%d_%s.%s', $id, substr(sha1($contents), 0, 12), $ext);
        $dest = $this->iconsDir . $filename;
        if (!@move_uploaded_file($tmpName, $dest)) {
            // Fallback for test harnesses where the file isn't a real
            // SAPI upload — copy then unlink.
            if (!@copy($tmpName, $dest)) {
                throw new RuntimeException('图标保存失败', 50001);
            }
            @unlink($tmpName);
        }

        $url = $this->iconsUrlPrefix . $filename;
        $this->bookmarks->updateIcon($id, $userId, $url);
        return $url;
    }

    /**
     * Re-fetch the favicon for an existing bookmark and update its
     * icon_url. Synchronous (unlike the deferred fetch on create) so
     * the caller gets the new URL back in the same request. Throws
     * RuntimeException (40401) if the bookmark does not exist and
     * RuntimeException (50001) if the favicon download fails.
     */
    public function refetchIcon(int $userId, int $bookmarkId): string
    {
        $existing = $this->bookmarks->find($bookmarkId, $userId);
        if ($existing === null) {
            throw new RuntimeException('书签不存在', 40401);
        }
        $url = (string) ($existing['url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('书签 URL 为空', 42201);
        }
        $iconUrl = $this->iconFetcher->fetchFavicon($url);
        if ($iconUrl === null) {
            throw new RuntimeException('图标获取失败', 50001);
        }
        $this->bookmarks->updateIcon($bookmarkId, $userId, $iconUrl);
        return $iconUrl;
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categoryTree(int $userId): array
    {
        return $this->categories->tree($userId);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function createCategory(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('分类名称不能为空', 42201);
        }
        if (mb_strlen($name) > 64) {
            throw new \InvalidArgumentException('分类名称不能超过 64 字符', 42201);
        }

        $parentId = null;
        if (isset($data['parent_id']) && $data['parent_id'] !== null) {
            $parentId = (int) $data['parent_id'];
            if ($parentId <= 0) {
                throw new \InvalidArgumentException('parent_id 无效', 42201);
            }
            $parent = $this->categories->find($parentId, $userId);
            if ($parent === null) {
                throw new \InvalidArgumentException('父分类不存在', 42201);
            }
        }
        $icon = isset($data['icon']) && $data['icon'] !== null
            ? (string) $data['icon'] : null;

        $id = $this->categories->create($userId, $parentId, $name, $icon);
        $created = $this->categories->find($id, $userId);
        if ($created === null) {
            throw new RuntimeException('分类创建失败', 50001);
        }
        return $created;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public function updateCategory(int $userId, int $id, array $data): array
    {
        $existing = $this->categories->find($id, $userId);
        if ($existing === null) {
            throw new RuntimeException('分类不存在', 40401);
        }
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parentId = (int) $data['parent_id'];
            if ($parentId === $id) {
                throw new \InvalidArgumentException('不能将分类设为自身的子分类', 42201);
            }
            $parent = $this->categories->find($parentId, $userId);
            if ($parent === null) {
                throw new \InvalidArgumentException('父分类不存在', 42201);
            }
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \InvalidArgumentException('分类名称不能为空', 42201);
            }
            if (mb_strlen($name) > 64) {
                throw new \InvalidArgumentException('分类名称不能超过 64 字符', 42201);
            }
            $data['name'] = $name;
        }
        $this->categories->update($id, $userId, $data);
        $refreshed = $this->categories->find($id, $userId);
        return $refreshed ?? $existing;
    }

    public function deleteCategory(int $userId, int $id): void
    {
        $existing = $this->categories->find($id, $userId);
        if ($existing === null) {
            throw new RuntimeException('分类不存在', 40401);
        }
        $this->categories->delete($id, $userId);
    }

    /**
     * @param array<int,array{id:int,sort_order:int}> $items
     */
    public function reorderCategories(int $userId, array $items): void
    {
        if (!is_array($items)) {
            throw new \InvalidArgumentException('items 必须是数组', 40001);
        }
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || !array_key_exists('sort_order', $item)) {
                throw new \InvalidArgumentException(
                    'items 中每个元素需包含 id 与 sort_order',
                    40001
                );
            }
        }
        $this->categories->reorder($userId, $items);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Schedule a favicon fetch in the shutdown phase. Uses fresh
     * repository instances because the request's PDO handle may have
     * been closed by the time the handler fires.
     */
    private function scheduleFaviconFetch(int $userId, int $bookmarkId, string $url): void
    {
        register_shutdown_function(function () use ($userId, $bookmarkId, $url): void {
            try {
                $iconUrl = $this->iconFetcher->fetchFavicon($url);
                if ($iconUrl !== null) {
                    // Re-instantiate repositories in case the request
                    // pipeline has torn down shared state.
                    (new BookmarkRepository())->updateIcon($bookmarkId, $userId, $iconUrl);
                }
            } catch (Throwable $e) {
                // Best-effort: swallow all errors.
            }
        });
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml', 'image/svg' => 'svg',
            'image/bmp' => 'bmp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'bin',
        };
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '上传文件过大',
            UPLOAD_ERR_PARTIAL => '文件仅部分上传',
            UPLOAD_ERR_NO_FILE => '未上传文件',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
            UPLOAD_ERR_EXTENSION => '被 PHP 扩展阻止',
            default => '上传失败',
        };
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) && is_writable($dir);
    }
}
