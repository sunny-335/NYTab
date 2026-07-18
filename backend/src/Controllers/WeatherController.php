<?php
declare(strict_types=1);

namespace Nytab\Controllers;

use Nytab\Core\Database;
use Nytab\Core\Request;
use Nytab\Core\Response;
use Nytab\Services\WeatherService;
use PDO;
use Throwable;

/**
 * HTTP entry points for /weather/*.
 *
 *   GET /weather?city=&lat=&lng=   — proxy to the configured provider
 *                                     (gaode / hefeng). When lat+lng
 *                                     is supplied, Gaode regeo is used
 *                                     to resolve the adcode first.
 *   GET /weather/cities?keyword=   — city search via Gaode geocoding.
 *
 * Authenticated by AuthGuardMiddleware. API keys are sourced from
 * system_settings.weather_settings (managed by WeatherSettingsController);
 * the keys never leave the server — only the masked form is exposed
 * via /weather/settings.
 */
final class WeatherController
{
    private WeatherService $svc;

    public function __construct()
    {
        $this->svc = new WeatherService();
    }

    public function show(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $settings = $this->loadSettings();
        $provider = (string) ($settings['provider'] ?? 'gaode');
        if (!in_array($provider, ['gaode', 'hefeng'], true)) {
            $provider = 'gaode';
        }

        $city = $this->queryString($req, 'city');
        $lat = $this->queryString($req, 'lat');
        $lng = $this->queryString($req, 'lng');

        try {
            $cityCode = $city;
            $resolvedCityName = '';

            // 优先使用经纬度做逆地理,得到 adcode 与显示名。
            if ($this->toFloat($lat) !== null && $this->toFloat($lng) !== null) {
                $gaodeKey = (string) ($settings['gaode_key'] ?? '');
                if ($gaodeKey === '') {
                    Response::error(42201, '高德 API Key 未配置,无法进行逆地理编码', 422);
                    return;
                }
                $geo = $this->svc->reverseGeocode($gaodeKey, (float) $lat, (float) $lng);
                $cityCode = $geo['adcode'];
                $resolvedCityName = $geo['city'];
            }

            if ($cityCode === '') {
                Response::error(40001, '缺少 city 或 lat/lng 参数', 400);
                return;
            }

            if ($provider === 'hefeng') {
                $hefengKey = (string) ($settings['hefeng_key'] ?? '');
                if ($hefengKey === '') {
                    Response::error(42201, '和风 API Key 未配置', 422);
                    return;
                }
                $data = $this->svc->getHeFengWeather($hefengKey, $cityCode);
            } else {
                $gaodeKey = (string) ($settings['gaode_key'] ?? '');
                if ($gaodeKey === '') {
                    Response::error(42201, '高德 API Key 未配置', 422);
                    return;
                }
                $data = $this->svc->getGaodeWeather($gaodeKey, $cityCode);
            }

            // 若逆地理得到了城市名而上游未返回 city,用逆地理结果补齐。
            if ($resolvedCityName !== '' && (string) ($data['city'] ?? '') === '') {
                $data['city'] = $resolvedCityName;
            }

            Response::json($data);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        }
    }

    public function cities(Request $req): void
    {
        if (!$this->requireAuth($req)) {
            return;
        }

        $keyword = $this->queryString($req, 'keyword');
        if (trim($keyword) === '') {
            Response::json(['items' => []]);
            return;
        }

        $settings = $this->loadSettings();
        $gaodeKey = (string) ($settings['gaode_key'] ?? '');
        if ($gaodeKey === '') {
            Response::error(42201, '高德 API Key 未配置', 422);
            return;
        }

        try {
            $list = $this->svc->searchCities($gaodeKey, $keyword);
            Response::json(['items' => $list]);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            Response::error($code !== 0 ? $code : 42201, $e->getMessage(), 422);
        }
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

    private function queryString(Request $req, string $name): string
    {
        $v = $req->query($name);
        if ($v === null) {
            return '';
        }
        return is_string($v) ? $v : (string) $v;
    }

    private function toFloat(mixed $v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return (float) $v;
        }
        return null;
    }

    /**
     * Read raw weather settings from system_settings.weather_settings.
     * Keys are NOT masked here — they are needed to call the upstream
     * APIs. Masking only happens in WeatherSettingsController::get().
     *
     * @return array{provider:string,gaode_key:string,hefeng_key:string,default_city:string,auto_location:bool}
     */
    private function loadSettings(): array
    {
        $defaults = [
            'provider' => 'gaode',
            'gaode_key' => '',
            'hefeng_key' => '',
            'default_city' => '',
            'auto_location' => false,
        ];
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT value FROM system_settings WHERE key = :key');
            $stmt->execute([':key' => 'weather_settings']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['value'])) {
                $decoded = json_decode((string) $row['value'], true);
                if (is_array($decoded)) {
                    foreach (['provider', 'gaode_key', 'hefeng_key', 'default_city'] as $f) {
                        if (isset($decoded[$f]) && is_string($decoded[$f])) {
                            $defaults[$f] = $decoded[$f];
                        }
                    }
                    if (isset($decoded['auto_location']) && is_bool($decoded['auto_location'])) {
                        $defaults['auto_location'] = $decoded['auto_location'];
                    }
                }
            }
        } catch (Throwable $e) {
            // fall through with defaults
        }
        return $defaults;
    }
}
