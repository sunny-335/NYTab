<?php
declare(strict_types=1);

namespace Nytab\Core;

use PDO;
use PDOException;

/**
 * PDO singleton wrapper.
 *
 * Defaults to configuration sourced from Env, but supports a one-off
 * override array (used by setup/test-database before the real config is
 * persisted).
 *
 * When APP_DEV_MODE is enabled (true/1/on), the connection is forced onto
 * a local SQLite file at backend/storage/nytab_dev.sqlite — regardless of
 * any PostgreSQL configuration in .env. This powers the developer-mode
 * infrastructure (see Nytab\Services\DeveloperModeService).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(?array $override = null): PDO
    {
        if ($override !== null) {
            return self::build($override);
        }

        if (self::$pdo === null) {
            if (self::isDevMode()) {
                self::$pdo = self::buildSqlite();
            } else {
                self::$pdo = self::build([
                    'host' => (string) (Env::get('DB_HOST', '127.0.0.1')),
                    'port' => (string) (Env::get('DB_PORT', '5432')),
                    'dbname' => (string) (Env::get('DB_NAME', 'nytab')),
                    'user' => (string) (Env::get('DB_USER', 'nytab')),
                    'password' => (string) (Env::get('DB_PASSWORD', '')),
                ]);
            }
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Whether developer mode is enabled via the APP_DEV_MODE env var.
     *
     * Values "true", "1", "on" (case-insensitive) are treated as enabled;
     * any other value (including absent) is disabled.
     */
    public static function isDevMode(): bool
    {
        $value = Env::get('APP_DEV_MODE');
        if ($value === null) {
            return false;
        }
        $lower = strtolower((string) $value);
        return in_array($lower, ['true', '1', 'on'], true);
    }

    /**
     * Build a PostgreSQL PDO connection from a config array.
     *
     * @param array<string,string> $cfg
     */
    private static function build(array $cfg): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? '5432',
            $cfg['dbname'] ?? 'nytab'
        );

        try {
            $pdo = new PDO(
                $dsn,
                $cfg['user'] ?? '',
                $cfg['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return $pdo;
    }

    /**
     * Build the SQLite PDO connection used by developer mode.
     *
     * The DB file lives at backend/storage/nytab_dev.sqlite; the storage
     * directory is created on demand. PRAGMA foreign_keys=ON enforces the
     * ON DELETE CASCADE / SET NULL behaviour declared by the migrations.
     */
    private static function buildSqlite(): PDO
    {
        $storageDir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }
        $path = $storageDir . '/nytab_dev.sqlite';
        $dsn = 'sqlite:' . $path;

        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('SQLite connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $pdo->exec('PRAGMA foreign_keys = ON;');

        return $pdo;
    }
}
