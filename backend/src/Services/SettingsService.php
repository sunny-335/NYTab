<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Settings domain service.
 *
 * Reads/writes JSONB keys of system_settings. Currently backs the
 * custom-background feature (key = `background`).
 *
 * Validation errors throw \InvalidArgumentException (code 42201);
 * server-side failures throw RuntimeException (code 50001).
 */
final class SettingsService
{
    private const SETTING_KEY = 'background';

    /**
     * Default background payload used when the system is not yet
     * installed, the DB is unreachable, or no value has been persisted.
     */
    private const DEFAULTS = [
        'type' => 'bing',
        'url' => '',
        'lastUpdate' => null,
    ];

    /** Allowed background types. */
    private const ALLOWED_TYPES = ['image', 'api', 'bing'];

    private const URL_MAX = 1024;

    /**
     * Return the current background payload. Falls back to defaults
     * when the system is not yet installed or the DB is unreachable,
     * so the homepage / login page can call this endpoint safely.
     *
     * @return array{type:string,url:string,lastUpdate:string|null}
     */
    public function getBackground(): array
    {
        $stored = $this->loadStored();

        $background = array_merge(self::DEFAULTS, $stored);
        $background['type'] = $this->clampType($background['type']);
        $background['url'] = $this->clampString($background['url'], self::DEFAULTS['url']);
        $background['lastUpdate'] = $this->clampLastUpdate($background['lastUpdate']);

        return $background;
    }

    /**
     * Validate and persist the supplied background payload.
     *
     * `url` is required for `image`/`api` types and ignored (cleared)
     * for `bing`. `lastUpdate` is always rewritten to the current time.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{type:string,url:string,lastUpdate:string|null}
     */
    public function updateBackground(array $payload): array
    {
        $current = $this->getBackground();

        $type = array_key_exists('type', $payload)
            ? trim((string) $payload['type'])
            : $current['type'];
        $url = array_key_exists('url', $payload)
            ? trim((string) $payload['url'])
            : $current['url'];

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'type 必须为 image / api / bing',
                42201
            );
        }

        if ($type === 'image' || $type === 'api') {
            if ($url === '') {
                throw new \InvalidArgumentException(
                    'url 不能为空',
                    42201
                );
            }
            if (strlen($url) > self::URL_MAX) {
                throw new \InvalidArgumentException(
                    'url 过长(≤' . self::URL_MAX . ' 字符)',
                    42201
                );
            }
        } else {
            // bing: url is unused — clear it so persisted state stays clean.
            $url = '';
        }

        $value = [
            'type' => $type,
            'url' => $url,
            'lastUpdate' => date('c'),
        ];

        $this->upsert($value);

        return $value;
    }

    /**
     * Set type=image and persist the supplied url (used by the upload
     * endpoint). Returns the full background payload after the update.
     *
     * @return array{type:string,url:string,lastUpdate:string|null}
     */
    public function setBackgroundImage(string $url): array
    {
        return $this->updateBackground(['type' => 'image', 'url' => $url]);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadStored(): array
    {
        // The homepage calls GET /settings/background before the DB might
        // be ready; fall back to defaults on any connection/query failure.
        if (!is_file(dirname(__DIR__, 2) . '/config/installed.lock')) {
            return [];
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT value FROM system_settings WHERE key = :key'
            );
            $stmt->execute([':key' => self::SETTING_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !isset($row['value'])) {
                return [];
            }
            $decoded = json_decode((string) $row['value'], true);
            if (!is_array($decoded)) {
                return [];
            }
            $out = [];
            if (array_key_exists('type', $decoded) && is_string($decoded['type'])) {
                $out['type'] = $decoded['type'];
            }
            if (array_key_exists('url', $decoded) && is_string($decoded['url'])) {
                $out['url'] = $decoded['url'];
            }
            if (array_key_exists('lastUpdate', $decoded)) {
                $out['lastUpdate'] = is_string($decoded['lastUpdate'])
                    ? $decoded['lastUpdate']
                    : null;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private function upsert(array $value): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (key, value) VALUES (:key, :value) '
            . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $ok = $stmt->execute([
            ':key' => self::SETTING_KEY,
            ':value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (!$ok) {
            throw new RuntimeException('background 保存失败', 50001);
        }
    }

    private function clampType(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ALLOWED_TYPES, true)
            ? $value
            : self::DEFAULTS['type'];
    }

    private function clampString(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function clampLastUpdate(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
