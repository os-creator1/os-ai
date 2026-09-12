<?php

namespace App\Library\Dashboard;

use App\DTO\Analytics\AutomationKpis;
use App\Enums\Dashboard\AttentionType;
use App\Enums\Dashboard\HeadlinePolarity;
use App\Enums\Dashboard\HeadlineTrend;
use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\User;
use App\Repositories\Contracts\OpportunityRepository;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The Business Home: a billing exception only when one is real, what has
 * actually changed since this customer was last here, what the Advisor
 * recommends, how the last 30 days compare, and at most four next actions —
 * all for the ONE Business the CustomerContext resolved (the viewed client's,
 * while viewing as one).
 *
 * Customer Experience Slice 4 §4 built it; Unified Business Home H-1 took
 * billing off it as a metric (§5), and H-2 added the activity window (§2.3).
 *
 * Auth::id() is never a tenant key here: the actor id is only ever the
 * capability actor (permissions, payer authority). Every figure comes from
 * the context's Business, which the resolver re-authorized on this very
 * request through WorkspaceManager::userCanAccessBusiness().
 *
 * Its sources, and nothing else:
 *  - the request's one MenuEntitlements snapshot (Slice 2A, §10), read through
 *    CustomerShellComposer so the shell and the page share it;
 *  - DashboardStatusReader — one statement for wallet, website and Google;
 *  - BusinessDashboardAnalyticsPresenter — B5's own KPI methods, two ranges;
 *  - BusinessConversationReadModel::startedCount() — Slice 2B's seam;
 *  - OpportunityRepository — open AND current recommendations;
 *  - BusinessAnalyticsQueries::countsBetween() / automationCountsBetween() —
 *    B5's own instant-window counts for the activity band;
 *  - HomeVisitMarker — the per-user, per-Business visit window (§2.3).
 *
 * Each band is built in its own try: one failing source degrades only its
 * own band (§12).
 *
 * While viewing as a client every figure is the viewed Business's, every
 * link passes ViewAsRouteClassification, and nothing that costs, funds,
 * touches a provider or switches identity is offered (§11).
 */
final class BusinessHomePresenter
{
    /** §6 — the same source-controlled bound as the former panel. Never request input. */
    public const RECOMMENDATION_LIMIT = 5;

    public const MAX_QUICK_ACTIONS = 4;

    public function __construct(
        private readonly CustomerShellComposer $shell,
        private readonly DashboardStatusReader $statusReader,
        private readonly BusinessDashboardAnalyticsPresenter $analytics,
        private readonly BusinessAnalyticsQueries $analyticsQueries,
        private readonly BusinessConversationReadModel $conversations,
        private readonly OpportunityRepository $opportunities,
        private readonly HomeVisitMarker $visits,
        private readonly DashboardLinkGate $links,
        private readonly ParentAccountSwitch $parentSwitch,
    ) {
    }

    /**
     * Null only when the context's Business no longer exists inside its
     * Workspace, in which case the caller renders the Account frame instead.
     */
    public function present(CustomerContext $context, User $user): ?DashboardSnapshot
    {
        $candidate = $context->selectedBusiness;
        $workspace = $context->frameWorkspace();

        if (! $context->isBusinessFrame() || $candidate === null || $workspace === null) {
            return null;
        }

        $business = Business::query()
            ->whereKey($candidate->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($business === null) {
            return null;
        }

        $entitlements = $this->shell->currentMenuEntitlements($context);
        $scoped = [$workspace->uid, $candidate->uid];
        $bands = [];
        $failed = [];

        $status = null;
        $statusFailed = false;

        try {
            $status = $this->statusReader->forBusiness((int) $business->id);
        } catch (Throwable $e) {
            report($e);
            $statusFailed = true;
        }

        // The B5 comparison feeds the headline row and the one attention type
        // B5 owns (AutomationFailing); it is only read when one of them can
        // actually render for this actor.
        $canSeeResults = Gate::forUser($user)->allows('view_reports');
        $automationsShown = $entitlements->allows('automations') && Gate::forUser($user)->allows('automations');
        $comparison = null;
        $comparisonFailed = false;

        if ($canSeeResults || $automationsShown) {
            try {
                $comparison = $this->analytics->comparison($business);
            } catch (Throwable $e) {
                report($e);
                $comparisonFailed = true;
            }
        }

        // 0 — Billing exception strip, 1 — Attention
        //
        // Billing left the Business Home as a metric (§5): it appears here
        // only as ONE compact exception a customer can actually act on, and
        // only for an actor whose remediation route resolves. Everything else
        // billing lives in Settings.
        if ($statusFailed) {
            $failed[] = DashboardSnapshot::BAND_ATTENTION;
        } else {
            $automationFailures = $automationsShown && $comparison !== null && $comparison['current']['automations'] instanceof AutomationKpis
                ? $comparison['current']['automations']->failed()
                : 0;
            $items = $this->attention($context, $user, $entitlements, $scoped, $candidate->name, $status, $automationFailures);

            $billing = array_values(array_filter($items, fn (AttentionItem $item) => self::isBilling($item->type)));
            $attention = array_values(array_filter($items, fn (AttentionItem $item) => ! self::isBilling($item->type)));

            if ($billing !== []) {
                $bands[DashboardSnapshot::BAND_BILLING_EXCEPTION] = $billing[0];
            }

            if ($attention !== []) {
                $bands[DashboardSnapshot::BAND_ATTENTION] = $attention;
            }
        }

        // 1b — Business activity: what actually changed since this customer
        // last used this Business (§2.3). Absent on a first visit.
        try {
            $activity = $this->businessActivity($business, $context, $user, $entitlements);

            if ($activity !== null) {
                $bands[DashboardSnapshot::BAND_ACTIVITY] = $activity;
            }
        } catch (Throwable $e) {
            report($e);
            $failed[] = DashboardSnapshot::BAND_ACTIVITY;
        }

        // 2 — Recommended next steps
        if (config('opportunity.enabled', false)) {
            try {
                $recommendations = $this->recommendations($business, $context, $user, $entitlements);

                if ($recommendations !== null) {
                    $bands[DashboardSnapshot::BAND_RECOMMENDATIONS] = $recommendations;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = DashboardSnapshot::BAND_RECOMMENDATIONS;
            }
        }

        // 3 — Recent / headline figures
        if ($canSeeResults) {
            if ($comparisonFailed || $comparison === null) {
                $failed[] = DashboardSnapshot::BAND_HEADLINES;
            } else {
                try {
                    $bands[DashboardSnapshot::BAND_HEADLINES] = $this->headlines($business, $context, $user, $entitlements, $scoped, $comparison, $automationsShown);
                } catch (Throwable $e) {
                    report($e);
                    $failed[] = DashboardSnapshot::BAND_HEADLINES;
                }
            }
        }

        // 4 — Spend: gone from the Business Home entirely (§5). Balance,
        // spend, top-ups and invoices live in Settings → Billing, which is
        // unchanged; Home speaks about billing only through the exception
        // strip above.

        // 5 — Quick actions
        $bands[DashboardSnapshot::BAND_ACTIONS] = $this->actions($context, $user, $entitlements, $scoped, $status);

        return new DashboardSnapshot(
            kind: DashboardSnapshot::KIND_BUSINESS,
            frameLabel: $context->isAgency() ? 'Client account home' : 'Business home',
            heading: $candidate->name,
            bands: $bands,
            failedBands: $failed,
        );
    }

    /**
     * §5 — only what a status column proves, each with a real remediation the
     * actor can reach through the four-way rule. An item whose fix is out of
     * reach is not rendered (§5.1): restricted staff, who never see billing,
     * get no billing item rather than a dead end.
     *
     * @param  array<int, string>  $scoped
     * @return array<int, AttentionItem>
     */
    private function attention(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, string $scope, BusinessStatusRow $status, int $automationFailures): array
    {
        $types = $status->attentionTypes();

        if ($automationFailures > 0) {
            $types[] = AttentionType::AutomationFailing;
        }

        $items = [];

        foreach ($types as $type) {
            if (! $this->isActionable($type, $status)) {
                continue;
            }

            $url = $this->remediationUrl($type, $context, $user, $entitlements, $scoped);

            if ($url === null) {
                continue;
            }

            $text = $type->sentence();
            $consequence = $type->consequence();

            $items[] = new AttentionItem($type, $type->severity(), $scope, $consequence === null ? $text : $text . ' ' . $consequence, $type->actionLabel(), $url);
        }

        return self::ordered($items);
    }

    /**
     * H-1 — Home speaks about billing only where the customer actually has
     * something to do. A balance under the customer's own automatic top-up
     * threshold is the NORMAL trigger for a top-up that then happens by
     * itself, so while automatic top-up is on it is not an exception: either
     * it works, or the top-up that stopped working is itself the exception
     * (AutoRechargeFailing), which names the thing the customer can fix.
     * Every other case is a real block or a debt, and still appears.
     */
    private function isActionable(AttentionType $type, BusinessStatusRow $status): bool
    {
        if ($type !== AttentionType::LowBalance) {
            return true;
        }

        return ! $status->autoRechargeEnabled;
    }

    /** The five billing cases, which render only as the exception strip (§5.2). */
    public static function isBilling(AttentionType $type): bool
    {
        return in_array($type, [
            AttentionType::WalletSuspended,
            AttentionType::OutstandingDebt,
            AttentionType::PaidActivityPaused,
            AttentionType::LowBalance,
            AttentionType::AutoRechargeFailing,
        ], true);
    }

    /**
     * Severity first (blocking, warning, informational), then scope, then
     * the type's own declared order — deterministic, never insertion luck.
     *
     * @param  array<int, AttentionItem>  $items
     * @return array<int, AttentionItem>
     */
    public static function ordered(array $items): array
    {
        $order = array_flip(array_map(fn (AttentionType $type) => $type->value, AttentionType::cases()));

        usort($items, fn (AttentionItem $a, AttentionItem $b) => [$a->severity->rank(), $a->scope, $order[$a->type->value]]
            <=> [$b->severity->rank(), $b->scope, $order[$b->type->value]]);

        return $items;
    }

    /**
     * @param  array<int, string>  $scoped
     */
    private function remediationUrl(AttentionType $type, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped): ?string
    {
        return match ($type) {
            AttentionType::WalletSuspended, AttentionType::OutstandingDebt, AttentionType::PaidActivityPaused,
            AttentionType::LowBalance, AttentionType::AutoRechargeFailing => $context->canManageBilling()
                ? $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.usage-billing.show', $scoped, ['access_backend'])
                : null,
            AttentionType::WebsiteUnpublished => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'),
            AttentionType::GoogleConnectionLost, AttentionType::GoogleLocationUnhealthy => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.gbp.index', $scoped, ['view_google_business_profile'], 'google_business_profile_module'),
            AttentionType::AutomationFailing => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.automations.index', $scoped, ['automations'], 'automations'),
        };
    }

    /**
     * §2.3 (H-2) — "what actually changed since this customer last used this
     * Business", and nothing else.
     *
     * Every figure is a canonical count from the seam that owns it, over the
     * one instant window the visit marker chose: new contacts and received
     * messages from B5 in a single statement, new conversations from Slice
     * 2B's read model, completed and failed automation runs from the B5/V2-H
     * execution reader. Nothing here is a lead, a booking, a visitor, a
     * ranking or a rate — none of those has a canonical source, so Home never
     * claims one. Zero-value items are left out entirely; when nothing moved
     * the band says so in one quiet line.
     *
     * The band is absent on a first visit: there is no earlier point to
     * compare against, and inventing one would be a fabricated delta.
     *
     * @return array{window: HomeActivityWindow, items: array<int, array{key: string, text: string}>}|null
     */
    private function businessActivity(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements): ?array
    {
        // Looking at someone else's Business never consumes their window:
        // neither an agency viewing a client nor an impersonated session
        // (`temp_user_id`, the same marker Slice 4's parent switch reads).
        $writable = ! $context->isViewingAsClient() && ! session()->has('temp_user_id');
        $window = $this->visits->observe($business, $context->userId, $writable);

        if ($window === null) {
            return null;
        }

        $counts = $this->analyticsQueries->countsBetween($business, $window->start, $window->end);
        $items = [];

        if ($counts['newContacts'] > 0) {
            $items[] = ['key' => 'new_contacts', 'text' => self::plural($counts['newContacts'], 'new contact', 'new contacts')];
        }

        if ($entitlements->allows('conversations') && Gate::forUser($user)->allows('chat_box')) {
            $conversations = $this->conversations->startedCount($business, $window->start, $window->end);

            if ($conversations > 0) {
                $items[] = ['key' => 'new_conversations', 'text' => self::plural($conversations, 'new conversation', 'new conversations')];
            }
        }

        if ($counts['messagesReceived'] > 0) {
            $items[] = ['key' => 'messages_received', 'text' => self::plural($counts['messagesReceived'], 'message received', 'messages received')];
        }

        if ($entitlements->allows('automations') && Gate::forUser($user)->allows('automations')) {
            $automations = $this->analyticsQueries->automationCountsBetween($business, $window->start, $window->end);

            if ($automations !== null && $automations['completed'] > 0) {
                $items[] = ['key' => 'automations_completed', 'text' => self::plural($automations['completed'], 'automation completed', 'automations completed')];
            }

            if ($automations !== null && $automations['failed'] > 0) {
                $items[] = ['key' => 'automations_failed', 'text' => self::plural($automations['failed'], 'automation failed', 'automations failed')];
            }
        }

        return [
            'window' => $window,
            'items' => array_slice($items, 0, max(1, (int) config('home.activity_max_items', 5))),
        ];
    }

    /** "1 new contact" / "3 new contacts" — the figure always leads. */
    private static function plural(int $count, string $singular, string $plural): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $singular : $plural);
    }

    /**
     * §6 — AI recommendations only, never a prerequisite failure: open AND
     * current, the first five, for the selected Business. Null (the band is
     * absent) when there is nothing to recommend.
     *
     * The Advisor pages resolve the actor's own PRIMARY Business
     * (OpportunityController), so a recommendation links there only when the
     * selected Business is that one; for any other Business the band still
     * shows its recommendations, without a link that would open a different
     * Business's list.
     *
     * @return array{items: array<int, array{title: string, detected: ?string, url: ?string}>, allUrl: ?string}|null
     */
    private function recommendations(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements): ?array
    {
        // paginateForCustomer() pages from the request's `page` input; the
        // dashboard always reads the first page, so a query string can never
        // choose which recommendations render. The framework's own resolvers
        // are restored immediately afterwards.
        Paginator::currentPageResolver(static fn () => 1);

        try {
            $page = $this->opportunities->paginateForCustomer($business, [
                'status' => OpportunityStatus::Open->value,
                'freshness' => OpportunityFreshness::Current->value,
            ]);
        } finally {
            PaginationState::resolveUsing(app());
        }

        $rows = collect($page->items())->take(self::RECOMMENDATION_LIMIT);

        if ($rows->isEmpty()) {
            return null;
        }

        $candidate = $context->selectedBusiness;
        $linksToAdvisor = $candidate !== null && $candidate->customerId === $context->userId && $candidate->isPrimary;

        $items = $rows->map(fn (Opportunity $opportunity) => [
            'title' => (string) $opportunity->title,
            'detected' => $opportunity->first_detected_at?->format('M j, Y'),
            'url' => $linksToAdvisor
                ? $this->links->url($context, $user, $entitlements, 'customer.opportunities.show', [(string) $opportunity->id], ['access_backend'])
                : null,
        ])->values()->all();

        return [
            'items' => $items,
            'allUrl' => $linksToAdvisor
                ? $this->links->url($context, $user, $entitlements, 'customer.opportunities.index', [], ['access_backend'])
                : null,
        ];
    }

    /**
     * §4.1 — the restrained headline row, every figure beside its comparison
     * and an honest interpretation.
     *
     * @param  array<int, string>  $scoped
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $comparison
     * @return array{items: array<int, Headline>, currentLabel: string, previousLabel: string, resultsUrl: ?string}
     */
    private function headlines(Business $business, CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, array $comparison, bool $automationsShown): array
    {
        /** @var AnalyticsDateRange $currentRange */
        $currentRange = $comparison['current']['range'];
        /** @var AnalyticsDateRange $previousRange */
        $previousRange = $comparison['previous']['range'];
        // H-1 (§2.2, KPI priority): outbound volume, provider-accepted and
        // failed-send figures have left Home. "More messages sent" was never
        // a business outcome, and provider vocabulary belongs to Messages and
        // Results, where those figures still live in full.
        $items = [];

        $contacts = new HeadlineComparison($comparison['current']['contacts']->newInRange, $comparison['previous']['contacts']->newInRange);
        $items[] = new Headline(
            key: 'new_contacts',
            label: 'New contacts',
            figure: number_format($contacts->current),
            figureCaption: 'Added to this business in the last 30 days.',
            comparison: $contacts,
            polarity: HeadlinePolarity::DescriptiveGrowth,
            comparisonSentence: $contacts->sentence(),
            interpretation: match ($contacts->trend) {
                HeadlineTrend::Up => number_format($contacts->absoluteDelta) . ' more ' . ($contacts->absoluteDelta === 1 ? 'contact was' : 'contacts were') . ' added than in the previous 30 days.',
                HeadlineTrend::Down => number_format(abs($contacts->absoluteDelta)) . ' fewer ' . (abs($contacts->absoluteDelta) === 1 ? 'contact was' : 'contacts were') . ' added than in the previous 30 days.',
                HeadlineTrend::Unchanged => 'The same number of contacts were added as in the previous 30 days.',
            },
            judgement: null,
        );

        // Slice 2B's seam, both ranges bound exactly as AnalyticsDateRange
        // built them: startUtc/endUtc are already the storage-timezone bounds
        // localDayStartInStorageTz() produced, which is the timezone the
        // conversation rows' created_at is written in — so no second
        // conversion here (Correction 1, decision C).
        if ($entitlements->allows('conversations') && Gate::forUser($user)->allows('chat_box')) {
            $started = new HeadlineComparison(
                $this->conversations->startedCount($business, $currentRange->startUtc, $currentRange->endUtc),
                $this->conversations->startedCount($business, $previousRange->startUtc, $previousRange->endUtc),
            );

            $items[] = $this->volumeHeadline(
                'conversations_started',
                'Conversations started',
                "New conversations in this business's inbox in the last 30 days.",
                $started,
                [
                    HeadlineTrend::Up->value => 'More conversations started than in the previous 30 days.',
                    HeadlineTrend::Down->value => 'Fewer conversations started than in the previous 30 days.',
                    HeadlineTrend::Unchanged->value => 'The same number of conversations started as in the previous 30 days.',
                ],
                'Volume is activity, not a success measure.',
            );
        }

        // §4.3 — absent for BOTH periods when either is null: a zero on one
        // side would fabricate a delta.
        $automationsNow = $comparison['current']['automations'];
        $automationsBefore = $comparison['previous']['automations'];

        if ($automationsShown && $automationsNow instanceof AutomationKpis && $automationsBefore instanceof AutomationKpis) {
            $items[] = $this->volumeHeadline(
                'automation_runs',
                'Automation runs',
                'Automation runs for this business in the last 30 days.',
                new HeadlineComparison($automationsNow->executionsInRange, $automationsBefore->executionsInRange),
                [
                    HeadlineTrend::Up->value => 'Automations ran more often than in the previous 30 days.',
                    HeadlineTrend::Down->value => 'Automations ran less often than in the previous 30 days.',
                    HeadlineTrend::Unchanged->value => 'Automations ran as often as in the previous 30 days.',
                ],
                'Runs are activity, not a success measure.',
            );
        }

        return [
            'items' => $items,
            'currentLabel' => self::rangeLabel($currentRange),
            'previousLabel' => self::rangeLabel($previousRange),
            'resultsUrl' => $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.analytics.overview', $scoped, ['view_reports']),
        ];
    }

    /**
     * A volume metric: descriptive copy only, never a judgement (§4.5).
     *
     * @param  array<string, string>  $copy  trend value => sentence
     */
    private function volumeHeadline(string $key, string $label, string $caption, HeadlineComparison $comparison, array $copy, string $note): Headline
    {
        return new Headline(
            key: $key,
            label: $label,
            figure: number_format($comparison->current),
            figureCaption: $caption,
            comparison: $comparison,
            polarity: HeadlinePolarity::Descriptive,
            comparisonSentence: $comparison->sentence(),
            interpretation: $copy[$comparison->trend->value] . ' ' . $note,
            judgement: null,
        );
    }

    /**
     * §11 — at most four, each through the four-way rule, only canonical
     * Business-scoped routes. While viewing as a client nothing that costs,
     * funds, touches a provider or switches identity is offered.
     *
     * There is no "Send a message": the legacy outbound Send page is no longer
     * a customer destination (the Messages menu offers Inbox only), so Home
     * does not promote it either. After Open inbox and Add contact, the
     * remaining places go, in order, to: the team member's "Login as Parent"
     * (Correction 1, decision D), then one setup action proven by the same
     * status column that raised its attention item. Funding is not offered
     * here at all: billing is a Settings destination (H-1 §5).
     *
     * @param  array<int, string>  $scoped
     * @return array{items: array<int, DashboardAction>, parentMessage: ?string}
     */
    private function actions(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, ?BusinessStatusRow $status): array
    {
        $actions = [];

        if ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.conversations.index', $scoped, ['chat_box'], 'conversations')) {
            $actions[] = new DashboardAction('inbox', 'Open inbox', $url, 'inbox');
        }

        if ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.people.add', $scoped, DashboardLinkGate::CONTACT_PERMISSIONS)) {
            $actions[] = new DashboardAction('add_contact', 'Add contact', $url, 'user-plus');
        }

        $parent = $this->parentSwitch->for($user, $context);
        $extras = [];

        if ($parent !== null) {
            $extras[] = new DashboardAction('login_as_parent', $parent['label'], $parent['url'], 'log-in', DashboardAction::KIND_ACCOUNT);
        }

        // No "Add funds": funding is a Settings → Billing action, and Home
        // promotes it only through the exception strip, when there is a real
        // billing problem to fix (§5).

        if ($status !== null) {
            $setup = $this->setupAction($context, $user, $entitlements, $scoped, $status);

            if ($setup !== null) {
                $extras[] = $setup;
            }
        }

        foreach ($extras as $extra) {
            if (count($actions) >= self::MAX_QUICK_ACTIONS) {
                break;
            }

            $actions[] = $extra;
        }

        return [
            'items' => array_slice($actions, 0, self::MAX_QUICK_ACTIONS),
            'parentMessage' => $parent !== null && in_array('login_as_parent', array_map(fn (DashboardAction $a) => $a->key, $actions), true)
                ? $parent['message']
                : null,
        ];
    }

    /**
     * One high-value setup action, only where the status column that raises
     * the matching attention item proves it (§11). Reconnecting Google is a
     * provider action, so it is never offered while viewing as a client.
     *
     * @param  array<int, string>  $scoped
     */
    private function setupAction(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, BusinessStatusRow $status): ?DashboardAction
    {
        $types = $status->attentionTypes();

        if (in_array(AttentionType::WebsiteUnpublished, $types, true)
            && ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation'))) {
            return new DashboardAction('publish_website', 'Publish your website', $url, 'globe');
        }

        if (! $context->isViewingAsClient() && in_array(AttentionType::GoogleConnectionLost, $types, true)
            && ($url = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.gbp.index', $scoped, ['view_google_business_profile'], 'google_business_profile_module'))) {
            return new DashboardAction('reconnect_google', 'Reconnect Google', $url, 'map-pin', DashboardAction::KIND_PROVIDER);
        }

        return null;
    }

    private static function percent(float $rate): string
    {
        return number_format($rate, 1) . '%';
    }

    /** "Aug 13 – Sep 11": the range's own Business-local dates. */
    public static function rangeLabel(AnalyticsDateRange $range): string
    {
        return $range->startLocal->format('M j') . ' – ' . $range->endLocal->format('M j');
    }
}
