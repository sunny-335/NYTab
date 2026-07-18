<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;
use PDO;

/**
 * Data access for the bookmarks table.
 *
 * Every read/write is scoped by user_id — find/update/delete always carry
 * the user_id predicate so a row owned by another user can never leak.
 * The `extra` JSONB column stores tags/color/note/open_in_new_tab; on
 * create we merge caller-provided extra over the spec defaults, on update
 * we read-modify-write so partial extra patches preserve old keys.
 */
final class BookmarkRepository
{
    private PDO $pdo;

    /** @var array<string,mixed> */
    private const DEFAULT_EXTRA = [
        'tags' => [],
        'color' => null,
        'note' => '',
        'open_in_new_tab' => true,
    ];

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * List bookmarks for $userId, optionally filtered by category and
     * matched against $keyword across title/url/description (case-
     * insensitive ILIKE). Ordered by sort_order then id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(int $userId, ?int $categoryId, string $keyword): array
    {
        $sql = 'SELECT id, user_id, category_id, title, url, description, '
            . 'icon_url, sort_order, extra, created_at, updated_at '
            . 'FROM bookmarks WHERE user_id = :uid';
        $params = [':uid' => $userId];

        if ($categoryId !== null) {
            $sql .= ' AND category_id = :cid';
            $params[':cid'] = $categoryId;
        }

        if ($keyword !== '') {
            $sql .= ' AND (title ILIKE :kw OR url ILIKE :kw OR description ILIKE :kw)';
            $params[':kw'] = '%' . $keyword . '%';
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->format((array) $row);
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, category_id, title, url, description, '
            . 'icon_url, sort_order, extra, created_at, updated_at '
            . 'FROM bookmarks WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->format((array) $row) : null;
    }

    /**
     * Insert a bookmark. $data keys recognised: title, url, category_id,
     * description, icon_url, sort_order, extra (array). Missing optional
     * keys fall back to DB defaults. Returns the new row id.
     *
     * @param array<string,mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        $extra = $this->normalizeExtra($data['extra'] ?? null, null);

        $stmt = $this->pdo->prepare(
            'INSERT INTO bookmarks '
            . '(user_id, category_id, title, url, description, icon_url, sort_order, extra) '
            . 'VALUES (:uid, :cid, :title, :url, :desc, :icon, :sort, :extra) RETURNING id'
        );

        $stmt->execute([
            ':uid' => $userId,
            ':cid' => isset($data['category_id']) && $data['category_id'] !== null
                ? (int) $data['category_id'] : null,
            ':title' => (string) ($data['title'] ?? ''),
            ':url' => (string) ($data['url'] ?? ''),
            ':desc' => isset($data['description']) && $data['description'] !== null
                ? (string) $data['description'] : null,
            ':icon' => isset($data['icon_url']) && $data['icon_url'] !== null
                ? (string) $data['icon_url'] : null,
            ':sort' => (int) ($data['sort_order'] ?? 0),
            ':extra' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $id = $stmt->fetchColumn();
        return (int) $id;
    }

    /**
     * Update a bookmark scoped by user_id. Only the keys present in $data
     * are written; `extra` is merged with the existing JSONB so partial
     * patches preserve keys not supplied. Returns true on affected row.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id, ':uid' => $userId];

        if (array_key_exists('category_id', $data)) {
            $fields[] = 'category_id = :cid';
            $params[':cid'] = $data['category_id'] !== null
                ? (int) $data['category_id'] : null;
        }
        if (array_key_exists('title', $data)) {
            $fields[] = 'title = :title';
            $params[':title'] = (string) $data['title'];
        }
        if (array_key_exists('url', $data)) {
            $fields[] = 'url = :url';
            $params[':url'] = (string) $data['url'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = :desc';
            $params[':desc'] = $data['description'] !== null
                ? (string) $data['description'] : null;
        }
        if (array_key_exists('icon_url', $data)) {
            $fields[] = 'icon_url = :icon';
            $params[':icon'] = $data['icon_url'] !== null
                ? (string) $data['icon_url'] : null;
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort';
            $params[':sort'] = (int) $data['sort_order'];
        }
        if (array_key_exists('extra', $data)) {
            $current = $this->find($id, $userId);
            $existingExtra = $current !== null
                ? (array) ($current['extra'] ?? [])
                : self::DEFAULT_EXTRA;
            $merged = $this->normalizeExtra($data['extra'], $existingExtra);
            $fields[] = 'extra = :extra';
            $params[':extra'] = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (empty($fields)) {
            return $this->exists($id, $userId);
        }

        $sql = 'UPDATE bookmarks SET ' . implode(', ', $fields)
            . ' WHERE id = :id AND user_id = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM bookmarks WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function exists(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM bookmarks WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Batch-update sort_order for a list of [{id, sort_order}, ...].
     * Each update is scoped by user_id so a caller can only reorder their
     * own bookmarks. Runs inside a transaction so a partial failure rolls
     * the whole batch back.
     *
     * @param array<int,array{id:int,sort_order:int}> $items
     */
    public function reorder(int $userId, array $items): void
    {
        if (empty($items)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE bookmarks SET sort_order = :sort '
            . 'WHERE id = :id AND user_id = :uid'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                $sort = (int) ($item['sort_order'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $stmt->execute([':sort' => $sort, ':id' => $id, ':uid' => $userId]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateIcon(int $id, int $userId, string $iconUrl): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bookmarks SET icon_url = :icon WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':icon' => $iconUrl, ':id' => $id, ':uid' => $userId]);
    }

    /**
     * Merge caller-provided extra with defaults (and optionally an
     * existing row's extra). Ensures the four canonical keys are always
     * present and well-typed.
     *
     * @param mixed $input
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>
     */
    private function normalizeExtra(mixed $input, ?array $existing): array
    {
        $base = $existing ?? self::DEFAULT_EXTRA;
        if (!is_array($input)) {
            return $base;
        }
        $merged = array_merge($base, $input);
        // Coerce canonical keys to the spec types.
        $merged['tags'] = isset($merged['tags']) && is_array($merged['tags'])
            ? array_values($merged['tags']) : ($base['tags'] ?? []);
        $merged['color'] = $merged['color'] ?? null;
        $merged['note'] = isset($merged['note']) && is_string($merged['note'])
            ? $merged['note'] : (string) ($base['note'] ?? '');
        $merged['open_in_new_tab'] = isset($merged['open_in_new_tab'])
            ? (bool) $merged['open_in_new_tab']
            : (bool) ($base['open_in_new_tab'] ?? true);
        return $merged;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function format(array $row): array
    {
        $extra = $row['extra'] ?? null;
        $decoded = is_string($extra) ? json_decode($extra, true) : $extra;
        if (!is_array($decoded)) {
            $decoded = self::DEFAULT_EXTRA;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'category_id' => isset($row['category_id']) && $row['category_id'] !== null
                ? (int) $row['category_id'] : null,
            'title' => (string) $row['title'],
            'url' => (string) $row['url'],
            'description' => $row['description'] ?? null,
            'icon_url' => $row['icon_url'] ?? null,
            'sort_order' => (int) $row['sort_order'],
            'extra' => $decoded,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
