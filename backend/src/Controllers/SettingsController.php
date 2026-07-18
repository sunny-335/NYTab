<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\SettingsService;
use Throwable;

/**
 * HTTP entry points for background settings & upload.
 *
 *   GET  /settings/background  — public (whitelisted in AuthGuardMiddleware
 *                                and SetupGuardMiddleware); returns the
 *                                background config so the homepage / login
 *                                page can render the wallpaper immediately.
 *   PUT  /settings/background  — authenticated; updates type/url.
 *   POST /background/upload    — authenticated; multipart upload
 *                                (≤ 5MB, JPG/PNG/WebP) stored under
 *                                uploads/backgrounds/. Auto-updates the
 *                                background setting to {type:'image', url}.
 *
 * The authenticated routes are enforced by AuthGuardMiddleware before
 * the controller is reached; the per-method user_id check below is
 * defense-in-depth.
 */
final class SettingsController
{
    private const MAX_BACKGROUND_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Allowed MIME types for background uploads. Keys are detected MIME
     * values, values are the file extension used on disk.
     */
    private const ALLOWED_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private SettingsService $service;

    private string $backgroundDir;

    private string $backgroundUrlPrefix;

    public function __construct()
    {
        $this->service = new SettingsService();
        $this->backgroundDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR
            . 'backgrounds' . DIRECTORY_SEPARATOR;
        $this->backgroundUrlPrefix = '/uploads/backgrounds/';
    }

    public function getBackground(Request $req): void
    {
        try {
            Response::json($this->service->getBackground());
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    public function updateBackground(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        // Only type / url are honoured — lastUpdate is always rewritten
        // by the service.
        $payload = [];
        foreach (['type', 'url'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = $body[$field];
            }
        }

        try {
            $background = $this->service->updateBackground($payload);
            Response::json($background);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), 500);
        }
    }

    public function uploadBackground(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        // Accept either `file` (primary) or `background` as the multipart
        // field name for caller convenience.
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            $file = $_FILES['background'] ?? null;
            if (!is_array($file)) {
                Response::error(40001, '未上传背景文件', 400);
                return;
            }
        }

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $http = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) ? 413 : 400;
            Response::error($http === 413 ? 41301 : 40001, $this->uploadErrorMessage($err), $http);
            return;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            Response::error(40001, '上传文件无效', 400);
            return;
        }

        $size = (int) ($file['size'] ?? (filesize($tmpName) ?: 0));
        if ($size <= 0) {
            Response::error(40001, '上传文件为空', 400);
            return;
        }
        if ($size > self::MAX_BACKGROUND_BYTES) {
            Response::error(41301, '背景图片大小不能超过 5MB', 413);
            return;
        }

        $detectedMime = function_exists('mime_content_type')
            ? (string) (mime_content_type($tmpName) ?: '')
            : '';
        $clientMime = (string) ($file['type'] ?? '');
        $mime = $detectedMime !== '' ? $detectedMime : $clientMime;
        $mimeLower = strtolower($mime);
        if (!isset(self::ALLOWED_MIME[$mimeLower])) {
            Response::error(42201, '仅允许 JPG / PNG / WebP 格式', 422);
            return;
        }
        $ext = self::ALLOWED_MIME[$mimeLower];

        if (!$this->ensureDir($this->backgroundDir)) {
            Response::error(50001, 'backgrounds 目录不可写', 500);
            return;
        }

        // {timestamp}_{random16hex}.{ext}
        $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $this->backgroundDir . $filename;
        if (!@move_uploaded_file($tmpName, $dest)) {
            // Fallback for test harnesses where the file isn't a real
            // SAPI upload — copy then unlink.
            if (!@copy($tmpName, $dest)) {
                Response::error(50001, '背景图片保存失败', 500);
                return;
            }
            @unlink($tmpName);
        }

        $url = $this->backgroundUrlPrefix . $filename;

        try {
            // Persist the new background config (type=image, url=$url).
            $this->service->setBackgroundImage($url);
            Response::json(['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), 500);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Defense-in-depth auth check. AuthGuardMiddleware already enforces
     * authentication for PUT/POST; this catches the case where the
     * global pipeline is bypassed (e.g. misconfigured routes).
     */
    private function requireAuth(Request $req): bool
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return false;
        }
        return true;
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) && is_writable($dir);
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
}
