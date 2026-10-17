<?php

namespace App\Library\Dashboard\Home;

use App\Enums\Dashboard\AttentionSeverity;
use App\Enums\Dashboard\AttentionType;
use App\Library\Coo\NextBestMove;
use App\Library\Dashboard\AttentionItem;
use App\Library\Dashboard\BusinessStatusRow;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantSuggestions;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\HomeActivityWindow;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * Home V2 — the coordinator that turns the bands BusinessHomePresenter already
 * read into ONE coherent command center.
 *
 * It owns presentation decisions and NO facts:
 *
 *  - the ONE primary move is NextBestMoveSelector's pick, handed to it through
 *    the `next_best_move` band; Growth items may only ENRICH that move (same
 *    Opportunity) or fill the secondary queue — they never compete for the
 *    primary slot;
 *  - "Needs your attention" is the Business's own attention items minus the one
 *    already shown as the primary move, plus booking problems BookingTypeManager
 *    itself reports, plus real "awaiting approval" Opportunities;
 *  - every figure on the page still comes from the band that owns it
 *    (headlines, activity, recent work). This class does arithmetic on none
 *    of them and runs no query.
 *
 * A pure function of its inputs, so each of the four states, the dedupe rule
 * and the all-clear honesty rule can be tested without a database.
 */
final class HomeCommandCenter
{
    public const STATE_SETUP = 'setup';

    public const STATE_BROKEN = 'broken';

    public const STATE_NORMAL = 'normal';

    public const STATE_GROWING = 'growing';

    /** Attention types that mean something the Business relies on has stopped working. */
    private const BROKEN_TYPES = [
        AttentionType::GoogleConnectionLost,
        AttentionType::AutomationFailing,
    ];

    public function __construct(
        private readonly HomeBriefing $briefing,
        private readonly HomeSetupJourney $journey,
        private readonly HomeActionRouter $router,
        private readonly AssistantSuggestions $assistantSuggestions,
        private readonly DashboardLinkGate $links,
    ) {
    }

    /**
     * @param  array<int, string>  $scoped  [workspaceUid, businessUid]
     * @param  array<string, mixed>  $bands  the presenter's bands so far
     * @param  array<int, string>  $failed  the presenter's failed band keys
     * @param  array<int, AttentionItem>  $attention  non-billing items this actor can fix
     * @param  array<string, mixed>|null  $booking  HomeBookingReader::summary(), null when Calendar is not in play
     * @return array<string, mixed>
     */
    public function build(
        Business $business,
        CustomerContext $context,
        User $user,
        MenuEntitlements $entitlements,
        array $scoped,
        ?BusinessStatusRow $status,
        array $bands,
        array $failed,
        array $attention,
        ?array $booking,
    ): array {
        $actions = $this->router->forActor($context, $user, $entitlements, $scoped, $booking['firstLocationUid'] ?? null);

        // ── Setup journey (STATE 1) ────────────────────────────────────────
        $journey = $this->setup($business, $context, $user, $entitlements, $scoped, $status, $booking);

        // ── Primary move (one) and its secondary queue ─────────────────────
        $nbmFailed = in_array(DashboardSnapshot::BAND_NEXT_BEST_MOVE, $failed, true);
        $nbm = $bands[DashboardSnapshot::BAND_NEXT_BEST_MOVE] ?? null;
        $growth = $bands[DashboardSnapshot::BAND_GROWTH] ?? null;
        $primary = null;
        $primaryUid = null;
        $primaryAttentionType = null;

        if (is_array($nbm) && is_array($nbm['move'] ?? null)) {
            $primary = $this->primary($nbm['move'], $growth, $actions);
            $primaryUid = $nbm['move']['subjectUid'] ?? null;
            $primaryAttentionType = ($nbm['move']['kind'] ?? null) === NextBestMove::KIND_ATTENTION ? $nbm['move']['key'] : null;
        }

        $queue = [];

        if (is_array($growth)) {
            foreach ($growth['items'] as $item) {
                if ($primaryUid !== null && $item['uid'] === $primaryUid) {
                    continue;
                }

                $queue[] = [
                    'uid' => $item['uid'],
                    'title' => $item['title'],
                    'context' => $item['headline'],
                    'category' => $item['category_label'],
                    'impact' => $item['impact'],
                    'awaitingApproval' => (bool) ($item['awaiting_approval'] ?? false),
                    'url' => $item['detail_url'],
                ];
            }
        }

        // ── Needs your attention ───────────────────────────────────────────
        $attentionItems = $this->attentionItems($context, $user, $entitlements, $scoped, $attention, $primaryAttentionType, $growth, $primaryUid, $booking, $actions);
        // A band Home could not read leaves "nothing needs you" unproven (see readFailed()).
        $sourcesFailed = $status === null || self::readFailed($failed);
        $visible = max(1, (int) config('home.attention_visible', 3));

        // ── State ──────────────────────────────────────────────────────────
        $problem = false;

        foreach ($attention as $item) {
            if (in_array($item->type, self::BROKEN_TYPES, true)) {
                $problem = true;
            }
        }

        foreach ($attentionItems as $item) {
            if ($item['kind'] === 'problem' && ($item['broken'] ?? false)) {
                $problem = true;
            }
        }

        $billingException = (bool) ($bands[DashboardSnapshot::BAND_BILLING_EXCEPTION] ?? null);

        if ($billingException) {
            $problem = true;
        }

        $growing = $primary !== null && $primary['kind'] === NextBestMove::KIND_OPPORTUNITY || $queue !== [];

        // Readiness is UNKNOWN when the status could not be read: neither "still setting up" nor "ready" may be
        // claimed, and "growing" is never presented without the readiness it depends on.
        $readinessUnknown = $status === null || ! $status->known;

        $state = match (true) {
            $journey !== null && $journey['essentialIncomplete'] => self::STATE_SETUP,
            $problem => self::STATE_BROKEN,
            $growing && ! $readinessUnknown => self::STATE_GROWING,
            default => self::STATE_NORMAL,
        };

        // ── Briefing ───────────────────────────────────────────────────────
        $activity = $bands[DashboardSnapshot::BAND_ACTIVITY] ?? null;
        $window = is_array($activity) && ($activity['window'] ?? null) instanceof HomeActivityWindow ? $activity['window'] : null;
        $briefing = $this->briefing->compose(
            is_array($activity) ? ($activity['counts'] ?? []) : null,
            $window,
            $business->name,
        );

        $offering = AssistantContext::forOffering((int) $context->frameWorkspace()->id, (string) $context->frameWorkspace()->uid, $business, $user, $context, $entitlements, $actions, $scoped);

        // The waiting-customers move carries its own real figure and, where the assistant can prepare replies, the AI-assisted button.
        if ($primary !== null && $primary['key'] === AttentionType::ConversationsAwaitingReply->value) {
            $primary['metric'] = $this->waitingMetric($bands);
            $primary['aiAction'] = $this->assistantSuggestions->draftReplies($offering);
        }

        return [
            'state' => $state,
            'greeting' => $this->greeting($business, $user),
            'briefing' => $briefing + ['unavailable' => in_array(DashboardSnapshot::BAND_ACTIVITY, $failed, true)],
            // A billing exception is something that needs the customer even when no move or attention item carries it.
            'status' => $this->status($state, $readinessUnknown, $nbmFailed, $sourcesFailed, ($primary !== null ? 1 : 0) + count($attentionItems) + ($billingException ? 1 : 0)),
            'setup' => $state === self::STATE_SETUP ? $journey : null,
            'readinessUnknown' => $readinessUnknown,
            'primary' => $primary,
            'primaryFailed' => $nbmFailed,
            'growthShown' => is_array($growth),
            'caughtUp' => $primary === null && ! $nbmFailed && ! $sourcesFailed && ! $readinessUnknown && ! $billingException && $state !== self::STATE_SETUP,
            'queue' => $queue,
            'queueCount' => is_array($growth) ? (int) $growth['open_count'] : (int) (($nbm['recommendations']['label'] ?? 0) ?: 0),
            'queueLabel' => is_array($growth) ? (string) $growth['open_count'] : (string) ($nbm['recommendations']['label'] ?? '0'),
            'queueUrl' => is_array($growth) ? ($growth['urls']['all'] ?? null) : ($nbm['recommendations']['url'] ?? null),
            'attention' => [
                'visible' => array_slice($attentionItems, 0, $visible),
                'overflow' => array_slice($attentionItems, $visible),
                // "All clear" is only ever said when nothing is wrong ANYWHERE — a primary exception counts.
                'allClear' => $attentionItems === [] && ! $sourcesFailed && $primaryAttentionType === null,
                'unknown' => $attentionItems === [] && $sourcesFailed && $primaryAttentionType === null,
                'hasPrimaryException' => $primaryAttentionType !== null,
            ],
            'handling' => $this->handling($bands, $growth),
            'quietSteps' => $this->quietSteps($actions),
            'actions' => $actions,
            'ask' => [
                // Contextual chips: setup help while launching, performance and optimisation once running. Each is the
                // literal question it sends, and is offered only when the tool that answers it is open to this actor.
                'suggestions' => $this->assistantSuggestions->for($offering, $state === self::STATE_SETUP),
                'disclosure' => \App\Library\Assistant\AssistantRetention::disclosure(),
                'url' => route('customer.workspaces.businesses.home.ask', $scoped),
                'assistant' => [
                    'show' => route('customer.workspaces.businesses.home.assistant.show', $scoped),
                    'conversations' => route('customer.workspaces.businesses.home.assistant.conversations', $scoped),
                    'message' => route('customer.workspaces.businesses.home.assistant.message', $scoped),
                    'approve' => route('customer.workspaces.businesses.home.assistant.approve', array_merge($scoped, ['__UID__'])),
                    'decline' => route('customer.workspaces.businesses.home.assistant.decline', array_merge($scoped, ['__UID__'])),
                    'delete' => route('customer.workspaces.businesses.home.assistant.delete', array_merge($scoped, ['__UID__'])),
                ],
            ],
        ];
    }

    /**
     * "7 waiting for you": the CURRENT awaiting-reply count the Conversations band already read (across all time, not the
     * selected period). It is shown on its own: the band's other figures (incoming/replied) are limited to the selected analytics
     * period, so they are a different population and no "of N" is ever derived from them. Null when the figure is unavailable.
     *
     * @param  array<string, mixed>  $bands
     * @return array{value: int, caption: string}|null
     */
    private function waitingMetric(array $bands): ?array
    {
        $band = $bands[DashboardSnapshot::BAND_CONVERSATIONS] ?? null;

        if (! is_array($band)) {
            return null;
        }

        $figures = [];

        foreach ($band['items'] ?? [] as $item) {
            $figures[$item['key']] = (int) preg_replace('/\D/', '', (string) $item['figure']);
        }

        if (! isset($figures['awaiting_reply']) || $figures['awaiting_reply'] < 1) {
            return null;
        }

        return ['value' => $figures['awaiting_reply'], 'caption' => 'waiting for you'];
    }

    /**
     * "While it's quiet": practical next steps, each only when its page exists for this actor (the router returns null
     * for a feature the plan lacks or a permission the actor does not hold).
     *
     * @param  array<string, array<string, mixed>|null>  $actions
     * @return array<int, array{key: string, title: string, text: string, url: string, drawerUrl: ?string}>
     */
    private function quietSteps(array $actions): array
    {
        $copy = [
            'booking-type' => ['Create a booking type', 'Let customers book you directly.'],
            'form' => ['Add a form to your website', 'Turn visitors into contacts.'],
            'contact' => ['Add a contact', "Bring in someone you're already talking to."],
        ];
        $steps = [];

        foreach ($copy as $key => [$title, $text]) {
            $action = $actions[$key] ?? null;

            if ($action !== null && ($action['fullUrl'] ?? null) !== null) {
                $steps[] = ['key' => $key, 'title' => $title, 'text' => $text, 'url' => $action['fullUrl'], 'drawerUrl' => $action['drawerUrl'] ?? null];
            }
        }

        return $steps;
    }

    /**
     * Whether any read that proves "nothing needs you" failed: the move and growth reads, and the activity, headlines, recent-work and
     * automations bands. One failed read is enough: the status says it could not check instead of claiming all is well.
     *
     * @param  array<int, string>  $failed  the presenter's failed band keys
     */
    public static function readFailed(array $failed): bool
    {
        return array_intersect($failed, [
            DashboardSnapshot::BAND_NEXT_BEST_MOVE,
            DashboardSnapshot::BAND_GROWTH,
            DashboardSnapshot::BAND_ACTIVITY,
            DashboardSnapshot::BAND_HEADLINES,
            DashboardSnapshot::BAND_RECENT_WORK,
            DashboardSnapshot::BAND_AUTOMATIONS,
        ]) !== [];
    }
    /**
     * The Business's one-line state, said only when the evidence supports it: an unreadable source is "unavailable",
     * never "all caught up".
     *
     * `pill` is the short form beside the greeting; `count` is how many things need the customer.
     *
     * @return array{key: string, label: string, pill: string, count: int}
     */
    private function status(string $state, bool $readinessUnknown, bool $moveFailed, bool $sourcesFailed, int $needing): array
    {
        return match (true) {
            $state === self::STATE_SETUP => ['key' => 'setup', 'label' => 'Setup still in progress', 'pill' => 'Setup still in progress', 'count' => $needing],
            $needing > 0 => ['key' => 'attention', 'label' => 'Needs your attention', 'pill' => $needing === 1 ? '1 thing needs you' : $needing . ' things need you', 'count' => $needing],
            $readinessUnknown || $moveFailed || $sourcesFailed => ['key' => 'unavailable', 'label' => 'Data temporarily unavailable', 'pill' => 'Data temporarily unavailable', 'count' => 0],
            default => ['key' => 'clear', 'label' => 'All caught up', 'pill' => 'All caught up · nothing needs you', 'count' => 0],
        };
    }

    /** "Good morning" — in the Business's own timezone, never the server's. */
    private function greeting(Business $business, User $user): array
    {
        $tz = (string) ($business->timezone ?: config('app.timezone', 'UTC'));

        try {
            $hour = (int) CarbonImmutable::now($tz)->format('G');
        } catch (\Throwable) {
            $hour = (int) CarbonImmutable::now()->format('G');
        }

        $salutation = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        $first = trim((string) ($user->first_name ?? ''));

        return [
            'salutation' => $salutation,
            'name' => $first !== '' ? $first : null,
            'business' => (string) $business->name,
            'subline' => "Here's what's happening with " . $business->name . '.',
        ];
    }

    /**
     * @param  array<string, mixed>  $move  the next_best_move band's payload
     * @param  array<string, mixed>|null  $growth
     * @param  array<string, array<string, mixed>|null>  $actions
     * @return array<string, mixed>
     */
    private function primary(array $move, ?array $growth, array $actions): array
    {
        $kind = $move['kind'];
        $enriched = null;

        if ($kind === NextBestMove::KIND_OPPORTUNITY && is_array($growth)) {
            foreach ($growth['items'] as $item) {
                if (($move['subjectUid'] ?? null) === $item['uid']) {
                    $enriched = $item;

                    break;
                }
            }
        }

        $severity = $move['severity'] ?? null;
        $action = ['label' => $move['actionLabel'], 'url' => $move['actionUrl'], 'drawer' => null, 'drawerUrl' => null];
        $detailUrl = null;
        $evidence = [];
        $impact = null;
        $headline = $move['headline'];
        $summary = null;
        $confidence = null;
        $statusKey = 'ready';
        $statusLabel = 'Ready to act on';

        if ($kind === NextBestMove::KIND_ATTENTION) {
            if ($move['key'] === AttentionType::ConversationsAwaitingReply->value && ($actions['follow-ups']['drawerUrl'] ?? null) !== null) {
                $action = ['label' => 'Review follow-ups', 'url' => $actions['follow-ups']['fullUrl'], 'drawer' => 'follow-ups', 'drawerUrl' => $actions['follow-ups']['drawerUrl']];
            }

            if ($severity === AttentionSeverity::Blocking) {
                $statusKey = 'blocked';
                $statusLabel = 'Blocked until fixed';
            }
        } elseif ($enriched !== null) {
            $headline = $enriched['title'];
            $summary = $enriched['summary'] ?: null;
            $detailUrl = $enriched['detail_url'];
            $impact = $enriched['expected'] ?? null;
            $confidence = $enriched['confidence'];
            $evidence = array_values(array_filter([
                $enriched['headline'] !== $enriched['title'] ? $enriched['headline'] : null,
                $enriched['evidence_summary'],
                $enriched['age_label'] ?: null,
            ]));

            $isAds = str_contains(strtolower((string) ($enriched['source_module'] ?? '')), 'ads');

            if ($isAds && ($actions['ads']['drawerUrl'] ?? null) !== null) {
                $action = ['label' => 'Review ads recommendation', 'url' => $actions['ads']['fullUrl'], 'drawer' => 'ads', 'drawerUrl' => $actions['ads']['drawerUrl']];
            } elseif ($enriched['action_url'] !== null) {
                $action = ['label' => $enriched['action_label'], 'url' => $enriched['action_url'], 'drawer' => null, 'drawerUrl' => null];
            } else {
                $action = ['label' => 'See details', 'url' => $detailUrl, 'drawer' => null, 'drawerUrl' => null];
            }

            if ($enriched['awaiting_approval'] ?? false) {
                $statusKey = 'awaiting_approval';
                $statusLabel = 'Waiting for your approval';
            } elseif (($enriched['state'] ?? '') === 'in_progress') {
                $statusKey = 'in_progress';
                $statusLabel = 'In progress';
            } elseif ($confidence === 'Limited data') {
                $statusKey = 'needs_evidence';
                $statusLabel = 'Waiting for more evidence';
            }
        }

        return [
            'kind' => $kind,
            'key' => $move['key'],
            'headline' => $headline,
            'summary' => $summary,
            // A "why" line that merely repeats the headline adds nothing.
            'why' => array_values(array_filter($move['why'], fn (string $line): bool => trim($line) !== trim((string) $headline))),
            'evidence' => $evidence,
            'impact' => $impact,
            'confidence' => $confidence,
            'severity' => $severity,
            'status' => $statusKey,
            'statusLabel' => $statusLabel,
            'needsMoreInfo' => $statusKey === 'needs_evidence',
            'action' => $action['url'] === null && $action['drawerUrl'] === null ? null : $action,
            'detailUrl' => $detailUrl,
            'explanation' => $move['explanation'] ?? null,
            'metric' => null,
            'aiAction' => null,
        ];
    }

    /**
     * @param  array<int, AttentionItem>  $attention
     * @param  array<string, mixed>|null  $growth
     * @param  array<string, mixed>|null  $booking
     * @param  array<string, array<string, mixed>|null>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function attentionItems(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, array $attention, ?string $primaryType, ?array $growth, ?string $primaryUid, ?array $booking, array $actions): array
    {
        $items = [];

        foreach ($attention as $item) {
            // A problem is shown once. The primary move already carries this one.
            if ($primaryType !== null && $item->type->value === $primaryType) {
                continue;
            }

            $action = ['label' => $item->actionLabel, 'url' => $item->url, 'drawer' => null, 'drawerUrl' => null];

            if ($item->type === AttentionType::ConversationsAwaitingReply && ($actions['follow-ups']['drawerUrl'] ?? null) !== null) {
                $action = ['label' => 'Review follow-ups', 'url' => $actions['follow-ups']['fullUrl'], 'drawer' => 'follow-ups', 'drawerUrl' => $actions['follow-ups']['drawerUrl']];
            }

            $items[] = [
                'key' => $item->type->value,
                'kind' => $item->severity === AttentionSeverity::Informational ? 'info' : 'problem',
                'broken' => in_array($item->type, self::BROKEN_TYPES, true),
                'text' => $item->text,
                'action' => $action,
            ];
        }

        foreach ($booking['problems'] ?? [] as $problem) {
            $url = $this->links->url(
                $context, $user, $entitlements,
                'customer.workspaces.businesses.calendar.booking-types.edit',
                array_merge($scoped, [$problem['locationUid'], $problem['typeUid']]),
                ['access_backend'], 'calendar',
            );

            $items[] = [
                'key' => 'booking_unavailable:' . $problem['typeUid'],
                'kind' => 'problem',
                // Only a problem that leaves NO bookable type at all is "something is broken".
                'broken' => ($booking['readyCount'] ?? 0) === 0,
                'text' => 'Customers cannot book "' . $problem['name'] . '" yet. ' . $problem['reason'],
                'action' => ['label' => 'Fix booking setup', 'url' => $url, 'drawer' => null, 'drawerUrl' => null],
            ];
        }

        if (is_array($growth)) {
            foreach ($growth['items'] as $item) {
                if (! ($item['awaiting_approval'] ?? false) || ($primaryUid !== null && $item['uid'] === $primaryUid)) {
                    continue;
                }

                $items[] = [
                    'key' => 'approval:' . $item['uid'],
                    'kind' => 'approval',
                    'broken' => false,
                    'text' => $item['title'] . ' is waiting for your approval.',
                    'action' => ['label' => 'Review', 'url' => $item['detail_url'], 'drawer' => null, 'drawerUrl' => null],
                ];
            }
        }

        $order = ['problem' => 0, 'approval' => 1, 'processing' => 2, 'info' => 3];
        usort($items, fn (array $a, array $b): int => $order[$a['kind']] <=> $order[$b['kind']]);

        return $items;
    }

    /**
     * What MotionGrove has actually been doing: the recent-work timeline's
     * persisted facts, plus in-progress / awaiting-approval Opportunities.
     * A queued job is never listed as done — only rows that prove an outcome are.
     *
     * @param  array<string, mixed>  $bands
     * @param  array<string, mixed>|null  $growth
     * @return array<int, array<string, mixed>>
     */
    private function handling(array $bands, ?array $growth): array
    {
        $rows = [];

        if (is_array($growth)) {
            foreach ($growth['items'] as $item) {
                if ($item['awaiting_approval'] ?? false) {
                    $rows[] = ['key' => 'recommendation_awaiting_approval', 'text' => $item['title'], 'status' => 'approval', 'at' => null, 'url' => $item['detail_url']];
                } elseif (($item['state'] ?? '') === 'in_progress') {
                    $rows[] = ['key' => 'recommendation_in_progress', 'text' => $item['title'], 'status' => 'progress', 'at' => null, 'url' => $item['detail_url']];
                }
            }
        }

        $recent = $bands[DashboardSnapshot::BAND_RECENT_WORK] ?? null;

        foreach (is_array($recent) ? $recent['items'] : [] as $item) {
            $rows[] = [
                'key' => $item['key'],
                'text' => $item['text'],
                'status' => $item['key'] === 'automation_failed' ? 'failed' : 'completed',
                'at' => $item['at'],
                'url' => $item['url'],
            ];
        }

        return array_slice($rows, 0, max(1, (int) config('home.handling_visible', 5)));
    }

    /**
     * The Launch journey, from facts this request has ALREADY read:
     *  - the Business row the presenter loaded (name, niche, website source and address);
     *  - the BusinessStatusRow: website status, primary Location/Service, usable forms, a matching external-site
     *    check and the readiness policy, all inside the one status statement the presenter already issues;
     *  - the Calendar summary, only when Calendar is in play for this actor.
     * It issues no query of its own, with one bounded exception: a Business-wide booking-readiness boolean is read
     * only when it is needed to classify the Business (no other reach evidence) and the actor's own Calendar summary
     * does not already cover every active Location.
     *
     * Returns null when the status could not be read: nothing is then known, so nothing is shown as done.
     *
     * @param  array<string, mixed>|null  $booking
     * @return array<string, mixed>|null
     */
    private function setup(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, ?BusinessStatusRow $status, ?array $booking): ?array
    {
        if ($status === null || ! $status->known) {
            return null;
        }

        $calendar = $booking !== null;
        $firstLoc = $booking['firstLocationUid'] ?? null;
        $owns = ! $context->isViewingAsClient() && (int) $business->customer_id === (int) $user->id;
        // The wizard edits the customer's ONE onboarding Business. It is offered for a missing foundation step only when it
        // is bound to THIS Business; a secondary Business is edited through its own scoped settings, never through the
        // wizard of the Business the customer originally onboarded.
        $wizard = $owns && $status->onboardingBound && (bool) config('business.onboarding.enabled', false) && Route::has('customer.onboarding.show');
        $settings = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.settings.show', $scoped, ['access_backend']);
        $primaryDetails = $owns && $business->is_primary
            ? $this->links->url($context, $user, $entitlements, 'customer.business.edit', [], ['access_backend'])
            : null;

        $urls = [
            'locations' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.locations.index', $scoped, ['access_backend']),
            'bookingType' => $firstLoc === null ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.booking-types.create', array_merge($scoped, [$firstLoc]), ['access_backend'], 'calendar'),
            'fixBooking' => $firstLoc === null ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.booking-types.index', array_merge($scoped, [$firstLoc]), ['access_backend'], 'calendar'),
            'website' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'),
            'forms' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.forms.index', $scoped, ['forms'], 'forms'),
            'business' => $wizard ? route('customer.onboarding.show', ['step' => 'business']) : ($primaryDetails ?? $settings),
            'businessLabel' => $wizard ? 'Add details' : 'Open settings',
            // The Business-scoped Services editor works for EVERY Business (the wizard edits only the customer's one onboarding
            // Business), so a secondary Business is never sent into another Business's wizard. Only an actor who may change the
            // services is offered it; anyone else sees the step without an action.
            'service' => $context->canManageWorkspace()
                ? $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.services.index', $scoped, ['access_backend'])
                : null,
            'serviceLabel' => 'Add service',
        ];

        // The website source: the stored choice, or "hosted" for a Business that already owns a site.
        $websiteMode = ((string) $business->getRawOriginal('website_mode')) ?: ($status->websiteStatus !== null ? 'hosted' : null);

        if ($status->readinessPolicy !== HomeSetupJourney::POLICY_SEO_FIRST) {
            // An established Business that predates the SEO-first policy keeps its original Home-stage rules.
            return $this->journey->build([
                'policy' => 'legacy',
                'hasLocation' => $calendar ? ($booking['locationIds'] !== []) : true,
                'calendar' => $calendar,
                'activeTypeCount' => (int) ($booking['activeTypeCount'] ?? 0),
                'readyCount' => (int) ($booking['readyCount'] ?? 0),
                'problemReason' => $booking['problems'][0]['reason'] ?? null,
                'websiteStatus' => $status->websiteStatus,
                'websiteApplicable' => $urls['website'] !== null,
            ], $urls);
        }

        // ── SEO-first policy: Business-wide readiness, independent of who is looking ─────────────────────
        $websiteCapable = $entitlements->allows('website_generation');
        $formsCapable = $entitlements->allows('forms');
        $calendarCapable = $entitlements->allows('calendar');

        $websiteUsable = $status->websiteStatus === 'published'
            || ($websiteMode === 'external' && ($status->hasCompletedExternalAudit || filled($business->website_url)));
        $formReady = ($formsCapable && $status->hasLiveForm)
            || ($websiteCapable && $status->websiteStatus === 'published' && $status->hasActiveWebsiteForm);

        $foundation = filled($business->name) && $business->industry !== null && $status->hasPrimaryLocation && $status->hasPrimaryService;
        $bookingPathReady = false;

        // Needed only while the journey can still be shown: some reach evidence is missing, or the foundation is.
        if ($calendarCapable && ! $formReady && (! $foundation || ! $websiteUsable)) {
            // The actor's own summary is Business-wide only when it covers every active Location.
            $bookingPathReady = $booking !== null && count($booking['locationIds']) === $status->activeLocationCount
                ? (int) $booking['readyCount'] > 0
                : app(HomeBookingReader::class)->businessWideBookingReady($business);
        }

        return $this->journey->build([
            'policy' => HomeSetupJourney::POLICY_SEO_FIRST,
            'hasIdentity' => filled($business->name) && $business->industry !== null,
            'hasLocation' => $status->hasPrimaryLocation,
            'hasPrimaryService' => $status->hasPrimaryService,
            'websiteCapable' => $websiteCapable,
            'formsCapable' => $formsCapable,
            'calendarCapable' => $calendarCapable,
            'bookingPathReady' => $bookingPathReady,
            'websiteStatus' => $status->websiteStatus,
            'websiteMode' => $websiteMode,
            'hasCompletedExternalAudit' => $status->hasCompletedExternalAudit,
            'hasExternalWebsiteAddress' => filled($business->website_url),
            'hasLiveForm' => $status->hasLiveForm,
            'hasActiveWebsiteForm' => $status->hasActiveWebsiteForm,
            // Actor-scoped display detail only (never an input to readiness).
            'calendar' => $calendar,
            'typeCount' => (int) ($booking['typeCount'] ?? 0),
            'activeTypeCount' => (int) ($booking['activeTypeCount'] ?? 0),
            'readyCount' => (int) ($booking['readyCount'] ?? 0),
            'problemReason' => $booking['problems'][0]['reason'] ?? null,
        ], $urls);
    }
}
