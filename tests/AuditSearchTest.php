<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Audit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Audit\API\Search\AuditSearch;
use Plugins\Audit\Infrastructure\Persistence\AuditLogRepository;

/**
 * The search SQL, against a recording double: no database. Asserts the shape
 * that matters for safety (bound values, escaped wildcards, stable order, no
 * query when nothing can match).
 */
#[CoversClass(AuditLogRepository::class)]
#[CoversClass(AuditSearch::class)]
final class AuditSearchTest extends TestCase
{
    private function db(array $rows = []): object
    {
        return new class ($rows) implements DatabasePort {
            /** @var list<array{0: string, 1: array}> */
            public array $calls = [];

            public function __construct(private array $rows) {}

            public function query(string $sql, array $params = []): array
            {
                $this->calls[] = [$sql, $params];

                return $this->rows;
            }

            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->calls[] = [$sql, $params];

                return $this->rows[0] ?? null;
            }

            public function execute(string $sql, array $params = []): int { return 0; }
            public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
            public function lastInsertId(?string $sequence = null): string { return '0'; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
        };
    }

    public function test_empty_search_pages_newest_first_with_inlined_ints(): void
    {
        $db = $this->db();
        (new AuditLogRepository($db))->search(new AuditSearch(), 10, 20);

        [$sql, $params] = $db->calls[0];
        $this->assertStringNotContainsString('WHERE', $sql);
        $this->assertStringEndsWith('ORDER BY id DESC LIMIT 10 OFFSET 20', $sql);
        $this->assertSame([], $params);
    }

    public function test_limit_is_clamped_and_offset_is_not_negative(): void
    {
        $db = $this->db();
        (new AuditLogRepository($db))->search(new AuditSearch(), 9999, -5);

        $this->assertStringEndsWith('LIMIT 200 OFFSET 0', $db->calls[0][0]);
    }

    public function test_text_wildcards_are_escaped_and_value_is_bound(): void
    {
        $db = $this->db();
        (new AuditLogRepository($db))->count(new AuditSearch(text: "50%_off!'; DROP"));

        [$sql, $params] = $db->calls[0];
        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringContainsString("ESCAPE '!'", $sql);
        $this->assertSame("%50!%!_off!!'; DROP%", $params['p0']);
    }

    public function test_filters_are_anded_and_all_values_bound(): void
    {
        $db = $this->db();
        $f  = new AuditSearch(
            tenantId: 't1',
            actions: ['a.x', 'b.y'],
            text: 'bob',
            textUserIds: ['u1'],
            textMetaValueKeys: ['nominee'],
            textMetaIn: ['tenant' => ['t9']],
            metaAny: [['contestant', 'C1'], ['nomination', 'N1']],
            since: new \DateTimeImmutable('2026-01-02 03:04:05'),
        );
        (new AuditLogRepository($db))->count($f);

        [$sql, $params] = $db->calls[0];
        $this->assertStringContainsString('tenant_id = :p0', $sql);
        $this->assertStringContainsString('action IN (:p1, :p2)', $sql);
        $this->assertStringContainsString('occurred_at >= :p3', $sql);
        $this->assertContains('2026-01-02 03:04:05', $params);
        $this->assertContains('$.contestant', $params);
        $this->assertContains('C1', $params);
        $this->assertContains('u1', $params);
        $this->assertContains('t9', $params);
        $this->assertStringNotContainsString('C1', $sql);
        $this->assertStringNotContainsString('bob', $sql);
        $this->assertSame(substr_count($sql, ':p'), count($params) + $this->reuse($sql, $params));
    }

    /** Every placeholder in the SQL is bound, and nothing extra is. */
    private function reuse(string $sql, array $params): int
    {
        preg_match_all('/:(p\d+)/', $sql, $m);
        $this->assertEqualsCanonicalizing(array_keys($params), array_unique($m[1]));

        return count($m[1]) - count(array_unique($m[1]));
    }

    public function test_empty_action_list_runs_no_query(): void
    {
        $db   = $this->db();
        $repo = new AuditLogRepository($db);
        $none = new AuditSearch(actions: []);

        $this->assertSame([], $repo->search($none));
        $this->assertSame(0, $repo->count($none));
        $this->assertSame([], $repo->countByAction($none));
        $this->assertSame([], $repo->topActors($none));
        $this->assertSame(0, $repo->countActors($none));
        $this->assertSame([], $db->calls);
    }

    public function test_count_by_action_and_top_actors_shape_rows(): void
    {
        $repo = new AuditLogRepository($this->db([['action' => 'a.x', 'c' => '3']]));
        $this->assertSame(['a.x' => 3], $repo->countByAction(new AuditSearch()));

        $repo = new AuditLogRepository($this->db([['user_id' => null, 'c' => '2'], ['user_id' => 'u1', 'c' => '1']]));
        $this->assertSame(
            [['userId' => null, 'count' => 2], ['userId' => 'u1', 'count' => 1]],
            $repo->topActors(new AuditSearch()),
        );
    }

    public function test_meta_key_with_path_syntax_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditSearch(metaAny: [['a.b[0]', 'x']]);
    }
}
