<?php
declare(strict_types=1);

namespace Nytab\Services;

/**
 * Best-effort favicon downloader.
 *
 * Given a bookmark URL, fetches the site's favicon and stores it under
 * `uploads/icons/<sha1(url)>.png`. The lookup order is:
 *   1. https://<host>/favicon.ico (direct)
 *   2. https://www.google.com/s2/favicons?domain=<host>&sz=64 (fallback)
 *
 * All network errors are swallowed and the method returns null — this
 * service is fire-and-forget from the bookmark creation flow and must
 * never bubble up to the caller. Each HTTP request is capped at 5
 * seconds total (connect + read) via the stream context.
 */
final class IconFetcherService
{
    private const TIMEOUT_SECONDS = 5;

    private string $iconsDir;

    private string $iconsUrlPrefix;

    public function __construct()
    {
        // backend/uploads/icons/
        $this->iconsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . 'uploads' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;
        $this->iconsUrlPrefix = '/uploads/icons/';
    }

    /**
     * Download the favicon for $url and return its public URL path
     * (e.g. `/uploads/icons/<sha1>.png`), or null on any failure.
     */
    public function fetchFavicon(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $binary = $this->download('https://' . $host . '/favicon.ico');
        if ($binary === null) {
            $binary = $this->download(
                'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=64'
            );
        }
        if ($binary === null || $binary === '') {
            return null;
        }

        if (!$this->ensureDir($this->iconsDir)) {
            return null;
        }

        $filename = sha1($url) . '.png';
        $path = $this->iconsDir . $filename;

        $written = @file_put_contents($path, $binary);
        if ($written === false || $written === 0) {
            return null;
        }
        return $this->iconsUrlPrefix . $filename;
    }

    /**
     * GET $url with a 5-second total timeout. Returns the response body
     * as a string, or null if the request failed, returned a non-2xx
     * status, or yielded an empty body. Uses the PHP stream wrapper so
     * no ext-curl dependency is required.
     *
     * Note: $http_response_header is auto-populated by PHP in the local
     * scope of the file_get_contents caller, so the status inspection
     * must live in this same method.
     */
    private function download(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => (string) self::TIMEOUT_SECONDS,
                'user_agent' => 'NYTab/1.0 IconFetcher',
                'follow_location' => true,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // @ to suppress PHP warnings on network failure — we treat any
        // error as "no favicon available" and return null.
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || $body === '') {
            return null;
        }

        // $http_response_header is auto-populated in this scope. Only
        // accept 2xx responses so we don't persist error pages as icons.
        if (isset($http_response_header) && is_array($http_response_header)) {
            $code = null;
            foreach ($http_response_header as $line) {
                if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d{3})/i', $line, $m)) {
                    $code = (int) $m[1];
                }
            }
            if ($code !== null && ($code < 200 || $code >= 300)) {
                return null;
            }
        }
        return $body;
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) && is_writable($dir);
    }
}
