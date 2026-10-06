<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Library\Growth\Rules\AutomationFailuresRule;
use App\Library\Growth\Rules\BookingRules;
use App\Library\Growth\Rules\CitationRules;
use App\Library\Growth\Rules\ConversationsAwaitingReplyRule;
use App\Library\Growth\Rules\CrmStaleOpportunitiesRule;
use App\Library\Growth\Rules\CrmUnansweredNewLeadsRule;
use App\Library\Growth\Rules\DocumentRules;
use App\Library\Growth\Rules\ReputationRules;
use App\Library\Growth\Rules\SeoRules;
use App\Library\Growth\Rules\WebsiteNotPublishedRule;
use App\Library\Growth\Rules\WebsitePackageOutOfSyncRule;

/**
 * THE closed registry of Growth rules (Growth Center §3-4).
 *
 * An explicit list, in review order — no directory scan, no class-name
 * strings, no database-defined rule. A rule exists only if it is written
 * here, and each is a pure detector over a GrowthFactSnapshot.
 *
 * It is also the single feeder of two engine registries: every rule
 * contributes an OpportunityTypeRegistry entry (so the engine's evidence and
 * copy validation covers it) and an OpportunityActionRegistry entry (a
 * non-executable navigation action). OpportunityTypeRegistry /
 * OpportunityActionRegistry read it through typeDefinitionsFor() /
 * actionDefinitions(), which keeps their own public API unchanged.
 *
 * DEFERRED (no canonical data — see the V1 doc, "Rules deferred"):
 *   seo.rank_just_outside_top_10       no rank observations
 *   seo.meaningful_rank_drop           no rank observations
 *   ads.zero_conversion_spend          no Google Ads module
 *   ads.cpl_above_target               no Google Ads module
 *   ads.budget_over_pacing             no Google Ads module
 *   ads.search_term_waste              no Google Ads module
 */
final class GrowthRuleRegistry
{
    /** @var array<string, GrowthRule>|null */
    private static ?array $rules = null;

    /** @return array<string, GrowthRule> keyed by rule key, in registry order */
    public static function all(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $list = [
            // Lead response
            new CrmUnansweredNewLeadsRule(),
            new ConversationsAwaitingReplyRule(),
            // Sales pipeline
            new CrmStaleOpportunitiesRule(),
            CrmStaleOpportunitiesRule::highValue(),
            // Proposals / payments
            new DocumentRules(DocumentRules::UNSIGNED),
            new DocumentRules(DocumentRules::SIGNED_UNPAID),
            new DocumentRules(DocumentRules::OVERDUE),
            new DocumentRules(DocumentRules::FAILED),
            // Bookings
            new BookingRules(),
            BookingRules::lowAvailability(),
            // Website / SEO / local presence
            new WebsiteNotPublishedRule(),
            new WebsitePackageOutOfSyncRule(),
            SeoRules::technical(),
            new SeoRules(),
            \App\Library\Growth\Rules\ContentRules::topicsNotCovered(),
            \App\Library\Growth\Rules\ContentRules::nearPageOne(),
            \App\Library\Growth\Rules\ContentRules::rankDeclined(),
            \App\Library\Growth\Rules\ContentRules::stale(),
            new CitationRules(),
            CitationRules::notChecked(),
            // Reviews
            new ReputationRules(),
            ReputationRules::noRecentRequests(),
            // Automations
            new AutomationFailuresRule(),
        ];

        $rules = [];

        foreach ($list as $rule) {
            $key = $rule->definition()->key;

            if (isset($rules[$key])) {
                throw new \LogicException("Duplicate Growth rule key [{$key}].");
            }

            $rules[$key] = $rule;
        }

        return self::$rules = $rules;
    }

    public static function find(string $key): ?GrowthRule
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, GrowthRule> the rules a given engine worker owns */
    public static function forWorker(string $workerKey): array
    {
        return array_filter(self::all(), fn (GrowthRule $r) => $r->definition()->worker->value === $workerKey);
    }

    /** @return array<string, array<string, mixed>> type key => OpportunityTypeRegistry definition */
    public static function typeDefinitionsFor(string $workerKey): array
    {
        $out = [];

        foreach (self::forWorker($workerKey) as $key => $rule) {
            $out[$key] = $rule->definition()->toTypeDefinition();
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> action key => OpportunityActionRegistry definition */
    public static function actionDefinitions(): array
    {
        $out = [];

        foreach (self::all() as $rule) {
            $definition = $rule->definition();
            $out[$definition->actionKey] = $definition->toActionDefinition();
        }

        return $out;
    }

    /** @return array<int, string> the worker keys that own at least one rule */
    public static function workerKeys(): array
    {
        return array_values(array_unique(array_map(
            fn (GrowthRule $r) => $r->definition()->worker->value,
            self::all(),
        )));
    }
}
