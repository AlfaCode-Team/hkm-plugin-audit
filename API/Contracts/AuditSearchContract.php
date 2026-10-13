<?php

declare(strict_types=1);

namespace Plugins\Audit\API\Contracts;

use Plugins\Audit\API\Search\AuditSearch;
use Plugins\Audit\Domain\Entities\AuditEntry;

/**
 * Published filtered / counted / offset-paged read of the `audit_log` trail on
 * the request's connection. A separate contract from {@see AuditReaderContract}
 * (which is keyset-only and unfiltered) so that contract's implementers are
 * unaffected; a consumer that wants this read injects it as optional and falls
 * back to the reader when an older plugin does not bind it.
 *
 * Filtering, counting and grouping all happen in SQL, over the whole trail.
 * Order is newest first (descending id, which is monotonic with occurred_at).
 */
interface AuditSearchContract
{
    /** @return list<AuditEntry> One page; $limit is clamped to 1..200, $offset to >= 0. */
    public function search(AuditSearch $filters, int $limit = 50, int $offset = 0): array;

    /** How many entries match. */
    public function count(AuditSearch $filters): int;

    /**
     * Matching entries per action name. Lets a caller classify actions (severity,
     * area) itself and derive its chip counts without the plugin knowing them.
     *
     * @return array<string, int>
     */
    public function countByAction(AuditSearch $filters): array;

    /**
     * The busiest actors among matching entries, most active first. A null
     * userId is the system (entries recorded with no actor).
     *
     * @return list<array{userId: ?string, count: int}>
     */
    public function topActors(AuditSearch $filters, int $limit = 5): array;

    /** How many distinct actors (excluding the system) appear among matching entries. */
    public function countActors(AuditSearch $filters): int;
}
