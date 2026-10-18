<?php

namespace App\Library\Seo\Content\Autopilot;

use App\Library\NicheBlueprint\Adapters\SeoStrategyComponentAdapter;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;

/**
 * Content Autopilot - what the niche allows. ONE universal engine; the Niche Blueprint's `seo_strategy.content_policy`
 * (read live through BlueprintConfigReader) configures it per niche, so no niche ever gets its own code path.
 *
 * FAIL CLOSED: a Business with no Blueprint, no `content_policy`, or an unreadable one has risk tier `unspecified` and
 * `approval_required` - Autopilot may still draft and queue for the owner, but it never publishes such an article on its
 * own. Only an explicit `standard` tier with `auto_publish: allowed` is ever eligible, and even then the first articles of
 * every Business wait for approval (the trust ramp, applied by the scheduler).
 */
final class ContentPolicy
{
    /** Articles Autopilot publishes without the owner only after this many have already been approved/published. */
    public const TRUST_RAMP_ARTICLES = 2;

    public function __construct(private readonly BlueprintConfigReader $blueprint)
    {
    }

    /** @return array{risk_tier: string, auto_publish: string, prohibited_phrases: list<string>, preferred_terms: list<string>} */
    public function forBusiness(Business $business): array
    {
        return SeoStrategyComponentAdapter::contentPolicy($this->blueprint->seoStrategy($business));
    }

    /** Is this niche, by its own declared policy, eligible for automatic publishing at all? */
    public function autoPublishAllowed(Business $business): bool
    {
        $policy = $this->forBusiness($business);

        return $policy['risk_tier'] === 'standard' && $policy['auto_publish'] === 'allowed';
    }

    /**
     * Everything an article about this Business must never say: the niche's prohibited phrases plus the claims the owner
     * listed in the Business Profile. Matched case-insensitively as whole phrases by the validator.
     *
     * @return list<string>
     */
    public function prohibitedPhrases(Business $business): array
    {
        $owner = (array) (BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first()?->prohibited_claims ?? []);

        $all = array_merge($this->forBusiness($business)['prohibited_phrases'], array_values(array_filter($owner, 'is_string')));
        $seen = [];
        $out = [];

        foreach ($all as $phrase) {
            $phrase = trim($phrase);
            $key = mb_strtolower($phrase);

            if ($phrase !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $phrase;
            }
        }

        return $out;
    }

    /**
     * Is a topic in season? A topic with no `months` is evergreen (always true). The window opens $leadMonths before the
     * first useful month so an article is live when searches begin; months wrap around the year end.
     *
     * @param  list<int>  $months
     */
    public function inSeason(array $months, int $currentMonth, int $leadMonths = 2): bool
    {
        if ($months === []) {
            return true;
        }

        foreach ($months as $month) {
            for ($ahead = 0; $ahead <= $leadMonths; $ahead++) {
                if ((($currentMonth - 1 + $ahead) % 12) + 1 === $month) {
                    return true;
                }
            }
        }

        return false;
    }
}
