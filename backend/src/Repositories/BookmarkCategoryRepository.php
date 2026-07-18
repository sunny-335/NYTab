<?php
declare(strict_types=1);

namespace Nytab\Repositories;

use Nytab\Core\Database;
use PDO;

/**
 * Data access for bookmark_categories — the self-referential tree
 * (parent_id → bookmark_categories.id). All writes are user_id scoped.
 *
 * The tree is materialised in PHP: one query pulls the user's entire
 * flat category set ordered by sort_order, then buildTree() nests
 * children under their parents. Cascading deletes of subtrees are
 * delegated to the DB's ON DELETE CASCADE on parent_id.
 */
final class BookmarkCategoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Return the full category tree for $userId. Top-level nodes
     * (parent_id IS NULL) appear at the array root; each node carries a
     * `children` array (possibly empty).
     *
     * @return array<int,array<string,mixed>>
     */
    public function tree(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, parent_id, name, icon, sort_order, extra, '
            . 'created_at, updated_at '
            . 'FROM bookmark_categories WHERE user_id = :uid '
            . 'ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<int|string,array<int,array<string,mixed>>> $byParent */
        $byParent = [];
        foreach ($rows as $row) {
            $node = $this->format((array) $row);
            $pid = $node['parent_id'];
            $key = $pid === null ? '__root__' : $pid;
            $byParent[$key][] = $node;
        }
        return $this->buildTree($byParent, null);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, parent_id, name, icon, sort_order, extra, '
            . 'created_at, updated_at '
            . 'FROM bookmark_categories WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->format((array) $row) : null;
    }

    public function create(int $userId, ?int $parentId, string $name, ?string $icon): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bookmark_categories (user_id, parent_id, name, icon) '
            . 'VALUES (:uid, :pid, :name, :icon) RETURNING id'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':pid' => $parentId !== null ? $parentId : null,
            ':name' => $name,
            ':icon' => $icon,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Update fields present in $data; recognised keys: parent_id, name,
     * icon, sort_order, extra. Returns true on affected row.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, int $userId, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id, ':uid' => $userId];

        if (array_key_exists('parent_id', $data)) {
            $fields[] = 'parent_id = :pid';
            $params[':pid'] = $data['parent_id'] !== null
                ? (int) $data['parent_id'] : null;
        }
        if (array_key_exists('name', $data)) {
            $fields[] = 'name = :name';
            $params[':name'] = (string) $data['name'];
        }
        if (array_key_exists('icon', $data)) {
            $fields[] = 'icon = :icon';
            $params[':icon'] = $data['icon'] !== null
                ? (string) $data['icon'] : null;
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort';
            $params[':sort'] = (int) $data['sort_order'];
        }
        if (array_key_exists('extra', $data)) {
            $fields[] = 'extra = :extra';
            $params[':extra'] = json_encode(
                is_array($data['extra']) ? $data['extra'] : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (empty($fields)) {
            return $this->exists($id, $userId);
        }

        $sql = 'UPDATE bookmark_categories SET ' . implode(', ', $fields)
            . ' WHERE id = :id AND user_id = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a category. Sub-categories are removed by the DB's
     * ON DELETE CASCADE on parent_id; bookmarks referencing this
     * category have their category_id SET NULL (also DB-side).
     */
    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM bookmark_categories WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function exists(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM bookmark_categories WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Batch-update sort_order for a list of [{id, sort_order}, ...].
     * User-scoped, transactional.
     *
     * @param array<int,array{id:int,sort_order:int}> $items
     */
    public function reorder(int $userId, array $items): void
    {
        if (empty($items)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE bookmark_categories SET sort_order = :sort '
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

    /**
     * Recursively build the tree under $parentId (null = root level).
     *
     * @param array<int|string,array<int,array<string,mixed>>> $byParent
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildTree(array $byParent, ?int $parentId): array
    {
        $key = $parentId === null ? '__root__' : $parentId;
        $nodes = $byParent[$key] ?? [];
        foreach ($nodes as &$node) {
            $node['children'] = $this->buildTree($byParent, (int) $node['id']);
        }
        unset($node);
        return $nodes;
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
            $decoded = [];
        }
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null
                ? (int) $row['parent_id'] : null,
            'name' => (string) $row['name'],
            'icon' => $row['icon'] ?? null,
            'sort_order' => (int) $row['sort_order'],
            'extra' => $decoded,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
