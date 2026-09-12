<?php

namespace App\Library\Ai\Enums;

/**
 * Unified Business Home and COO Decision Engine Contract §10.2. Every
 * first-party AI call carries exactly one category, and every category
 * draws on the same Workspace budget (D-2) — this enum is never used to
 * create a private per-feature pool.
 *
 * Live at AI-1, per the contract's own list. Reserved future categories
 * (`seo_reasoning`, `email_newsletter`, and so on) are added case by case
 * only when each feature actually ships — never speculatively.
 */
enum AiUsageCategory: string
{
    case CooDiagnosis = 'coo_diagnosis';
    case CooInteractive = 'coo_interactive';
    case ConversationCompaction = 'conversation_compaction';
    case WebsiteGeneration = 'website_generation';
    case CampaignMessageDraft = 'campaign_message_draft';
    case AgencyProspectReply = 'agency_prospect_reply';

    /**
     * Contract §19.3 rule 2 — new COO AI categories are hard-budgeted from
     * their very first production call, unconditionally, regardless of
     * `config('ai.enforce_budgets_for_existing_categories')`. Only the
     * three pre-existing categories are ever subject to that flag.
     */
    public function isAlwaysHardEnforced(): bool
    {
        return match ($this) {
            self::CooDiagnosis, self::CooInteractive, self::ConversationCompaction => true,
            self::WebsiteGeneration, self::CampaignMessageDraft, self::AgencyProspectReply => false,
        };
    }
}
