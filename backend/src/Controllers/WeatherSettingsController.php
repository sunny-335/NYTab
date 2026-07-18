<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Database;
use Nytab\Core\Request;
use Nytab\Core\Response;
use PDO;
use Throwable;

/**
 * HTTP entry points for /weather/settings.
 *
 *   GET /weather/settings   — return current weather settings; API keys
 *                             are masked (first 4 chars + ****) so the
 *                             full key never reaches the client.
 *   PUT /weather/settings   — update settings. An empty string for
 *                             gaode_key / hefeng_key preserves the
 *                             existing value (lets the UI submit a
 *                             masked key without clobbering it).
 *
 * Authenticated by AuthGuardMiddleware. Persisted in
 * system_settings.weather_settings (JSONB).
 */
final class WeatherSettingsController
{
    private const SETTING_KEY = 'weather_settings';

    private const VALID_PROVIDERS = ['gaode', 'hefeng'];

    /** @var array{provider:string,gaode_key:string,hefeng_key:string,default_city:string,auto_location:bool} */
    private const DEFAULTS = [
        'provider' => 'gaode',
        'gaode_key' => '',
        'hefeng_key' => '',
        'default_city' => '',
        'auto_location' => false,
    ];

    public function get(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $raw = $this->loadSettings();

        Response::json([
            'provider' => $raw['provider'],
            'gaode_key' => $this->maskKey($raw['gaode_key']),
            'hefeng_key' => $this->maskKey($raw['hefeng_key']),
            'default_city' => $raw['default_city'],
            'auto_location' => $raw['auto_location'],
        ]);
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

        $current = $this->loadSettings();

        // provider
        $provider = $current['provider'];
        if (array_key_exists('provider', $body)) {
            $candidate = is_string($body['provider']) ? $body['provider'] : '';
            if (!in_array($candidate, self::VALID_PROVIDERS, true)) {
                Response::error(42201, 'provider 必须为 gaode 或 hefeng', 422);
                return;
            }
            $provider = $candidate;
        }

        // gaode_key / hefeng_key: 空字符串保留原值,非空则更新。
        $gaodeKey = $current['gaode_key'];
        if (array_key_exists('gaode_key', $body)) {
            $v = is_string($body['gaode_key']) ? $body['gaode_key'] : '';
            if ($v !== '') {
                $gaodeKey = $v;
            }
        }
        $hefengKey = $current['hefeng_key'];
        if (array_key_exists('hefeng_key', $body)) {
            $v = is_string($body['hefeng_key']) ? $body['hefeng_key'] : '';
            if ($v !== '') {
                $hefengKey = $v;
            }
        }

        // default_city
        $defaultCity = $current['default_city'];
        if (array_key_exists('default_city', $body)) {
            $defaultCity = is_string($body['default_city']) ? $body['default_city'] : '';
        }

        // auto_location: 必须为布尔值
        $autoLocation = $current['auto_location'];
        if (array_key_exists('auto_location', $body)) {
            $v = $body['auto_location'];
            if (!is_bool($v)) {
                Response::error(42201, 'auto_location 必须为布尔值', 422);
                return;
            }
            $autoLocation = $v;
        }

        $next = [
            'provider' => $provider,
            'gaode_key' => $gaodeKey,
            'hefeng_key' => $hefengKey,
            'default_city' => $defaultCity,
            'auto_location' => $autoLocation,
        ];

        try {
            $this->saveSettings($next);
        } catch (Throwable $e) {
            Response::error(50001, 'weather_settings 保存失败: ' . $e->getMessage(), 500);
            return;
        }

        Response::json([
            'provider' => $next['provider'],
            'gaode_key' => $this->maskKey($next['gaode_key']),
            'hefeng_key' => $this->maskKey($next['hefeng_key']),
            'default_city' => $next['default_city'],
            'auto_location' => $next['auto_location'],
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireAuth(Request $req): bool
    {
        $userId = (int) $req->getAttribute('user_id', 0);
        if ($userId <= 0) {
            Response::error(40101, '未登录', 401);
            return false;
        }
        return true;
    }

    /**
     * @return array{provider:string,gaode_key:string,hefeng_key:string,default_city:string,auto_location:bool}
     */
    private function loadSettings(): array
    {
        $merged = self::DEFAULTS;
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT value FROM system_settings WHERE key = :key');
            $stmt->execute([':key' => self::SETTING_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['value'])) {
                $decoded = json_decode((string) $row['value'], true);
                if (is_array($decoded)) {
                    foreach (['provider', 'gaode_key', 'hefeng_key', 'default_city'] as $f) {
                        if (isset($decoded[$f]) && is_string($decoded[$f])) {
                            $merged[$f] = $decoded[$f];
                        }
                    }
                    if (isset($decoded['auto_location']) && is_bool($decoded['auto_location'])) {
                        $merged['auto_location'] = $decoded['auto_location'];
                    }
                }
            }
        } catch (Throwable $e) {
            // fall through with defaults
        }
        return $merged;
    }

    /**
     * @param array{provider:string,gaode_key:string,hefeng_key:string,default_city:string,auto_location:bool} $s
     *
     * @throws Throwable When the upsert fails.
     */
    private function saveSettings(array $s): void
    {
        $pdo = Database::connection();
        // ON CONFLICT ... DO UPDATE works on both PostgreSQL and
        // SQLite (>= 3.24, required by the dev-mode migration set).
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (key, value) VALUES (:key, :value) '
            . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $ok = $stmt->execute([
            ':key' => self::SETTING_KEY,
            ':value' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (!$ok) {
            throw new \RuntimeException('upsert failed');
        }
    }

    /**
     * Mask an API key for display: keep the first 4 chars (or fewer if
     * the key is shorter), append `****`. Empty keys stay empty.
     */
    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $len = mb_strlen($key, 'UTF-8');
        if ($len <= 4) {
            return '****';
        }
        return mb_substr($key, 0, 4, 'UTF-8') . '****';
    }
}
