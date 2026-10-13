<?php

declare(strict_types=1);

namespace Plugins\Audit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Audit\API\Contracts\AuditReaderContract;
use Plugins\Audit\API\Contracts\AuditSearchContract;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Audit\Application\Ports\AuditWriter;
use Plugins\Audit\Application\Services\AuditService;
use Plugins\Audit\Infrastructure\Persistence\AuditLogRepository;
use Plugins\Audit\Infrastructure\Persistence\AuditTrail;

/**
 * Audit plugin — owns the 'audit.trail' domain and the `audit_log` table.
 *
 * The SINGLE writer/reader of that table. Other plugins (User, Feedback, Tenancy)
 * declare requires: ["audit.trail"] and record through the published
 * {@see AuditServiceContract} — they never write `audit_log` themselves.
 *
 * ── WHICH DATABASE: THE REQUEST'S ─────────────────────────────────────────
 * The writer and reader take the request's `DatabasePort`. On a tenant-routed
 * request that is the TENANT's database — each tenant has its own
 * `audit_log`, created from `database/tenant-template/` — and on an unrouted
 * request it is the central connection. There is no pinned central trail:
 * `database/migrations/` ships nothing, and an earlier revision of this
 * docblock claimed otherwise.
 *
 * A caller that needs a record in a SPECIFIC tenant's trail must therefore
 * write it while the request is scoped to that tenant (Tenancy's switch audit
 * does exactly this). Every entry is also emitted as a JSON log line, so a
 * request whose connection has no `audit_log` loses the row, not the event.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'audit.trail';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return ['database.management'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [AuditServiceContract::class, AuditReaderContract::class, AuditSearchContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Write side: persistence seam behind the audit service — on the
        // request's connection (see the class docblock). A request singleton
        // (bindInternal), so whichever connection it is first resolved on is
        // the one every record in that request goes to.
        $container->bindInternal(AuditWriter::class, static fn (ModuleContainer $c): AuditWriter =>
            new AuditTrail($c->make(DatabasePort::class)));

        // Published write contract — the ONE way any plugin records an action.
        // Auto-fills actor (Identity) and tenant (Tenancy's `tenant.current`
        // container key — a plain string, no Tenancy import) when omitted.
        $container->bind(AuditServiceContract::class, static function (ModuleContainer $c): AuditServiceContract {
            $identity = $c->has(Identity::class) ? $c->make(Identity::class) : null;
            $actorId  = $identity !== null ? ($identity->userId ?: null) : null;

            // Tenant source: the routed tenant (`tenant.current`, set by Tenancy's
            // TenantContextStage) when present, else the authoritative Identity
            // tenant — which is always bound at load and drives the routing itself,
            // so it is populated even on paths where the stage bound nothing.
            $tenantId = $c->has('tenant.current')
                ? ((string) $c->make('tenant.current') ?: null)
                : ($identity !== null ? ($identity->tenantId ?: null) : null);

            $clientIp = $c->has('client.ip') ? (string) $c->make('client.ip') : null;

            return new AuditService(
                writer:        $c->make(AuditWriter::class),
                actorId:       $actorId,
                currentTenant: $tenantId,
                clientIp:      $clientIp,
            );
        });

        // Published read/query contract for control-plane admin surfaces.
        $container->bind(AuditReaderContract::class, static fn (ModuleContainer $c): AuditReaderContract =>
            new AuditLogRepository($c->make(DatabasePort::class)));

        // Published filtered / counted / offset-paged read (opt-in; a separate
        // contract so AuditReaderContract implementers are unaffected).
        $container->bind(AuditSearchContract::class, static fn (ModuleContainer $c): AuditSearchContract =>
            new AuditLogRepository($c->make(DatabasePort::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or subscriptions — a pure infrastructure domain.
    }

  
}
