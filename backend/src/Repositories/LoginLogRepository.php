<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;
use PDO;

/**
 * Data access for the login_logs table (brute-force audit trail).
 *
 * Rows are append-only: AuthService records every login attempt with
 * success=true/false; RateLimitMiddleware + AuthService consult the
 * recent-failure count to enforce the 5/5min → 15min lockout policy.
 */
final class LoginLogRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function record(string $username, string $ip, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_logs (username, ip, success) VALUES (:u, :ip, :s)'
        );
        $stmt->execute([':u' => $username, ':ip' => $ip, ':s' => $success]);
    }

    /**
     * Count failed attempts from $ip within the last $minutes minutes.
     */
    public function countRecentFailures(string $ip, int $minutes = 5): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_logs '
            . 'WHERE ip = :ip AND success = false '
            . 'AND created_at >= NOW() - make_interval(mins => :min)'
        );
        $stmt->execute([':ip' => $ip, ':min' => $minutes]);
        return (int) $stmt->fetchColumn();
    }
}
