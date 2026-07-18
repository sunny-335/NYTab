<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Database;
use Nytab\Core\Env;
use Nytab\Utils\Hasher;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Developer-mode infrastructure.
 *
 * When enabled (APP_DEV_MODE=true in .env), the backend is forced onto a
 * local SQLite database (backend/storage/nytab_dev.sqlite) and the install
 * guard is bypassed — letting contributors spin up a working backend
 * without provisioning PostgreSQL or running the setup wizard.
 *
 * On enable() the service:
 *   1. flips APP_DEV_MODE to true in .env (creating the file if absent)
 *   2. reloads the Env cache so Database::connection() picks it up
 *   3. runs the SQLite migrations (skipped if already initialized)
 *   4. seeds an admin/admin account (skipped if a user named admin exists)
 *
 * Default admin credentials: username `admin`, password `admin`
 * (bcrypt cost=12 via Nytab\Utils\Hasher).
 */
final class DeveloperModeService
{
    private string $backendRoot;

    private string $envFile;

    private string $sqliteMigrationsDir;

    public function __construct()
    {
        $this->backendRoot = dirname(__DIR__, 2);
        $this->envFile = $this->backendRoot . '/.env';
        $this->sqliteMigrationsDir = $this->backendRoot . '/migrations/sqlite';
    }

    /**
     * Whether developer mode is currently enabled. Values "true", "1",
     * "on" (case-insensitive) of the APP_DEV_MODE env var are treated
     * as enabled; anything else (including absent) is disabled.
     */
    public function isEnabled(): bool
    {
        return Database::isDevMode();
    }

    /**
     * Flip APP_DEV_MODE to true in .env. Creates the file if it does not
     * exist; updates the existing line if it does. Other entries are
     * preserved verbatim. Reloads the Env cache and resets the cached
     * PDO so subsequent Database::connection() calls use SQLite.
     */
    public function enable(): void
    {
        $this->writeEnvFlag('true');
        Env::reload();
        Database::reset();
    }

    /**
     * Flip APP_DEV_MODE to false in .env. Creates the file if it does
     * not exist; updates the existing line if it does. Reloads the Env
     * cache and resets the cached PDO so subsequent Database::connection()
     * calls fall back to PostgreSQL.
     */
    public function disable(): void
    {
        $this->writeEnvFlag('false');
        Env::reload();
        Database::reset();
    }

    /**
     * Run the SQLite migrations and seed the default admin/admin account.
     * Safe to call repeatedly — already-initialized schema/admin are
     * skipped. Must be called after enable() so Database::connection()
     * resolves to the SQLite PDO.
     */
    public function initSqlite(): void
    {
        $pdo = Database::connection();

        $this->runMigrations($pdo);
        $this->seedAdmin($pdo);
    }

    /**
     * Write APP_DEV_MODE=<value> to .env, preserving all other lines.
     *
     * @param string $value "true" or "false"
     */
    private function writeEnvFlag(string $value): void
    {
        $lines = [];
        $found = false;

        if (is_file($this->envFile)) {
            $raw = file($this->envFile, FILE_IGNORE_NEW_LINES);
            if ($raw !== false) {
                foreach ($raw as $line) {
                    $trimmed = ltrim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        $lines[] = $line;
                        continue;
                    }
                    if (!str_contains($line, '=')) {
                        $lines[] = $line;
                        continue;
                    }
                    [$key] = explode('=', $line, 2);
                    if (trim($key) === 'APP_DEV_MODE') {
                        $lines[] = 'APP_DEV_MODE=' . $value;
                        $found = true;
                        continue;
                    }
                    $lines[] = $line;
                }
            }
        }

        if (!$found) {
            $lines[] = 'APP_DEV_MODE=' . $value;
        }

        file_put_contents($this->envFile, implode("\n", $lines) . "\n");
        @chmod($this->envFile, 0640);
    }

    /**
     * Execute every *.sql file under backend/migrations/sqlite/ in
     * alphabetical (= numeric prefix) order. Migrations use IF NOT EXISTS
     * / DROP IF EXISTS, so a re-run after a partial failure is safe.
     */
    private function runMigrations(PDO $pdo): void
    {
        $files = glob($this->sqliteMigrationsDir . '/*.sql');
        if ($files === false) {
            return;
        }
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            // SQLite PDO accepts multi-statement scripts in a single exec call.
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Seed the default admin/admin account if it does not yet exist.
     * Password is hashed with bcrypt cost=12 via Nytab\Utils\Hasher.
     */
    private function seedAdmin(PDO $pdo): void
    {
        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $check->execute([':username' => 'admin']);
        $count = (int) $check->fetchColumn();
        if ($count > 0) {
            return;
        }

        $hash = Hasher::hash('admin');
        if ($hash === false || $hash === '') {
            throw new RuntimeException('Failed to hash default admin password');
        }
        $prefs = json_encode(['theme' => 'light', 'lang' => 'zh-CN']);
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, display_name, preferences) '
            . 'VALUES (:username, :hash, :display_name, :prefs)'
        );
        $insert->execute([
            ':username' => 'admin',
            ':hash' => $hash,
            ':display_name' => 'admin',
            ':prefs' => $prefs,
        ]);
    }
}
