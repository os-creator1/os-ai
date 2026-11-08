<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Dashboard\Home\HomeBookingReader;
use App\Library\Dashboard\RecentWorkReader;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\User;
use Throwable;

/**
 * The parts of the Growth work record that are NOT Opportunity Engine executions, read from the records that already prove them — never
 * from a new store and never inferred:
 *
 *  - Booking setup: the live booking-readiness check (HomeBookingReader). "Ready" is only ever "ready as of this check"; a recheck is
 *    simply loading the page again.
 *  - Customer replies: replies whose send state was recorded (`submitted` = sent to the messaging provider, `delivered` = the provider
 *    reported delivery) and customers still waiting, by the inbox's own definition and Location rule. A reply is never a booking.
 *  - Website and SEO activity: the recorded audits, publishes and rank checks (RecentWorkReader). Draft / published / verified status of
 *    an individual metadata fix lives on the SEO audit page, which this links to.
 *
 * Each section appears only when this actor may open its module (DashboardLinkGate: permission, plan entitlement, ViewAs); a section
 * that cannot be read says so instead of showing zero.
 */
final class GrowthWorkConnections
{
    private const SEO_KEYS = ['seo_audit_completed', 'external_audit_completed', 'external_audit_partial', 'website_version_published', 'article_published', 'rank_check_scheduled', 'rank_check_manual'];

    public function __construct(
        private readonly HomeBookingReader $bookings,
        private readonly BusinessConversationReadModel $conversations,
        private readonly RecentWorkReader $recentWork,
        private readonly DashboardLinkGate $links,
    ) {
    }

    /**
     * @param  array{0: string, 1: string}  $scoped  [workspaceUid, businessUid]
     * @return array{booking: ?array<string, mixed>, replies: ?array<string, mixed>, activity: ?array<string, mixed>}
     */
    public function build(Business $business, User $user, CustomerContext $context, MenuEntitlements $entitlements, array $scoped): array
    {
        return [
            'booking' => $this->booking($business, $user, $context, $entitlements, $scoped),
            'replies' => $this->replies($business, $user, $context, $entitlements, $scoped),
            'activity' => $this->activity($business, $user, $context, $entitlements, $scoped),
        ];
    }

    /** @return array<string, mixed>|null */
    private function booking(Business $business, User $user, CustomerContext $context, MenuEntitlements $entitlements, array $scoped): ?array
    {
        $calendar = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.index', $scoped, ['access_backend'], 'calendar');

        if ($calendar === null) {
            return null;
        }

        try {
            $summary = $this->bookings->summary($business, (int) $user->id);
        } catch (Throwable $e) {
            report($e);

            return ['unavailable' => true];
        }

        $problems = array_map(function (array $p) use ($context, $user, $entitlements, $scoped): array {
            $fix = $p['locationUid'] === '' ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.booking-types.edit', array_merge($scoped, [$p['locationUid'], $p['typeUid']]), ['access_backend'], 'calendar');

            return ['name' => $p['name'], 'reason' => $p['reason'], 'fixUrl' => $fix];
        }, $summary['problems']);

        $create = ($summary['firstLocationUid'] ?? null) === null ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.booking-types.create', array_merge($scoped, [$summary['firstLocationUid']]), ['access_backend'], 'calendar');

        return [
            'unavailable' => false,
            'typeCount' => (int) $summary['typeCount'],
            'activeTypeCount' => (int) $summary['activeTypeCount'],
            'readyCount' => (int) $summary['readyCount'],
            'evaluated' => (int) $summary['evaluated'],
            'problems' => $problems,
            'createUrl' => $create,
            'calendarUrl' => $calendar,
        ];
    }

    /** @return array<string, mixed>|null */
    private function replies(Business $business, User $user, CustomerContext $context, MenuEntitlements $entitlements, array $scoped): ?array
    {
        $inbox = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.conversations.index', $scoped, ['chat_box'], 'conversations');

        if ($inbox === null) {
            return null;
        }

        try {
            $actor = (int) $user->id;
            $waiting = $this->conversations->awaitingReplyCount($business, null, $actor);
            $answered = $this->conversations->recentAnsweredByReply($business, $actor, 5);
            $names = $answered === [] ? [] : ChatBox::displayNamesFor($business, array_map(fn (array $r): object => (object) ['id' => $r['id'], 'to' => $r['to']], $answered));
        } catch (Throwable $e) {
            report($e);

            return ['unavailable' => true];
        }

        return [
            'unavailable' => false,
            'waiting' => $waiting,
            'answered' => array_map(fn (array $r): array => [
                'name' => (string) ($names[$r['id']] ?? $r['to']),
                'at' => $r['at'],
                'status' => $r['status'],
            ], $answered),
            'inboxUrl' => $inbox . (str_contains($inbox, '?') ? '&' : '?') . 'filter=waiting',
        ];
    }

    /** @return array<string, mixed>|null */
    private function activity(Business $business, User $user, CustomerContext $context, MenuEntitlements $entitlements, array $scoped): ?array
    {
        $audit = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.seo.audit.index', $scoped, ['view_seo'], 'seo_basic_visibility');

        if ($audit === null) {
            return null;
        }

        try {
            $items = array_values(array_filter(
                $this->recentWork->recent($business, RecentWorkReader::PER_SOURCE_LIMIT, (int) $user->id),
                fn ($item): bool => in_array($item->key, self::SEO_KEYS, true),
            ));
        } catch (Throwable $e) {
            report($e);

            return ['unavailable' => true];
        }

        return [
            'unavailable' => false,
            'auditUrl' => $audit,
            'items' => array_map(fn ($item): array => [
                'text' => $item->text,
                'at' => $item->at,
                // Only a recorded audit / publish / rank check: "Recorded", never a claim about rankings or traffic.
                'url' => $this->links->url($context, $user, $entitlements, $item->routeName, $item->businessScoped ? array_merge($scoped, $item->routeParameters) : $item->routeParameters, $item->permissions, $item->featureKey),
            ], array_slice($items, 0, 6)),
        ];
    }
}
