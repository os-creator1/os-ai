<?php

namespace App\Library\Opportunity;

use App\Enums\Business\BusinessGoal;
use App\Enums\Opportunity\OpportunityWorkerKey;

/**
 * Closed, source-controlled Opportunity type definitions (RFC-002 §13.2).
 * Maps (worker_key, type) to trusted content and validation metadata — this
 * is what makes "unsupported narrative claims are rejected" enforceable.
 *
 * Only business_advisor's 11 RFC-001-derived types are defined, locked
 * exactly per RFC-002 §39. A worker/AI candidate may only ever supply the
 * fact data behind a type's evidence and (where a template accepts them)
 * validated template parameters — never final title/summary/evidence-summary
 * text, and never a type outside this registry.
 *
 * 'context_validator' is null for every type below: every business_advisor
 * type fires at most once per Business, so context is always null. This is
 * approved, trusted static metadata (RFC-002 §26) — not a dynamically
 * supplied class name, and not a worker-, request-, database-, environment-,
 * or AI-defined value.
 */
final class OpportunityTypeRegistry
{
    private const DEFINITIONS = [
        'missing_phone' => [
            'title_template' => 'Add your business phone number',
            'summary_template' => 'Customers and future platform modules need a reliable way to contact the business.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['phone_blank'],
            'required_evidence_fact_keys' => ['phone_blank'],
            'evidence_summary_templates' => [
                'phone_blank' => 'The business phone number is not set.',
            ],
            'action_key' => 'add_phone',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_email' => [
            'title_template' => 'Add a public business email',
            'summary_template' => 'A public email gives customers and platform tools another verified way to reach you.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['email_blank'],
            'required_evidence_fact_keys' => ['email_blank'],
            'evidence_summary_templates' => [
                'email_blank' => 'The business email address is not set.',
            ],
            'action_key' => 'add_email',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_website' => [
            'title_template' => 'Add your website URL',
            'summary_template' => 'A website URL lets customers and future SEO tools find and verify your business online.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['website_url_blank'],
            'required_evidence_fact_keys' => ['website_url_blank'],
            'evidence_summary_templates' => [
                'website_url_blank' => 'The business website URL is not set.',
            ],
            'action_key' => 'add_website',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [
                BusinessGoal::LocalSeo->value,
                BusinessGoal::WebsiteConversion->value,
            ],
        ],
        'missing_description' => [
            'title_template' => 'Write a business description of at least 50 characters',
            'summary_template' => 'A short description helps customers and future AI tools understand what your business actually does.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['description_too_short'],
            'required_evidence_fact_keys' => ['description_too_short'],
            'evidence_summary_templates' => [
                'description_too_short' => 'The business description is missing or shorter than 50 characters.',
            ],
            'action_key' => 'add_description',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_primary_location' => [
            'title_template' => 'Add your primary location or service area',
            'summary_template' => 'A primary location is required before local customers or location-based tools can find you.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['primary_location_missing'],
            'required_evidence_fact_keys' => ['primary_location_missing'],
            'evidence_summary_templates' => [
                'primary_location_missing' => 'The business has no primary location on file.',
            ],
            'action_key' => 'add_location',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'incomplete_primary_location' => [
            'title_template' => 'Finish your primary location details',
            'summary_template' => 'Your location is missing fields required for its service mode, so it cannot be used reliably yet.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['primary_location_incomplete'],
            'required_evidence_fact_keys' => ['primary_location_incomplete'],
            'evidence_summary_templates' => [
                'primary_location_incomplete' => 'The primary location is missing fields required for its service mode.',
            ],
            'action_key' => 'complete_location',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_services' => [
            'title_template' => 'Add at least one service',
            'summary_template' => 'At least one active service is required so customers know what you offer.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['active_services_missing'],
            'required_evidence_fact_keys' => ['active_services_missing'],
            'evidence_summary_templates' => [
                'active_services_missing' => 'The business has no active services on file.',
            ],
            'action_key' => 'add_service',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_primary_service' => [
            'title_template' => 'Choose a primary service',
            'summary_template' => 'You have services listed but none marked as primary, so it is unclear what to lead with.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['primary_service_missing'],
            'required_evidence_fact_keys' => ['primary_service_missing'],
            'evidence_summary_templates' => [
                'primary_service_missing' => 'The business has active services but none marked as primary.',
            ],
            'action_key' => 'confirm_primary_service',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_gbp_url' => [
            'title_template' => 'Add your Google Business Profile URL',
            'summary_template' => 'A Google Business Profile link helps customers find and verify your business locally.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['gbp_url_blank'],
            'required_evidence_fact_keys' => ['gbp_url_blank'],
            'evidence_summary_templates' => [
                'gbp_url_blank' => 'The Google Business Profile URL is not set.',
            ],
            'action_key' => 'add_gbp_url',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [
                BusinessGoal::LocalSeo->value,
                BusinessGoal::Reputation->value,
            ],
        ],
        'missing_facebook_url' => [
            'title_template' => 'Add your Facebook page URL',
            'summary_template' => 'A Facebook link gives customers another verified way to find your business.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['facebook_url_blank'],
            'required_evidence_fact_keys' => ['facebook_url_blank'],
            'evidence_summary_templates' => [
                'facebook_url_blank' => 'The Facebook page URL is not set.',
            ],
            'action_key' => 'add_facebook_url',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
        'missing_instagram_url' => [
            'title_template' => 'Add your Instagram profile URL',
            'summary_template' => 'Instagram is a common discovery channel for event and photo-based businesses like yours.',
            'allowed_evidence_source_types' => ['business_profile'],
            'allowed_evidence_fact_keys' => ['instagram_url_blank'],
            'required_evidence_fact_keys' => ['instagram_url_blank'],
            'evidence_summary_templates' => [
                'instagram_url_blank' => 'The Instagram profile URL is not set.',
            ],
            'action_key' => 'add_instagram_url',
            'context_validator' => null,
            'allowed_relevant_goal_keys' => [],
        ],
    ];

    public static function has(string $workerKey, string $type): bool
    {
        return $workerKey === OpportunityWorkerKey::BusinessAdvisor->value
            && array_key_exists($type, self::DEFINITIONS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $workerKey, string $type): ?array
    {
        if (! self::has($workerKey, $type)) {
            return null;
        }

        return self::DEFINITIONS[$type];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forWorker(string $workerKey): array
    {
        return $workerKey === OpportunityWorkerKey::BusinessAdvisor->value
            ? self::DEFINITIONS
            : [];
    }
}
