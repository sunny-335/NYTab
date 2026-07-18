<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Branding domain service.
 *
 * Reads/writes the `branding` key (JSONB) of system_settings. The
 * `copyright` field is intentionally NOT persisted — it is hardcoded
 * here and unconditionally re-attached on every read so that no API,
 * database edit, or migration can alter it.
 *
 * Validation errors throw \InvalidArgumentException (code 42201);
 * server-side failures throw RuntimeException (code 50001).
 */
final class BrandingService
{
    /**
     * Hardcoded copyright string shown on the about page. This value is
     * deliberately not stored in or read from the database, and no API
     * exists to modify it.
     */
    public const COPYRIGHT = '© 暖心向阳335';

    private const SETTING_KEY = 'branding';

    private const DEFAULTS = [
        'nickname' => 'NYTab',
        'title' => 'NYTab',
        'logo' => '/logo.jpg',
    ];

    private const NICKNAME_MAX = 32;

    private const TITLE_MAX = 64;

    private const LOGO_MAX = 512;

    /**
     * Return the current branding payload. Always includes the
     * hardcoded `copyright` field. Falls back to defaults when the
     * system is not yet installed or the DB is unreachable, so the
     * install wizard and login page can call this endpoint safely.
     *
     * @return array{nickname:string,title:string,logo:string,copyright:string}
     */
    public function get(): array
    {
        $stored = $this->loadStored();

        $branding = array_merge(self::DEFAULTS, $stored);
        $branding['nickname'] = $this->clampString($branding['nickname'], self::DEFAULTS['nickname']);
        $branding['title'] = $this->clampString($branding['title'], self::DEFAULTS['title']);
        $branding['logo'] = $this->clampString($branding['logo'], self::DEFAULTS['logo']);
        $branding['copyright'] = self::COPYRIGHT;

        return $branding;
    }

    /**
     * Validate and persist the supplied branding payload. The
     * `copyright` field is silently ignored — callers cannot change it.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{nickname:string,title:string,logo:string,copyright:string}
     */
    public function update(array $payload): array
    {
        $current = $this->get();

        $nickname = array_key_exists('nickname', $payload)
            ? trim((string) $payload['nickname'])
            : $current['nickname'];
        $title = array_key_exists('title', $payload)
            ? trim((string) $payload['title'])
            : $current['title'];
        $logo = array_key_exists('logo', $payload)
            ? trim((string) $payload['logo'])
            : $current['logo'];

        if ($nickname === '' || mb_strlen($nickname) > self::NICKNAME_MAX) {
            throw new \InvalidArgumentException(
                'nickname 长度需为 1-' . self::NICKNAME_MAX . ' 字符',
                42201
            );
        }
        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw new \InvalidArgumentException(
                'title 长度需为 1-' . self::TITLE_MAX . ' 字符',
                42201
            );
        }
        if ($logo === '' || strlen($logo) > self::LOGO_MAX) {
            throw new \InvalidArgumentException(
                'logo 无效或过长(≤' . self::LOGO_MAX . ' 字符)',
                42201
            );
        }

        $value = [
            'nickname' => $nickname,
            'title' => $title,
            'logo' => $logo,
        ];

        $this->upsert($value);

        $value['copyright'] = self::COPYRIGHT;

        return $value;
    }

    /**
     * Update only the logo field (used by the upload endpoint). Returns
     * the full branding payload after the update.
     *
     * @return array{nickname:string,title:string,logo:string,copyright:string}
     */
    public function updateLogo(string $logoPath): array
    {
        return $this->update(['logo' => $logoPath]);
    }

    /**
     * @return array<string,string>
     */
    private function loadStored(): array
    {
        // The install wizard calls GET /branding before the DB exists;
        // fall back to defaults on any connection/query failure.
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
            foreach (['nickname', 'title', 'logo'] as $field) {
                if (array_key_exists($field, $decoded) && is_string($decoded[$field])) {
                    $out[$field] = $decoded[$field];
                }
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,string> $value
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
            throw new RuntimeException('branding 保存失败', 50001);
        }
    }

    private function clampString(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
