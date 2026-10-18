<?php

namespace App\Library\Ads\Decisions;

/**
 * Where a decision's ONE call to action goes. Every kind is an INTERNAL
 * MotionGrove destination (a provider screen, the CRM pipeline, the Goals
 * page, the Website module). There is deliberately no "apply fix" kind: this
 * slice creates and edits no campaign, so a button that pretends to would be
 * fake. Where the owner must act in the provider itself, the decision text
 * says exactly what to do.
 */
enum AdsDecisionCtaKind: string
{
    case OpenCampaign = 'open_campaign';
    case ReviewSearchTerms = 'review_search_terms';
    case ReviewAd = 'review_ad';
    case OpenPipeline = 'open_pipeline';
    case ReviewLandingPage = 'review_landing_page';
    case FixTracking = 'fix_tracking';
    case AssignGoal = 'assign_goal';
    case SetTargets = 'set_targets';
    case FinishGoalSetup = 'finish_goal_setup';
}
