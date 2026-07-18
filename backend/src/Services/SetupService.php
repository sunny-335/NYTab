<?php
declare(strict_types=1);

namespace Nytab\Services;

use Nytab\Core\Database;
use Nytab\Core\Env;
use Nytab\Utils\Hasher;
use Nytab\Utils\Validator;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * First-run installer.
 *
 * Orchestrates the setup wizard end-to-end:
 *  1. write .env (DB credentials + generated JWT secret)
 *  2. reload Env so the rest of the process sees the new config
 *  3. execute all migrations/*.sql against the freshly configured DB
 *  4. create the initial admin user with a bcrypt-hashed password
 *  5. insert system_settings('installed', {version, installed_at})
 *  6. write config/installed.lock — flips the system into "installed" mode
 *
 * No third-party dependencies; uses PDO + pgsql directly.
 */
final class SetupService
{
    private string $backendRoot;

    private string $migrationsDir;

    private string $envFile;

    private string $installedLock;

    public function __construct()
    {
        $this->backendRoot = dirname(__DIR__, 2);
        $this->migrationsDir = $this->backendRoot . '/migrations';
        $this->envFile = $this->backendRoot . '/.env';
        $this->installedLock = $this->backendRoot . '/config/installed.lock';
    }

    public function isInstalled(): bool
    {
        return is_file($this->installedLock);
    }

    /**
     * Environment readiness checks for the setup wizard's first step.
     *
     * @return array<string, array<string,mixed>>
     */
    public function checkRequirements(): array
    {
        // Developer mode runs on SQLite; otherwise the production PostgreSQL
        // extension is required. The key name reflects the active driver so
        // the setup wizard can render the correct label.
        $devMode = Database::isDevMode();
        $pdoKey = $devMode ? 'pdo_sqlite' : 'pdo_pgsql';
        $pdoExt = $devMode ? 'pdo_sqlite' : 'pdo_pgsql';

        return [
            'php_version' => [
                'required' => '8.1.0',
                'actual' => PHP_VERSION,
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            ],
            $pdoKey => ['ok' => extension_loaded($pdoExt)],
            'json' => ['ok' => extension_loaded('json')],
            'writable_config' => [
                'ok' => is_writable($this->backendRoot . '/config'),
                'path' => 'backend/config',
            ],
            'writable_uploads' => [
                'ok' => is_writable($this->backendRoot . '/uploads'),
                'path' => 'backend/uploads',
            ],
        ];
    }

    /**
     * Probe a database connection without persisting any configuration.
     *
     * Connects to the PostgreSQL maintenance database ("postgres") rather
     * than the target database, then consults pg_database to determine
     * whether the target database already exists. Returns a structured
     * array so the wizard can show "database will be auto-created" before
     * the user commits to the install.
     *
     * @param array<string,string|int> $config
     *
     * @return array{databaseExists: bool, canCreate: bool, server_version: string}
     */
    public function testDatabaseConnection(array $config): array
    {
        $pdo = $this->connectMaintenancePdo($config);
        $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        $dbname = (string) $config['name'];
        $existsStmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $existsStmt->execute([':dbname' => $dbname]);
        $databaseExists = $existsStmt->fetchColumn() !== false;

        // When the target DB does not exist yet, surface whether the
        // connecting role has CREATEDB so the wizard can warn the user
        // up-front rather than failing mid-install.
        $canCreate = false;
        if (!$databaseExists) {
            $permStmt = $pdo->prepare(
                'SELECT rolcreatedb FROM pg_roles WHERE rolname = current_user'
            );
            $permStmt->execute();
            $perm = $permStmt->fetchColumn();
            $canCreate = $perm !== false && (bool) $perm;
        }

        return [
            'databaseExists' => $databaseExists,
            'canCreate' => $canCreate,
            'server_version' => $version,
        ];
    }

    /**
     * Execute the full installation sequence.
     *
     * @param array<string,string|int> $databaseConfig
     * @param array<string,string>     $adminConfig
     * @param string|null              $corsOrigins  Optional CORS whitelist written to .env;
     *                                               falls back to the request Origin header, then '*'.
     */
    public function install(array $databaseConfig, array $adminConfig, ?string $corsOrigins = null): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('系统已安装', 40901);
        }

        // Validate admin credentials up-front so we fail before touching disk.
        if (!Validator::isValidUsername($adminConfig['username'])) {
            throw new \InvalidArgumentException(
                '用户名格式无效(3-64 字母数字下划线)',
                42201
            );
        }
        if (!Validator::isStrongPassword($adminConfig['password'])) {
            throw new \InvalidArgumentException(
                '管理员密码强度不足(≥8 字符且包含大小写/数字/特殊字符中至少 3 类)',
                42201
            );
        }
        if (!empty($adminConfig['email']) && !Validator::isValidEmail($adminConfig['email'])) {
            throw new \InvalidArgumentException('邮箱格式无效', 42201);
        }

        // CREATE DATABASE does not support parameter binding, so whitelist
        // the dbname against a strict identifier pattern before interpolating
        // it into the DDL later in ensureDatabaseExists().
        $dbname = (string) $databaseConfig['name'];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dbname)) {
            throw new \InvalidArgumentException(
                '数据库名仅允许字母、数字、下划线,且首字符必须为字母或下划线',
                42201
            );
        }

        // Derive CORS_ORIGINS: explicit payload > Origin header > wildcard fallback.
        // The wildcard is a last-resort dev-friendly default; spec recommends a
        // concrete origin in production, so the install wizard always sends one.
        if ($corsOrigins === null || $corsOrigins === '') {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
            $corsOrigins = $origin !== '' ? $origin : '*';
        }

        // 1. Ensure the target database exists — connect to the maintenance
        //    "postgres" DB and CREATE DATABASE if missing. Must happen before
        //    writeEnvFile()/Env::reload() so Database::connection() below can
        //    open the new DB without "database does not exist" errors.
        $this->ensureDatabaseExists($databaseConfig);

        // 2. Persist .env with the user-provided DB credentials + a fresh JWT secret.
        $this->writeEnvFile($databaseConfig, $corsOrigins);

        // 3. Reload the Env cache so Database::connection() below picks up the
        //    newly written DB_* values within this same request. Also reset
        //    the cached PDO in case an earlier call (e.g. test-database) left
        //    a connection pinned to the maintenance DB.
        Env::reload();
        Database::reset();

        // 4. Run all migrations in alphabetical (= numeric prefix) order.
        $this->runMigrations();

        // 5. Create the initial admin user.
        $this->createAdmin($adminConfig);

        // 6. Record the install marker in system_settings.
        $this->markInstalled();

        // 7. Touch installed.lock — this is the authoritative "installed" flag
        //    consulted by SetupGuardMiddleware and isInstalled() above.
        file_put_contents($this->installedLock, '');
        @chmod($this->envFile, 0640);
    }

    /**
     * Open a PDO connection to the PostgreSQL maintenance database
     * ("postgres"), used to probe the catalog and CREATE DATABASE before
     * the target database exists.
     *
     * @param array<string,string|int> $config
     */
    private function connectMaintenancePdo(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=postgres',
            $config['host'],
            (int) $config['port']
        );
        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Ensure the target database exists. Connects to the maintenance "postgres"
     * DB, checks pg_database, and runs CREATE DATABASE if missing.
     *
     * The dbname is whitelist-validated in install() against
     * ^[a-zA-Z_][a-zA-Z0-9_]*$ before this method is reached, so it is safe
     * to interpolate into the DDL (CREATE DATABASE does not support binding).
     *
     * @param array<string,string|int> $config
     */
    private function ensureDatabaseExists(array $config): void
    {
        $pdo = $this->connectMaintenancePdo($config);
        $dbname = (string) $config['name'];

        $existsStmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $existsStmt->execute([':dbname' => $dbname]);
        if ($existsStmt->fetchColumn() !== false) {
            return;
        }

        // CREATE DATABASE cannot run inside a transaction block; PDO pgsql
        // does not auto-begin for DDL, but guard defensively just in case.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // dbname is whitelist-validated in install() — safe to interpolate.
        // Double-quote the identifier to preserve case and avoid reserved-word
        // collisions.
        $pdo->exec('CREATE DATABASE "' . $dbname . '"');
    }

    /**
     * @param array<string,string|int> $db
     */
    private function writeEnvFile(array $db, string $corsOrigins): void
    {
        $jwtSecret = bin2hex(random_bytes(32));
        $content = sprintf(
            "DB_HOST=%s\nDB_PORT=%d\nDB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\n"
            . "JWT_SECRET=%s\nJWT_ACCESS_TTL=3600\nJWT_REFRESH_TTL=604800\n"
            . "APP_ENV=production\nAPP_URL=\nCORS_ORIGINS=%s\nUPLOAD_MAX_SIZE=5242880\n",
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['user'],
            $db['password'],
            $jwtSecret,
            $corsOrigins
        );
        file_put_contents($this->envFile, $content);
        @chmod($this->envFile, 0640);
    }

    private function runMigrations(): void
    {
        $pdo = Database::connection();
        $files = glob($this->migrationsDir . '/*.sql');
        if ($files === false) {
            return;
        }
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }
            // PDO pgsql accepts multi-statement scripts in a single exec call;
            // $$ dollar-quoting inside the migration bodies is handled server-side.
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Migrations use IF NOT EXISTS / OR REPLACE / DROP IF EXISTS, so a
                // re-run after a partial failure is safe. Surface any other error.
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<string,string> $admin
     */
    private function createAdmin(array $admin): void
    {
        $pdo = Database::connection();
        $hash = Hasher::hash($admin['password']);
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, email, display_name, preferences) '
            . 'VALUES (:username, :hash, :email, :display_name, :prefs) RETURNING id'
        );
        $displayName = $admin['username'];
        $email = $admin['email'] ?? null;
        $prefs = json_encode(['theme' => 'light', 'lang' => 'zh-CN']);
        $stmt->execute([
            ':username' => $admin['username'],
            ':hash' => $hash,
            ':email' => $email,
            ':display_name' => $displayName,
            ':prefs' => $prefs,
        ]);
    }

    private function markInstalled(): void
    {
        $pdo = Database::connection();
        $version = '1.0.0';
        $installedAt = date('c');
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (key, value) VALUES (:key, :value) '
            . 'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $stmt->execute([
            ':key' => 'installed',
            ':value' => json_encode(['version' => $version, 'installed_at' => $installedAt]),
        ]);
    }

    /**
     * Best-effort retrieval of the installed version string. Returns null
     * when the system is not installed or the DB is not yet reachable.
     */
    public function getInstalledVersion(): ?string
    {
        if (!$this->isInstalled()) {
            return null;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'installed'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['value'])) {
                $data = json_decode((string) $row['value'], true);
                return $data['version'] ?? null;
            }
        } catch (Throwable $e) {
            // Database may not be ready (e.g. broken env). Fall through to null.
        }
        return null;
    }
}
