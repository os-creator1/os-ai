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
    case CooMoveExplanation = 'coo_move_explanation';
    case ConversationCompaction = 'conversation_compaction';
    case WebsiteGeneration = 'website_generation';
    case CampaignMessageDraft = 'campaign_message_draft';
    case AgencyProspectReply = 'agency_prospect_reply';

    /**
     * Content Autopilot — everything the SEO Content Autopilot asks a model (brief assist, article draft and
     * rewrite, soft-finding judge). Hard-budgeted from its first call, and additionally capped per Business per
     * period by `config('ai.business_category_ceilings.content_autopilot')` (AiBusinessCategoryCeiling).
     */
    case ContentAutopilot = 'content_autopilot';

    /**
     * Contract §19.3 rule 2 — new COO AI categories are hard-budgeted from
     * their very first production call, unconditionally, regardless of
     * `config('ai.enforce_budgets_for_existing_categories')`. Only the
     * three pre-existing categories are ever subject to that flag.
     */
    public function isAlwaysHardEnforced(): bool
    {
        return match ($this) {
            self::CooDiagnosis, self::CooInteractive, self::CooMoveExplanation, self::ConversationCompaction, self::ContentAutopilot => true,
            self::WebsiteGeneration, self::CampaignMessageDraft, self::AgencyProspectReply => false,
        };
    }

    /**
     * Correction 1 (§8.1, §10.1 step 1) — the COO categories are a product
     * the Business must be entitled to (`ai_coo_basic`). The three that
     * pre-date the gateway are deliberately absent: they shipped without
     * that feature, and putting them behind it now would take working
     * product away from customers who already have it.
     */
    public function requiresCooEntitlement(): bool
    {
        return match ($this) {
            self::CooDiagnosis, self::CooInteractive, self::CooMoveExplanation, self::ConversationCompaction => true,
            self::WebsiteGeneration, self::CampaignMessageDraft, self::AgencyProspectReply, self::ContentAutopilot => false,
        };
    }

    /**
     * Correction 2 (§8.1, C-8) — which categories a dormant Business
     * refuses. Scheduled COO work on a Business nobody is using costs real
     * money for nothing, so it is gated.
     *
     * The three pre-existing categories are NOT gated: each one is a
     * customer action happening right now (generating a website, drafting
     * a campaign, answering a prospect who just replied), which is itself
     * the activity dormancy is looking for. `coo_interactive` is likewise
     * exempt — an explicit request is never refused for dormancy alone —
     * and the gateway additionally only applies this to the product lane.
     */
    public function isDormancyGated(): bool
    {
        return match ($this) {
            self::CooDiagnosis, self::CooMoveExplanation, self::ConversationCompaction => true,
            self::CooInteractive, self::WebsiteGeneration, self::CampaignMessageDraft, self::AgencyProspectReply, self::ContentAutopilot => false,
        };
    }
}
