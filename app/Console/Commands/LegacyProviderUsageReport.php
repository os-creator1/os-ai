<?php

namespace App\Console\Commands;

use App\Library\Messaging\LegacyWebhookRouteRegistry;
use App\Models\CustomerBasedSendingServer;
use App\Models\LegacyWebhookRouteUsage;
use App\Models\SendingServer;
use Illuminate\Console\Command;

/**
 * Legacy Provider Webhook Measurement Contract §6 — the operator-only,
 * read-only retain/deprecate view. This command never mutates a
 * SendingServer, a CustomerBasedSendingServer, or a usage row, and it never
 * decides anything: it prints evidence for a human to read.
 *
 * SAFE BY CONSTRUCTION. It prints route names, provider slugs, counts and
 * timestamps only. Business bindings appear as counts per provider type,
 * never as named customers. It never touches SendingServer.settings (which
 * holds provider credentials) or any other credential-shaped column, so
 * there is no code path here that could print a credential, a token, an API
 * key, a webhook payload, message content or a phone number.
 *
 * §6 item 3 asks for "current active SendingServer provider types" grouped
 * by "settings" — SendingServer has no queryable, ungrouped "type of
 * settings" concept, and settings itself holds per-row credentials, so
 * grouping by it would be both meaningless and unsafe to print. The
 * mechanically correct and safe reading is SendingServer.type, the column
 * that actually names the provider (see the TYPE_* constants on that
 * model), grouped and counted exactly as this item asks. §6 item 4 groups
 * CustomerBasedSendingServer bindings the same way, via its `sending_server`
 * foreign key.
 */
class LegacyProviderUsageReport extends Command
{
    protected $signature = 'messaging:legacy-provider-usage-report';

    protected $description = 'Operator-only, read-only retain/deprecate evidence for the legacy provider webhook surface.';

    public function handle(): int
    {
        $this->printRouteHitAggregates();
        $this->printNeverObservedRoutes();
        $this->printActiveSendingServerTypes();
        $this->printBusinessBindingsByType();
        $this->printPlatformIssuedCallbackRisk();

        return self::SUCCESS;
    }

    /**
     * §6 item 1 — S0 route-hit aggregates.
     */
    private function printRouteHitAggregates(): void
    {
        $this->info('== Legacy route hit aggregates ==');

        $rows = LegacyWebhookRouteUsage::query()
            ->orderByDesc('hit_count')
            ->get(['route_name', 'provider_slug', 'hit_count', 'first_seen_at', 'last_seen_at']);

        if ($rows->isEmpty()) {
            $this->line('  (no hits recorded yet)');

            return;
        }

        $this->table(
            ['route_name', 'provider_slug', 'hit_count', 'first_seen_at', 'last_seen_at'],
            $rows->map(fn (LegacyWebhookRouteUsage $row): array => [
                $row->route_name,
                $row->provider_slug,
                $row->hit_count,
                optional($row->first_seen_at)->toDateTimeString(),
                optional($row->last_seen_at)->toDateTimeString(),
            ])->all(),
        );
    }

    /**
     * §6 item 2 — every registry entry with no row, or a row with
     * hit_count = 0. This is the column the retirement decision actually
     * turns on, so it is made explicit rather than left to be inferred from
     * the first table's absence.
     */
    private function printNeverObservedRoutes(): void
    {
        $this->info('== Never-observed legacy routes ==');

        $observedWithHits = LegacyWebhookRouteUsage::query()
            ->where('hit_count', '>', 0)
            ->pluck('provider_slug', 'route_name');

        $neverObserved = collect(LegacyWebhookRouteRegistry::all())
            ->reject(fn (string $slug, string $routeName): bool => $observedWithHits->has($routeName));

        if ($neverObserved->isEmpty()) {
            $this->line('  (every registered legacy route has at least one recorded hit)');

            return;
        }

        $this->table(
            ['route_name', 'provider_slug'],
            $neverObserved->map(fn (string $slug, string $routeName): array => [$routeName, $slug])->values()->all(),
        );
    }

    /**
     * §6 item 3 — current active SendingServer provider types, with a count
     * per type. See the class docblock for why this groups by `type` rather
     * than the literal word "settings".
     */
    private function printActiveSendingServerTypes(): void
    {
        $this->info('== Active SendingServer rows by provider type ==');

        $rows = SendingServer::query()
            ->where('status', true)
            ->selectRaw('type, count(*) as active_count')
            ->groupBy('type')
            ->orderByDesc('active_count')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  (no active SendingServer rows)');

            return;
        }

        $this->table(
            ['type', 'active_count'],
            $rows->map(fn ($row): array => [$row->type, $row->active_count])->all(),
        );
    }

    /**
     * §6 item 4 — CustomerBasedSendingServer / Business bindings, as counts
     * per provider type. Never a named customer or Business.
     */
    private function printBusinessBindingsByType(): void
    {
        $this->info('== Business/customer sending-server bindings by provider type ==');

        $rows = CustomerBasedSendingServer::query()
            ->join('sending_servers', 'sending_servers.id', '=', 'customer_based_sending_servers.sending_server')
            ->selectRaw('sending_servers.type as type, count(*) as binding_count')
            ->groupBy('sending_servers.type')
            ->orderByDesc('binding_count')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  (no bindings)');

            return;
        }

        $this->table(
            ['type', 'binding_count'],
            $rows->map(fn ($row): array => [$row->type, $row->binding_count])->all(),
        );
    }

    /**
     * §6 item 5 — the twelve platform-issued callback routes, flagged as a
     * separate risk class: for these a zero hit count is weaker evidence of
     * disuse than for a hand-configured URL, because the platform actively
     * published the URL.
     */
    private function printPlatformIssuedCallbackRisk(): void
    {
        $this->info('== Platform-issued callback routes (separate risk class, §6 item 5) ==');

        $observedWithHits = LegacyWebhookRouteUsage::query()
            ->where('hit_count', '>', 0)
            ->pluck('hit_count', 'route_name');

        $this->table(
            ['route_name', 'provider_slug', 'hit_count', 'zero_hits_is_weaker_evidence'],
            collect(LegacyWebhookRouteRegistry::PLATFORM_ISSUED_CALLBACK_ROUTES)
                ->map(function (string $routeName) use ($observedWithHits): array {
                    $slug = LegacyWebhookRouteRegistry::slugFor($routeName) ?? 'unknown';
                    $hits = (int) ($observedWithHits[$routeName] ?? 0);

                    return [$routeName, $slug, $hits, $hits === 0 ? 'yes' : 'n/a'];
                })
                ->all(),
        );
    }
}
