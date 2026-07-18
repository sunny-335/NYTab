<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Weather domain service.
 *
 * Proxies the Gaode (AMap) and HeFeng (QWeather) HTTP APIs and exposes
 * a 30-minute shared cache stored in system_settings.weather_cache
 * (JSONB; key = cache_key, value = {data, cached_at}).
 *
 * All weather API methods accept an API key from the caller — the
 * controller layer is responsible for sourcing the key from
 * system_settings.weather_settings.
 *
 * Validation errors throw \InvalidArgumentException (code 42201);
 * upstream / transport failures throw RuntimeException (code 42201).
 */
final class WeatherService
{
    private const SETTING_CACHE_KEY = 'weather_cache';

    private const CACHE_TTL = 1800; // 30 minutes

    private const HTTP_TIMEOUT = 10;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const USER_AGENT = 'NYTab/1.0 WeatherProxy';

    /**
     * Fetch Gaode (AMap) weather for a city adcode.
     *
     * Uses extensions=all so the response carries a multi-day forecast;
     * the first cast (today) is normalised into top-level fields and
     * the full cast list is returned under `forecast`. extensions=all
     * does not include humidity, so that field is left empty.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException On missing key/city (code 42201)
     * @throws RuntimeException          On upstream/transport failure (code 42201)
     */
    public function getGaodeWeather(string $apiKey, string $cityCode): array
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('高德 API Key 未配置', 42201);
        }
        if ($cityCode === '') {
            throw new \InvalidArgumentException('city 参数缺失', 42201);
        }

        $cacheKey = 'gaode:weather:' . $cityCode;
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://restapi.amap.com/v3/weather/weatherInfo'
            . '?city=' . rawurlencode($cityCode)
            . '&key=' . rawurlencode($apiKey)
            . '&extensions=all';

        $resp = $this->httpGet($url);

        $status = (string) ($resp['status'] ?? '');
        if ($status !== '1') {
            $info = (string) ($resp['info'] ?? '上游错误');
            throw new RuntimeException('高德天气 API 调用失败: ' . $info, 42201);
        }

        $forecasts = $resp['forecasts'] ?? [];
        $forecast = is_array($forecasts) && isset($forecasts[0]) && is_array($forecasts[0])
            ? $forecasts[0]
            : [];
        $casts = $forecast['casts'] ?? [];
        $today = is_array($casts) && isset($casts[0]) && is_array($casts[0])
            ? $casts[0]
            : [];

        $data = [
            'source' => 'gaode',
            'city' => (string) ($forecast['city'] ?? ''),
            'province' => (string) ($forecast['province'] ?? ''),
            'adcode' => (string) ($forecast['adcode'] ?? $cityCode),
            'report_time' => (string) ($forecast['reporttime'] ?? ''),
            'temp' => (string) ($today['daytemp'] ?? ''),
            'night_temp' => (string) ($today['nighttemp'] ?? ''),
            'condition' => (string) ($today['dayweather'] ?? ''),
            'night_condition' => (string) ($today['nightweather'] ?? ''),
            'humidity' => '', // extensions=all 不返回湿度
            'wind_direction' => (string) ($today['daywind'] ?? ''),
            'wind_power' => (string) ($today['daypower'] ?? ''),
            'forecast' => is_array($casts) ? array_values($casts) : [],
        ];

        $this->setCache($cacheKey, $data);
        return $data;
    }

    /**
     * Fetch HeFeng (QWeather) "now" observation for a location id.
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException On missing key/location (code 42201)
     * @throws RuntimeException          On upstream/transport failure (code 42201)
     */
    public function getHeFengWeather(string $apiKey, string $locationId): array
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('和风 API Key 未配置', 42201);
        }
        if ($locationId === '') {
            throw new \InvalidArgumentException('location 参数缺失', 42201);
        }

        $cacheKey = 'hefeng:weather:' . $locationId;
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://devapi.qweather.com/v7/weather/now'
            . '?location=' . rawurlencode($locationId)
            . '&key=' . rawurlencode($apiKey);

        $resp = $this->httpGet($url);

        $code = (string) ($resp['code'] ?? '');
        if ($code !== '200') {
            throw new RuntimeException('和风天气 API 调用失败 code=' . $code, 42201);
        }

        $now = is_array($resp['now'] ?? null) ? $resp['now'] : [];

        $data = [
            'source' => 'hefeng',
            'city' => '',
            'province' => '',
            'adcode' => $locationId,
            'report_time' => (string) ($now['obsTime'] ?? ''),
            'temp' => (string) ($now['temp'] ?? ''),
            'condition' => (string) ($now['text'] ?? ''),
            'humidity' => (string) ($now['humidity'] ?? ''),
            'wind_direction' => (string) ($now['windDir'] ?? ''),
            'wind_scale' => (string) ($now['windScale'] ?? ''),
            'wind_speed' => (string) ($now['windSpeed'] ?? ''),
            'feels_like' => (string) ($now['feelsLike'] ?? ''),
            'pressure' => (string) ($now['pressure'] ?? ''),
            'visibility' => (string) ($now['vis'] ?? ''),
            'precip' => (string) ($now['precip'] ?? ''),
        ];

        $this->setCache($cacheKey, $data);
        return $data;
    }

    /**
     * Reverse-geocode lat/lng to adcode/city/province via Gaode regeo.
     *
     * @return array{adcode:string,city:string,province:string}
     *
     * @throws \InvalidArgumentException On missing key (code 42201)
     * @throws RuntimeException          On upstream/transport failure (code 42201)
     */
    public function reverseGeocode(string $apiKey, float $lat, float $lng): array
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('高德 API Key 未配置', 42201);
        }

        $cacheKey = 'gaode:regeo:' . sprintf('%.4f', $lat) . ',' . sprintf('%.4f', $lng);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://restapi.amap.com/v3/geocode/regeo'
            . '?location=' . rawurlencode(sprintf('%.6f', $lng) . ',' . sprintf('%.6f', $lat))
            . '&key=' . rawurlencode($apiKey);

        $resp = $this->httpGet($url);

        $status = (string) ($resp['status'] ?? '');
        if ($status !== '1') {
            $info = (string) ($resp['info'] ?? '上游错误');
            throw new RuntimeException('高德逆地理 API 调用失败: ' . $info, 42201);
        }

        $regeo = is_array($resp['regeocode'] ?? null) ? $resp['regeocode'] : [];
        $ac = is_array($regeo['addressComponent'] ?? null) ? $regeo['addressComponent'] : [];

        $adcode = $this->scalarString($ac['adcode'] ?? '');
        $city = $this->scalarString($ac['city'] ?? '');
        $province = $this->scalarString($ac['province'] ?? '');

        // 直辖市 / 省直辖县: 高德返回 city 为空数组,用 province 兜底。
        if ($city === '') {
            $city = $province;
        }

        $data = [
            'adcode' => $adcode,
            'city' => $city,
            'province' => $province,
        ];

        $this->setCache($cacheKey, $data);
        return $data;
    }

    /**
     * Search cities by keyword via Gaode geocoding.
     *
     * Returns a de-duplicated list of {adcode, name, province}. The
     * Gaode geocode endpoint occasionally returns an empty array for
     * `city` (municipalities); in that case the province is used as
     * the display name.
     *
     * @return array<int,array{adcode:string,name:string,province:string}>
     *
     * @throws \InvalidArgumentException On missing key (code 42201)
     * @throws RuntimeException          On upstream/transport failure (code 42201)
     */
    public function searchCities(string $apiKey, string $keyword): array
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('高德 API Key 未配置', 42201);
        }
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }

        $cacheKey = 'gaode:cities:' . md5($kw);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = 'https://restapi.amap.com/v3/geocode/geo'
            . '?address=' . rawurlencode($kw)
            . '&key=' . rawurlencode($apiKey);

        $resp = $this->httpGet($url);

        $status = (string) ($resp['status'] ?? '');
        if ($status !== '1') {
            $info = (string) ($resp['info'] ?? '上游错误');
            throw new RuntimeException('高德地理编码 API 调用失败: ' . $info, 42201);
        }

        $geocodes = $resp['geocodes'] ?? [];
        if (!is_array($geocodes)) {
            $geocodes = [];
        }

        $out = [];
        $seen = [];
        foreach ($geocodes as $g) {
            if (!is_array($g)) {
                continue;
            }
            $adcode = $this->scalarString($g['adcode'] ?? '');
            if ($adcode === '') {
                continue;
            }
            if (isset($seen[$adcode])) {
                continue;
            }
            $seen[$adcode] = true;

            $province = $this->scalarString($g['province'] ?? '');
            $city = $this->scalarString($g['city'] ?? '');
            $district = $this->scalarString($g['district'] ?? '');
            $formatted = $this->scalarString($g['formatted_address'] ?? '');

            $name = $city;
            if ($name === '') {
                $name = $province;
            }
            if ($name === '') {
                $name = $district !== '' ? $district : $formatted;
            }

            $out[] = [
                'adcode' => $adcode,
                'name' => $name,
                'province' => $province,
            ];
        }

        $this->setCache($cacheKey, $out);
        return $out;
    }

    // ------------------------------------------------------------------
    // HTTP + cache plumbing
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     *
     * @throws RuntimeException When the response is not valid JSON.
     */
    private function httpGet(string $url): array
    {
        $body = $this->httpGetRaw($url);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('天气 API 返回数据格式错误', 42201);
        }
        return $decoded;
    }

    /**
     * @throws RuntimeException On transport failure.
     */
    private function httpGetRaw(string $url): string
    {
        // Prefer cURL when available — better timeout / error handling.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                ]);
                $body = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                if ($body === false) {
                    throw new RuntimeException('外部 API 请求失败: ' . $err, 42201);
                }
                return (string) $body;
            }
        }

        // Fallback: stream context + file_get_contents.
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::HTTP_TIMEOUT,
                'user_agent' => self::USER_AGENT,
            ],
            'ssl' => ['verify_peer' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('外部 API 请求失败', 42201);
        }
        return (string) $body;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getCache(string $key): ?array
    {
        $store = $this->loadCacheStore();
        if (!isset($store[$key]) || !is_array($store[$key])) {
            return null;
        }
        $entry = $store[$key];
        $cachedAt = (int) ($entry['cached_at'] ?? 0);
        $data = $entry['data'] ?? null;
        if ($cachedAt <= 0 || !is_array($data)) {
            return null;
        }
        if ((time() - $cachedAt) > self::CACHE_TTL) {
            return null;
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function setCache(string $key, array $data): void
    {
        $store = $this->loadCacheStore();
        $store[$key] = [
            'data' => $data,
            'cached_at' => time(),
        ];
        $this->saveCacheStore($store);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadCacheStore(): array
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT value FROM system_settings WHERE key = :key');
            $stmt->execute([':key' => self::SETTING_CACHE_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !isset($row['value'])) {
                return [];
            }
            $decoded = json_decode((string) $row['value'], true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $store
     */
    private function saveCacheStore(array $store): void
    {
        try {
            $pdo = Database::connection();
            // ON CONFLICT ... DO UPDATE works on both PostgreSQL and
            // SQLite (>= 3.24, required by the dev-mode migration set).
            $stmt = $pdo->prepare(
                'INSERT INTO system_settings (key, value) VALUES (:key, :value) '
                . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
            );
            $stmt->execute([
                ':key' => self::SETTING_CACHE_KEY,
                ':value' => json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // 缓存写入失败不影响主流程,只是后续请求会重新拉取上游数据。
        }
    }

    /**
     * Normalise Gaode scalar/array fields. Gaode occasionally returns
     * empty arrays (e.g. city for municipalities) instead of strings.
     */
    private function scalarString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            return '';
        }
        return $v === null ? '' : (string) $v;
    }
}
