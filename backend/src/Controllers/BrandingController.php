<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\BrandingService;
use Throwable;

/**
 * HTTP entry points for /branding/*.
 *
 *   GET  /branding        — public (whitelisted in AuthGuardMiddleware
 *                           and SetupGuardMiddleware); returns the
 *                           branding payload (incl. hardcoded copyright)
 *                           for the login page / install wizard.
 *   PUT  /branding        — authenticated; updates nickname/title/logo.
 *                           The `copyright` field is silently ignored.
 *   POST /branding/logo   — authenticated; multipart upload (≤ 500 KB,
 *                           PNG/JPG/SVG) stored under uploads/branding/.
 *
 * The authenticated routes are enforced by AuthGuardMiddleware before
 * the controller is reached; the per-method user_id check below is
 * defense-in-depth.
 */
final class BrandingController
{
    private const MAX_LOGO_BYTES = 500 * 1024; // 500 KB

    /**
     * Allowed MIME types for logo uploads. Keys are detected MIME
     * values, values are the file extension used on disk.
     */
    private const ALLOWED_MIME = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/svg+xml' => 'svg',
        'image/svg' => 'svg',
    ];

    private BrandingService $service;

    private string $brandingDir;

    private string $brandingUrlPrefix;

    public function __construct()
    {
        $this->service = new BrandingService();
        $this->brandingDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR
            . 'branding' . DIRECTORY_SEPARATOR;
        $this->brandingUrlPrefix = '/uploads/branding/';
    }

    public function get(Request $req): void
    {
        try {
            Response::json($this->service->get());
        } catch (Throwable $e) {
            Response::error(50001, $e->getMessage(), 500);
        }
    }

    public function update(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $body = $req->body();
        if (empty($body)) {
            Response::error(40001, '请求体为空', 400);
            return;
        }

        // Only nickname / title / logo are honoured. Any `copyright`
        // key in the payload is deliberately ignored — the service
        // re-attaches the hardcoded value on return.
        $payload = [];
        foreach (['nickname', 'title', 'logo'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = $body[$field];
            }
        }

        try {
            $branding = $this->service->update($payload);
            Response::json($branding);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 50001, $e->getMessage(), 500);
        }
    }

    public function uploadLogo(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $file = $_FILES['logo'] ?? null;
        if (!is_array($file)) {
            Response::error(40001, '未上传 logo 文件', 400);
            return;
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
        if ($size > self::MAX_LOGO_BYTES) {
            Response::error(41301, 'logo 大小不能超过 500KB', 413);
            return;
        }

        $detectedMime = function_exists('mime_content_type')
            ? (string) (mime_content_type($tmpName) ?: '')
            : '';
        $clientMime = (string) ($file['type'] ?? '');
        $mime = $detectedMime !== '' ? $detectedMime : $clientMime;
        $mimeLower = strtolower($mime);
        if (!isset(self::ALLOWED_MIME[$mimeLower])) {
            Response::error(42201, '仅允许 PNG / JPG / SVG 格式', 422);
            return;
        }
        $ext = self::ALLOWED_MIME[$mimeLower];

        if (!$this->ensureDir($this->brandingDir)) {
            Response::error(50001, 'branding 目录不可写', 500);
            return;
        }

        $filename = 'logo.' . $ext;
        $dest = $this->brandingDir . $filename;
        if (!@move_uploaded_file($tmpName, $dest)) {
            // Fallback for test harnesses where the file isn't a real
            // SAPI upload — copy then unlink.
            if (!@copy($tmpName, $dest)) {
                Response::error(50001, 'logo 保存失败', 500);
                return;
            }
            @unlink($tmpName);
        }

        $logoPath = $this->brandingUrlPrefix . $filename;

        try {
            $branding = $this->service->updateLogo($logoPath);
            Response::json($branding);
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
     * authentication for PUT/POST /branding; this catches the case
     * where the global pipeline is bypassed (e.g. misconfigured routes).
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
