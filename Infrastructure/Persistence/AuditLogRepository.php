<?php

declare(strict_types=1);

namespace Plugins\Audit\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Audit\API\Contracts\AuditReaderContract;
use Plugins\Audit\API\Contracts\AuditSearchContract;
use Plugins\Audit\API\Search\AuditSearch;
use Plugins\Audit\Domain\Entities\AuditEntry;

/**
 * AuditLogRepository — read/query side of the `audit_log` trail.
 *
 * Access rule: DatabasePort ONLY — the request's connection, so on a
 * tenant-routed request this reads THAT tenant's trail (see Provider). Writes
 * go through {@see AuditTrail};
 * this is the read counterpart, leaning on the
 * (tenant_id|user_id|action, occurred_at) indexes.
 *
 * Listings are keyset-paginated by descending id (monotonic with occurred_at):
 * pass the last id seen as $beforeId for the next page. LIMIT is clamped and
 * inlined as an integer — it cannot be a bound parameter with emulated prepares
 * disabled — while all filter VALUES stay parameter-bound.
 */
final class AuditLogRepository implements AuditReaderContract, AuditSearchContract
{
    private const SELECT =
        'SELECT id, event_id, user_id, tenant_id, action, ip, meta, occurred_at FROM audit_log';

    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function recent(int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('', [], $limit, $beforeId);
    }

    public function forTenant(string $tenantId, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('tenant_id = :tenant_id', ['tenant_id' => $tenantId], $limit, $beforeId);
    }

    public function forUser(string $userId, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('user_id = :user_id', ['user_id' => $userId], $limit, $beforeId);
    }

    public function byAction(string $action, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('action = :action', ['action' => $action], $limit, $beforeId);
    }

    public function find(string $eventId): ?AuditEntry
    {
        try {
            $row = $this->db->queryOne(
                self::SELECT . ' WHERE event_id = :event_id LIMIT 1',
                ['event_id' => $eventId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load audit entry.', layer: 'repository.audit', previous: $e);
        }

        return $row === null ? null : AuditEntry::fromRow($row);
    }

    public function countForTenant(string $tenantId): int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT COUNT(*) AS c FROM audit_log WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count audit entries.', layer: 'repository.audit', previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        try {
            return $this->db->execute(
                'DELETE FROM audit_log WHERE occurred_at < :cutoff',
                ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to purge audit entries.', layer: 'repository.audit', previous: $e);
        }
    }

    // ── AuditSearchContract ─────────────────────────────────────────────────

    public function search(AuditSearch $filters, int $limit = 50, int $offset = 0): array
    {
        if ($filters->actions === []) {
            return [];
        }

        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->where($filters);

        // LIMIT/OFFSET are validated ints, inlined (see the class docblock);
        // `id` is the primary key, so the order is total and pages never overlap.
        $sql = self::SELECT . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        try {
            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to search audit entries.', layer: 'repository.audit', previous: $e);
        }

        return array_map(static fn (array $r): AuditEntry => AuditEntry::fromRow($r), $rows);
    }

    public function count(AuditSearch $filters): int
    {
        if ($filters->actions === []) {
            return 0;
        }

        [$where, $params] = $this->where($filters);

        try {
            $row = $this->db->queryOne('SELECT COUNT(*) AS c FROM audit_log' . $where, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count audit entries.', layer: 'repository.audit', previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    public function countByAction(AuditSearch $filters): array
    {
        if ($filters->actions === []) {
            return [];
        }

        [$where, $params] = $this->where($filters);

        try {
            $rows = $this->db->query(
                'SELECT action, COUNT(*) AS c FROM audit_log' . $where . ' GROUP BY action',
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count audit entries by action.', layer: 'repository.audit', previous: $e);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['action']] = (int) $r['c'];
        }

        return $out;
    }

    public function topActors(AuditSearch $filters, int $limit = 5): array
    {
        if ($filters->actions === []) {
            return [];
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$where, $params] = $this->where($filters);

        try {
            $rows = $this->db->query(
                'SELECT user_id, COUNT(*) AS c FROM audit_log' . $where
                . ' GROUP BY user_id ORDER BY c DESC, user_id ASC LIMIT ' . $limit,
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to rank audit actors.', layer: 'repository.audit', previous: $e);
        }

        return array_map(static fn (array $r): array => [
            'userId' => isset($r['user_id']) && $r['user_id'] !== '' ? (string) $r['user_id'] : null,
            'count'  => (int) $r['c'],
        ], $rows);
    }

    public function countActors(AuditSearch $filters): int
    {
        if ($filters->actions === []) {
            return 0;
        }

        [$where, $params] = $this->where($filters);

        try {
            $row = $this->db->queryOne(
                'SELECT COUNT(DISTINCT user_id) AS c FROM audit_log' . $where,
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count audit actors.', layer: 'repository.audit', previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    /**
     * The WHERE clause (with its leading space, or '') and bound parameters for
     * a search. Placeholders are generated; no caller value is interpolated.
     *
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function where(AuditSearch $f): array
    {
        $clauses = [];
        $params  = [];
        $n       = 0;
        $bind    = static function (mixed $value) use (&$params, &$n): string {
            $name          = 'p' . $n++;
            $params[$name] = $value;

            return ':' . $name;
        };
        $in = static function (array $values) use ($bind): string {
            return implode(', ', array_map($bind, array_values($values)));
        };

        if ($f->tenantId !== null) {
            $clauses[] = 'tenant_id = ' . $bind($f->tenantId);
        }
        if ($f->userId !== null) {
            $clauses[] = 'user_id = ' . $bind($f->userId);
        }
        if ($f->actions !== null) { // [] is short-circuited by every caller
            $clauses[] = 'action IN (' . $in($f->actions) . ')';
        }
        if ($f->since !== null) {
            $clauses[] = 'occurred_at >= ' . $bind($f->since->format('Y-m-d H:i:s'));
        }

        if ($f->metaAny !== []) {
            $any = [];
            foreach ($f->metaAny as [$key, $value]) {
                $any[] = 'JSON_UNQUOTE(JSON_EXTRACT(meta, ' . $bind('$.' . $key) . ')) = ' . $bind((string) $value);
            }
            $clauses[] = '(' . implode(' OR ', $any) . ')';
        }

        $text = $f->text !== null ? trim($f->text) : '';
        if ($text !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($text, 0, 100)) . '%';
            $any  = [];
            foreach (['action', 'ip', 'event_id', 'CAST(JSON_KEYS(meta) AS CHAR)'] as $column) {
                $any[] = $column . ' LIKE ' . $bind($like) . " ESCAPE '!'";
            }
            if ($f->textUserIds !== []) {
                $any[] = 'user_id IN (' . $in($f->textUserIds) . ')';
            }
            foreach ($f->textMetaValueKeys as $key) {
                $any[] = 'JSON_UNQUOTE(JSON_EXTRACT(meta, ' . $bind('$.' . $key) . ')) LIKE ' . $bind($like) . " ESCAPE '!'";
            }
            foreach ($f->textMetaIn as $key => $values) {
                if ($values !== []) {
                    $any[] = 'JSON_UNQUOTE(JSON_EXTRACT(meta, ' . $bind('$.' . $key) . ')) IN (' . $in($values) . ')';
                }
            }
            $clauses[] = '(' . implode(' OR ', $any) . ')';
        }

        return [$clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /**
     * Run a keyset-paginated listing with an optional WHERE filter.
     *
     * @param array<string, scalar> $params
     * @return list<AuditEntry>
     */
    private function page(string $where, array $params, int $limit, ?int $beforeId): array
    {
        $limit   = max(1, min(self::MAX_LIMIT, $limit));
        $clauses = $where !== '' ? [$where] : [];

        if ($beforeId !== null) {
            $clauses[] = 'id < ' . (int) $beforeId; // validated int — keyset cursor
        }

        $sql = self::SELECT
            . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
            . ' ORDER BY id DESC LIMIT ' . $limit;

        try {
            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list audit entries.', layer: 'repository.audit', previous: $e);
        }

        return array_map(static fn (array $r): AuditEntry => AuditEntry::fromRow($r), $rows);
    }
}
