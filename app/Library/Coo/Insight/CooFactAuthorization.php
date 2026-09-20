<?php

namespace App\Library\Coo\Insight;

use App\Enums\Dashboard\AttentionType;
use App\Library\Coo\Context\CooContextEnvelope;

/**
 * Implementation Contract 19 §6.2 / R-7, sub-slice 19.B — the one place that
 * decides which COO fact sources this actor may have composed for them.
 *
 * R-7 IS THE BAR. If a fact is excluded by permission or Location, the COO does
 * not mention it, hint at it, reveal it through a count, summarise it, or
 * explain its absence. So "excluded" here does not mean zeroed, masked or
 * annotated — it means the source is NEVER QUERIED and the key is absent from
 * the facts, the fingerprint inputs and the prompt.
 *
 * WHY THE TEST IS "COVERS EVERY LOCATION" RATHER THAN A FILTER.
 * The rule this class implements is the one SeoLocationScope states: filter to
 * the actor's accessible Locations FIRST, aggregate SECOND, never the reverse.
 * The COO's activity sources cannot do that: every one of them
 * (BusinessAnalyticsQueries, BusinessConversationReadModel) aggregates by
 * `business_id` and accepts no Location set — verified against their full
 * public surface. Adding Location scoping to the analytics and conversation
 * read models is Contract 06/08B work, not this slice's.
 *
 * So rather than filter after aggregating — which would compute a number over
 * Locations the actor cannot read and then discard it, leaving it computed and
 * therefore countable — this class asks a question those sources CAN answer
 * honestly: is the actor's authorized Location set the whole Business? When it
 * is, "Business-wide" and "authorized-wide" are the same set, no inaccessible
 * Location contributes, and the aggregate is exactly the actor's own. When it
 * is not, the source is not queried at all.
 *
 * FAIL CLOSED. Business-wide safety is an allowlist, never a denylist: a fact
 * that is not named below as provably safe is excluded. A new Attention type,
 * or a new fact source, is therefore invisible to a Location-restricted actor
 * until someone classifies it deliberately —
 * CooFactAuthorizationTest::test_every_attention_type_is_explicitly_classified
 * fails until they do.
 */
final class CooFactAuthorization
{
    /**
     * Attention types whose truth value cannot be changed by a Location this
     * actor may not read. Each is raised from a column on a per-Business row;
     * the schema evidence is named so the claim is checkable, not asserted.
     *
     * @var array<int, AttentionType>
     */
    private const BUSINESS_WIDE_SAFE_ATTENTION = [
        // business_usage_wallets is one row per Business and carries no
        // location column. A wallet is funded and suspended for the Business,
        // never for one of its Locations.
        AttentionType::WalletSuspended,
        AttentionType::OutstandingDebt,
        AttentionType::PaidActivityPaused,
        AttentionType::LowBalance,
        AttentionType::AutoRechargeFailing,

        // `websites` is one row per Business with no location column: a
        // website is published for the Business as a whole.
        AttentionType::WebsiteUnpublished,

        // `business_google_connections` is the Business's own OAuth link and
        // carries no location column. Note the deliberate contrast with
        // GoogleLocationUnhealthy below, which is per-Location and excluded.
        AttentionType::GoogleConnectionLost,
    ];

    /**
     * Named, not merely absent, so the classification is a decision on the
     * record and the coverage test can prove the two lists are exhaustive.
     *
     * @var array<int, AttentionType>
     */
    private const LOCATION_BOUND_ATTENTION = [
        // Raised from Slice 2B's awaiting-reply count over `chat_boxes`, which
        // carries `location_id` and is enforced per Location by
        // ChatBoxController. A restricted actor seeing this raised would learn
        // that a conversation is waiting at a Location they may not open.
        AttentionType::ConversationsAwaitingReply,

        // Raised from `business_google_locations.business_location_id`. The
        // flag exists precisely because one Location's listing is unhealthy,
        // so showing it to an actor who cannot read that Location reveals both
        // the Location and its state.
        AttentionType::GoogleLocationUnhealthy,

        // Location-bound in SUBSTANCE, which is why the `automations` table
        // having no location column proves nothing. Every automation_executions
        // row is created with a NOT NULL `contact_id` FK
        // (AutomationExecutionClaimService::claim), and `contacts.location_id`
        // is Location-bound and enforced per Location by ContactsController.
        // A failed run therefore always belongs to a Contact that may sit at a
        // Location this actor may not read, so a raised flag would tell them
        // something is failing for customers they cannot see. This is exactly
        // the "it is only an aggregate" argument §6.2 rules out.
        AttentionType::AutomationFailing,
    ];

    private function __construct(
        public readonly bool $coversEveryLocation,
        /** @var array<int, int> */
        public readonly array $authorizedLocationIds,
        /** @var array<int, int> */
        public readonly array $everyLocationId,
    ) {
    }

    /**
     * @param  array<int, int>  $everyLocationId  every Location of the Business,
     *   read once from BusinessLocationRepository — the set the actor's
     *   authorization is compared against
     */
    public static function resolve(CooContextEnvelope $envelope, array $everyLocationId): self
    {
        $all = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $everyLocationId)));
        sort($all, SORT_NUMERIC);

        // A Business with no Locations at all has nothing Location-bound to be
        // restricted from: its Location-bound rows carry a null location, which
        // LocationAccessGuard's consumers treat as Business-wide. Covering the
        // empty set is therefore genuinely complete, not a loophole.
        $unreachable = array_diff($all, $envelope->authorizedLocationIds);

        return new self($unreachable === [], $envelope->authorizedLocationIds, $all);
    }

    /**
     * True when every Location of the Business is one this actor may read, so
     * a Business-wide aggregate is exactly the actor's own aggregate.
     *
     * Consulted BEFORE the source is queried. When it is false the query does
     * not happen, so the excluded Location is unreadable and uncountable
     * rather than read-and-discarded.
     */
    public function mayComposeLocationBoundFacts(): bool
    {
        return $this->coversEveryLocation;
    }

    /**
     * The Attention types this actor may be told about, in the order given.
     *
     * At complete Location coverage every raised type is the actor's own. At
     * partial coverage only the provably per-Business ones survive, and a type
     * that is neither listed as safe nor as Location-bound is dropped, because
     * an unclassified fact is an unproven fact.
     *
     * @param  array<int, AttentionType>  $raised
     * @return array<int, AttentionType>
     */
    public function permittedAttention(array $raised): array
    {
        if ($this->coversEveryLocation) {
            return array_values($raised);
        }

        return array_values(array_filter(
            $raised,
            static fn (AttentionType $type): bool => in_array($type, self::BUSINESS_WIDE_SAFE_ATTENTION, true),
        ));
    }

    /** @return array<int, AttentionType> */
    public static function businessWideSafeAttention(): array
    {
        return self::BUSINESS_WIDE_SAFE_ATTENTION;
    }

    /** @return array<int, AttentionType> */
    public static function locationBoundAttention(): array
    {
        return self::LOCATION_BOUND_ATTENTION;
    }
}
