<?php
declare(strict_types=1);

namespace Nytab\Core;

/**
 * Minimal env loader.
 *
 * Parses a .env file of KEY=VALUE lines into a static cache and provides
 * typed access via Env::get(). The file is loaded lazily on first access.
 */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    private static ?string $path = null;

    public static function setPath(string $path): void
    {
        self::$path = $path;
        self::$cache = null;
    }

    /**
     * Force a fresh re-read of the .env file. Used by the setup flow after
     * the installer writes a new .env so that subsequent Env::get() calls
     * see the updated configuration without a new process.
     */
    public static function reload(): void
    {
        self::$cache = null;
        self::load();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::load();
        }
        if (!array_key_exists($key, self::$cache)) {
            return $default;
        }
        return self::$cache[$key];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::load();
        }
        return self::$cache;
    }

    private static function load(): void
    {
        $path = self::$path ?? self::defaultPath();
        $values = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = ltrim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (!str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    $value = self::stripQuotes($value);
                    if ($key !== '') {
                        $values[$key] = $value;
                    }
                }
            }
        }

        // Environment variables set in the OS take precedence over .env file.
        foreach ($values as $key => $value) {
            $env = getenv($key);
            if ($env !== false) {
                $values[$key] = $env;
            }
        }

        self::$cache = $values;
    }

    private static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }
}
