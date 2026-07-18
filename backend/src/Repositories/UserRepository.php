<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;
use PDO;

/**
 * Data access for the users table.
 *
 * All writes are parameterised; password_hash is always the bcrypt digest
 * produced by Nytab\Utils\Hasher — the repository never sees plaintext.
 */
final class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $stmt->execute([':h' => $hash, ':id' => $id]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateProfile(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['username'])) {
            $fields[] = 'username = :username';
            $params[':username'] = $data['username'];
        }
        if (array_key_exists('email', $data)) {
            $fields[] = 'email = :email';
            $params[':email'] = $data['email'];
        }
        if (isset($data['display_name'])) {
            $fields[] = 'display_name = :display_name';
            $params[':display_name'] = $data['display_name'];
        }
        if (isset($data['avatar_url'])) {
            $fields[] = 'avatar_url = :avatar_url';
            $params[':avatar_url'] = $data['avatar_url'];
        }
        if (isset($data['preferences'])) {
            $fields[] = 'preferences = :prefs';
            $params[':prefs'] = json_encode($data['preferences']);
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updateLastLogin(int $id, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = :ip, '
            . 'failed_attempts = 0 WHERE id = :id'
        );
        $stmt->execute([':ip' => $ip, ':id' => $id]);
    }

    public function incrementFailedAttempts(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * Lock the account for N minutes. Uses make_interval to bind the
     * duration as a parameter (avoiding INTERVAL string interpolation).
     */
    public function lock(int $id, int $minutes): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET locked_until = NOW() + make_interval(mins => :min) WHERE id = :id'
        );
        $stmt->execute([':min' => $minutes, ':id' => $id]);
    }
}
