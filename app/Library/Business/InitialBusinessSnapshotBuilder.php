<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessGoal;
use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Deterministic, local-data-only profile-completeness analysis (RFC-001 §11.4, §12).
 * Reads only the given Business, its primary BusinessLocation, its active
 * BusinessServices, and the caller-supplied primary goals. No remote requests, no
 * AI, no claims about anything not directly proven by stored data (AD-006).
 */
class InitialBusinessSnapshotBuilder
{
    private const MAX_FINDINGS = 5;

    /** RFC-001 §12 — sums to 100. */
    private const WEIGHTS = [
        'has_name' => 10,
        'has_industry' => 10,
        'has_email' => 8,
        'has_phone' => 10,
        'has_website_url' => 10,
        'has_description' => 8,
        'has_country_and_timezone' => 8,
        'has_primary_location' => 12,
        'has_complete_primary_location' => 8,
        'has_active_service' => 8,
        'has_active_primary_service' => 8,
    ];

    private const IMPACT_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    private const EFFORT_ORDER = ['low' => 0, 'medium' => 1, 'high' => 2];

    /**
     * @param  array<int, string>  $primaryGoals  BusinessGoal values from CustomerOnboarding.
     */
    public function build(Business $business, array $primaryGoals = []): array
    {
        $location = $business->primaryLocation;
        $activeServices = $business->services
            ->filter(fn (BusinessService $service) => $service->status === BusinessServiceStatus::Active)
            ->values();
        $primaryService = $business->primaryService;

        $facts = $this->collectFacts($business, $location, $activeServices, $primaryService);

        return [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'profile_completeness_percent' => $this->score($facts),
            'facts' => $facts,
            'findings' => $this->buildFindings($business, $location, $activeServices, $primaryService, $primaryGoals),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function collectFacts(Business $business, ?BusinessLocation $location, Collection $activeServices, ?BusinessService $primaryService): array
    {
        return [
            'has_name' => filled($business->name),
            'has_industry' => $business->industry !== null,
            'has_email' => filled($business->email),
            'has_phone' => filled($business->phone),
            'has_website_url' => filled($business->website_url),
            'has_description' => mb_strlen(trim((string) $business->description)) >= 50,
            'has_country_and_timezone' => filled($business->country_code) && filled($business->timezone),
            'has_primary_location' => $location !== null,
            'has_complete_primary_location' => $location !== null && $this->locationMeetsServiceModeRequirements($location),
            'has_active_service' => $activeServices->isNotEmpty(),
            'has_active_primary_service' => $primaryService !== null,
        ];
    }

    /**
     * The complete, uncapped RFC-002 Opportunity-candidate condition set —
     * every business_advisor evidence fact_key mapped to whether it
     * currently holds true for this Business. Unlike build()'s `findings`
     * (impact-sorted and capped at MAX_FINDINGS for the onboarding results
     * UI), this reports all eleven conditions with no truncation, and is
     * the single authoritative implementation buildFindings() itself reads
     * from below — never recomputed twice.
     *
     * @return array<string, bool>
     */
    public function opportunityFacts(Business $business): array
    {
        $location = $business->primaryLocation;
        $activeServices = $business->services
            ->filter(fn (BusinessService $service) => $service->status === BusinessServiceStatus::Active)
            ->values();
        $primaryService = $business->primaryService;

        return $this->collectOpportunityFacts($business, $location, $activeServices, $primaryService);
    }

    /**
     * @return array<string, bool>
     */
    private function collectOpportunityFacts(Business $business, ?BusinessLocation $location, Collection $activeServices, ?BusinessService $primaryService): array
    {
        $instagramIndustries = [BusinessIndustry::EventServices, BusinessIndustry::Photographer, BusinessIndustry::WeddingVendor];

        return [
            'phone_blank' => blank($business->phone),
            'email_blank' => blank($business->email),
            'website_url_blank' => blank($business->website_url),
            'description_too_short' => mb_strlen(trim((string) $business->description)) < 50,
            'primary_location_missing' => $location === null,
            'primary_location_incomplete' => $location !== null && ! $this->locationMeetsServiceModeRequirements($location),
            'active_services_missing' => $activeServices->isEmpty(),
            'primary_service_missing' => $activeServices->isNotEmpty() && $primaryService === null,
            'gbp_url_blank' => blank($business->google_business_profile_url),
            'facebook_url_blank' => blank($business->facebook_url),
            'instagram_url_blank' => blank($business->instagram_url) && in_array($business->industry, $instagramIndustries, true),
        ];
    }

    private function score(array $facts): int
    {
        $total = 0;

        foreach (self::WEIGHTS as $key => $weight) {
            if (! empty($facts[$key])) {
                $total += $weight;
            }
        }

        return (int) round($total);
    }

    /**
     * Mirrors the conditional location requirements from
     * UpsertBusinessLocationRequest (RFC-001 §17) at the data layer.
     */
    private function locationMeetsServiceModeRequirements(BusinessLocation $location): bool
    {
        return match ($location->service_mode) {
            BusinessServiceMode::Storefront, BusinessServiceMode::Hybrid => filled($location->address_line_1)
                && filled($location->city) && filled($location->region) && filled($location->country_code),
            BusinessServiceMode::ServiceArea => filled($location->city) && filled($location->region) && filled($location->country_code),
            BusinessServiceMode::Online => filled($location->country_code),
        };
    }

    /**
     * @param  array<int, string>  $primaryGoals
     * @return array<int, array<string, mixed>>
     */
    private function buildFindings(Business $business, ?BusinessLocation $location, Collection $activeServices, ?BusinessService $primaryService, array $primaryGoals): array
    {
        $facts = $this->collectOpportunityFacts($business, $location, $activeServices, $primaryService);
        $candidates = [];

        if ($facts['phone_blank']) {
            $candidates[] = $this->finding(
                $business,
                'missing_phone',
                'Add your business phone number',
                'Customers and future platform modules need a reliable way to contact the business.',
                'medium',
                'low',
                'add_phone',
                'business',
                false
            );
        }

        if ($facts['email_blank']) {
            $candidates[] = $this->finding(
                $business,
                'missing_email',
                'Add a public business email',
                'A public email gives customers and platform tools another verified way to reach you.',
                'low',
                'low',
                'add_email',
                'business',
                false
            );
        }

        if ($facts['website_url_blank']) {
            $websiteGoalRelevant = $this->goalsInclude($primaryGoals, [BusinessGoal::LocalSeo, BusinessGoal::WebsiteConversion]);
            $candidates[] = $this->finding(
                $business,
                'missing_website',
                'Add your website URL',
                'A website URL lets customers and future SEO tools find and verify your business online.',
                $websiteGoalRelevant ? 'high' : 'medium',
                'medium',
                'add_website',
                'business',
                $websiteGoalRelevant
            );
        }

        if ($facts['description_too_short']) {
            $candidates[] = $this->finding(
                $business,
                'missing_description',
                'Write a business description of at least 50 characters',
                'A short description helps customers and future AI tools understand what your business actually does.',
                'medium',
                'low',
                'add_description',
                'business',
                false
            );
        }

        if ($facts['primary_location_missing']) {
            $candidates[] = $this->finding(
                $business,
                'missing_primary_location',
                'Add your primary location or service area',
                'A primary location is required before local customers or location-based tools can find you.',
                'high',
                'medium',
                'add_location',
                'location',
                false
            );
        } elseif ($facts['primary_location_incomplete']) {
            $candidates[] = $this->finding(
                $business,
                'incomplete_primary_location',
                'Finish your primary location details',
                'Your location is missing fields required for its service mode, so it cannot be used reliably yet.',
                'high',
                'medium',
                'complete_location',
                'location',
                false
            );
        }

        if ($facts['active_services_missing']) {
            $candidates[] = $this->finding(
                $business,
                'missing_services',
                'Add at least one service',
                'At least one active service is required so customers know what you offer.',
                'high',
                'medium',
                'add_service',
                'services',
                false
            );
        } elseif ($facts['primary_service_missing']) {
            $candidates[] = $this->finding(
                $business,
                'missing_primary_service',
                'Choose a primary service',
                'You have services listed but none marked as primary, so it is unclear what to lead with.',
                'high',
                'low',
                'confirm_primary_service',
                'services',
                false
            );
        }

        if ($facts['gbp_url_blank']) {
            // The missing field alone is what proves this finding — goal selection
            // may only raise its impact/relevance, never suppress it (AD-006).
            $gbpGoalRelevant = $this->goalsInclude($primaryGoals, [BusinessGoal::LocalSeo, BusinessGoal::Reputation]);
            $candidates[] = $this->finding(
                $business,
                'missing_gbp_url',
                'Add your Google Business Profile URL',
                $gbpGoalRelevant
                    ? 'A Google Business Profile link supports the local visibility goal you selected.'
                    : 'A Google Business Profile link helps customers find and verify your business locally.',
                $gbpGoalRelevant ? 'high' : 'medium',
                'low',
                'add_gbp_url',
                'assets',
                $gbpGoalRelevant
            );
        }

        if ($facts['facebook_url_blank']) {
            $candidates[] = $this->finding(
                $business,
                'missing_facebook_url',
                'Add your Facebook page URL',
                'A Facebook link gives customers another verified way to find your business.',
                'low',
                'low',
                'add_facebook_url',
                'assets',
                false
            );
        }

        if ($facts['instagram_url_blank']) {
            $candidates[] = $this->finding(
                $business,
                'missing_instagram_url',
                'Add your Instagram profile URL',
                'Instagram is a common discovery channel for event and photo-based businesses like yours.',
                'low',
                'low',
                'add_instagram_url',
                'assets',
                false
            );
        }

        return $this->sortAndLimit($candidates);
    }

    /**
     * @param  array<int, string>  $primaryGoals
     * @param  array<int, BusinessGoal>  $relevantGoals
     */
    private function goalsInclude(array $primaryGoals, array $relevantGoals): bool
    {
        $relevantValues = array_map(fn (BusinessGoal $goal) => $goal->value, $relevantGoals);

        return array_intersect($primaryGoals, $relevantValues) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(
        Business $business,
        string $suffix,
        string $title,
        string $reason,
        string $impact,
        string $effort,
        string $actionKey,
        string $actionStep,
        bool $goalRelevant
    ): array {
        return [
            'fingerprint' => "business:{$business->id}:{$suffix}",
            'title' => $title,
            'reason' => $reason,
            'impact' => $impact,
            'effort' => $effort,
            'confidence' => 1.0,
            'worker_key' => 'business_advisor',
            'can_ai_prepare' => false,
            'action_key' => $actionKey,
            'action_step' => $actionStep,
            '_goal_relevant' => $goalRelevant,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function sortAndLimit(array $findings): array
    {
        usort($findings, function (array $a, array $b) {
            return [
                self::IMPACT_ORDER[$a['impact']],
                $a['_goal_relevant'] ? 0 : 1,
                self::EFFORT_ORDER[$a['effort']],
                $a['fingerprint'],
            ] <=> [
                self::IMPACT_ORDER[$b['impact']],
                $b['_goal_relevant'] ? 0 : 1,
                self::EFFORT_ORDER[$b['effort']],
                $b['fingerprint'],
            ];
        });

        return array_values(array_map(
            fn (array $finding) => Arr::except($finding, ['_goal_relevant']),
            array_slice($findings, 0, self::MAX_FINDINGS)
        ));
    }
}
