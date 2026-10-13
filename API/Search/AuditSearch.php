<?php

declare(strict_types=1);

namespace Plugins\Audit\API\Search;

/**
 * AuditSearch — the filters of one {@see \Plugins\Audit\API\Contracts\AuditSearchContract}
 * read. Every field is optional; an empty search matches the whole trail. All
 * filters are AND-ed, and every value reaches SQL as a bound parameter.
 *
 * The free-text filter ($text) matches case-insensitively, as a substring, any of:
 * the action, the IP, the event id, the NAMES of the meta keys, a user id in
 * $textUserIds, a meta value under one of $textMetaValueKeys, or a meta value
 * listed under $textMetaIn. The last three exist because the trail stores
 * identifiers only: the caller resolves a name (a person, an organisation) to
 * ids elsewhere and hands the ids over, so the match stays in SQL.
 */
final readonly class AuditSearch
{
    /**
     * @param ?string                      $tenantId  rows of this tenant_id only
     * @param ?string                      $userId    rows of this actor only
     * @param ?list<string>                $actions   exact action names; null = any, [] = matches nothing
     * @param ?string                      $text      the free-text needle
     * @param list<string>                 $textUserIds       user ids a text match also accepts
     * @param list<string>                 $textMetaValueKeys meta keys whose VALUE the needle is matched against
     * @param array<string, list<string>>  $textMetaIn        meta key => values a text match also accepts (exact)
     * @param list<array{0: string, 1: string}> $metaAny      [meta key, value] pairs; a row must equal at least one
     * @param ?\DateTimeImmutable          $since     rows occurred at or after this instant
     */
    public function __construct(
        public ?string $tenantId = null,
        public ?string $userId = null,
        public ?array $actions = null,
        public ?string $text = null,
        public array $textUserIds = [],
        public array $textMetaValueKeys = [],
        public array $textMetaIn = [],
        public array $metaAny = [],
        public ?\DateTimeImmutable $since = null,
    ) {
        foreach ($textMetaValueKeys as $key) {
            self::assertKey($key);
        }
        foreach (array_keys($textMetaIn) as $key) {
            self::assertKey((string) $key);
        }
        foreach ($metaAny as $pair) {
            self::assertKey((string) ($pair[0] ?? ''));
        }
    }

    /** A meta key becomes part of a JSON path, so it is restricted to a safe alphabet. */
    private static function assertKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid audit meta key [{$key}].");
        }
    }

    public function with(
        ?string $text = null,
        ?array $actions = null,
        ?\DateTimeImmutable $since = null,
    ): self {
        return new self(
            tenantId:          $this->tenantId,
            userId:            $this->userId,
            actions:           $actions ?? $this->actions,
            text:              $text ?? $this->text,
            textUserIds:       $this->textUserIds,
            textMetaValueKeys: $this->textMetaValueKeys,
            textMetaIn:        $this->textMetaIn,
            metaAny:           $this->metaAny,
            since:             $since ?? $this->since,
        );
    }

    /** The same search without its free-text part (and the ids that belong to it). */
    public function withoutText(): self
    {
        return new self(
            tenantId: $this->tenantId,
            userId:   $this->userId,
            actions:  $this->actions,
            metaAny:  $this->metaAny,
            since:    $this->since,
        );
    }
}
