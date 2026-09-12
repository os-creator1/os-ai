<?php

/**
 * Unified Business Home and COO Decision Engine Contract §10, §11, §13
 * (slice AI-1) — the single policy seam for internal AI provider-cost
 * budgets and model routing. `App\Library\Ai\AiBudgetPolicyResolver` is
 * the only reader of `budgets`; `App\Library\Ai\AiModelRouter` is the
 * only reader of `routes` and `category_routes`. No amount or model name
 * belongs anywhere else in the codebase — that is what
 * T-BUD-7/T-ROUTE-1's architecture tests assert.
 *
 * All money amounts are integer micro-USD (1 USD = 1_000_000). No floats.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Budgets (contract §11.1, L-11)
    |--------------------------------------------------------------------------
    |
    | One row per plan policy. `workspace_cap_microusd` is the Workspace-
    | wide monthly (or whole-trial) hard cap. `business_cap_microusd` is
    | null when there is no separate per-Business sub-cap (Core/Growth: the
    | Business cap equals the Workspace cap, i.e. no extra constraint).
    | Agency has both, and whichever is reached first wins (§10.3a).
    |
    | Changing an amount bumps `policy_version` — a period already opened
    | keeps the cap it snapshotted (§11.1).
    |
    */
    'policy_version' => (int) env('AI_BUDGET_POLICY_VERSION', 1),

    'budgets' => [
        'trial' => [
            'workspace_cap_microusd' => (int) env('AI_BUDGET_TRIAL_WORKSPACE_CAP_MICROUSD', 1_500_000),
            'business_cap_microusd' => null,
            'interactive_share_bps' => (int) env('AI_BUDGET_TRIAL_INTERACTIVE_SHARE_BPS', 3000),
            // The whole 28-day trial is one period, never a calendar month.
            'period' => 'trial',
        ],
        'core' => [
            'workspace_cap_microusd' => (int) env('AI_BUDGET_CORE_WORKSPACE_CAP_MICROUSD', 5_000_000),
            'business_cap_microusd' => null,
            'interactive_share_bps' => (int) env('AI_BUDGET_CORE_INTERACTIVE_SHARE_BPS', 3000),
            'period' => 'calendar_month',
        ],
        'growth' => [
            'workspace_cap_microusd' => (int) env('AI_BUDGET_GROWTH_WORKSPACE_CAP_MICROUSD', 10_000_000),
            'business_cap_microusd' => null,
            'interactive_share_bps' => (int) env('AI_BUDGET_GROWTH_INTERACTIVE_SHARE_BPS', 3000),
            'period' => 'calendar_month',
        ],
        'agency' => [
            'workspace_cap_microusd' => (int) env('AI_BUDGET_AGENCY_WORKSPACE_CAP_MICROUSD', 25_000_000),
            'business_cap_microusd' => (int) env('AI_BUDGET_AGENCY_BUSINESS_CAP_MICROUSD', 6_000_000),
            'interactive_share_bps' => (int) env('AI_BUDGET_AGENCY_INTERACTIVE_SHARE_BPS', 3000),
            'period' => 'calendar_month',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservation lifecycle (contract §10.1 steps 4 and 7)
    |--------------------------------------------------------------------------
    */
    'reservation_expiry_minutes' => (int) env('AI_RESERVATION_EXPIRY_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Existing-category observation mode (contract §19.3, D-6)
    |--------------------------------------------------------------------------
    |
    | While false, the three pre-existing categories (website_generation,
    | campaign_message_draft, agency_prospect_reply) are still gated,
    | reserved, called, and recorded — but never refused solely for
    | `budget_exhausted`. New COO categories (coo_diagnosis, coo_interactive,
    | conversation_compaction) are ALWAYS hard-enforced regardless of this
    | flag (contract §19.3 rule 2) — see AiUsageCategory::isHardEnforced().
    |
    | This must be true before broader product launch (§21 launch gate).
    | AI-1 ships it false; a later slice flips it after one observation
    | window of real ledger data.
    |
    */
    'enforce_budgets_for_existing_categories' => (bool) env('AI_ENFORCE_BUDGETS_FOR_EXISTING_CATEGORIES', false),

    /*
    |--------------------------------------------------------------------------
    | Estimator (§10.1 step 3)
    |--------------------------------------------------------------------------
    |
    | The deterministic upper bound a reservation is taken at. A chat request
    | is a sequence of messages, not a string: the provider tokenises each
    | message's role and its structural delimiters as well as its content, so
    | many short messages cost far more than their characters suggest. Each
    | message therefore carries a framing allowance and the request carries
    | one more for reply priming.
    |
    | Deliberately generous. An over-reservation is released the instant the
    | provider reports real usage; an under-reservation is a cap breach that
    | cannot be taken back.
    |
    */
    'estimator' => [
        'chars_per_token' => (int) env('AI_ESTIMATOR_CHARS_PER_TOKEN', 3),
        'per_message_framing_tokens' => (int) env('AI_ESTIMATOR_PER_MESSAGE_FRAMING_TOKENS', 8),
        'per_request_framing_tokens' => (int) env('AI_ESTIMATOR_PER_REQUEST_FRAMING_TOKENS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model routing (contract §13, D-4)
    |--------------------------------------------------------------------------
    |
    | Domain code asks for a logical route (routine|reasoning|compaction)
    | and never names a model. Concrete provider/model choice, and the
    | per-token price used to compute cost, live here ONLY. Changing a
    | model is a config edit — no domain code, no schema, no migration.
    |
    | `price_version` must be bumped whenever a route's prices or model
    | change, so ledger rows already committed keep the price version they
    | were charged under (§10.1 step 6) — old entries are never
    | retroactively recomputed.
    |
    | Chosen defaults (evaluated against the existing OpenAI Chat
    | Completions integration, D-4):
    |   - routine / compaction: gpt-4o-mini — the cheapest current OpenAI
    |     chat model that reliably produces structured JSON output and
    |     short drafts/replies, which is all these routes are asked to do
    |     (website section drafts, campaign message drafts, prospecting
    |     replies, conversation summarization). At $0.15 / $0.60 per
    |     million input/output tokens, this keeps a Core Workspace's whole
    |     $5 monthly cap good for thousands of typical calls.
    |   - reasoning: gpt-4o — the strongest model already integrated,
    |     reached only when a deterministic rule escalates the case (E-3
    |     with >=3 material signals and 3x cost headroom, contract §11.2).
    |     Its higher $2.50 / $10.00 per-million price is exactly why it is
    |     never the default route.
    |
    */
    'routes' => [
        'routine' => [
            'provider' => 'openai',
            'model' => env('AI_ROUTE_ROUTINE_MODEL', 'gpt-4o-mini'),
            'input_price_microusd_per_mtok' => (int) env('AI_ROUTE_ROUTINE_INPUT_PRICE_MICROUSD_PER_MTOK', 150_000),
            'cached_input_price_microusd_per_mtok' => (int) env('AI_ROUTE_ROUTINE_CACHED_INPUT_PRICE_MICROUSD_PER_MTOK', 75_000),
            'output_price_microusd_per_mtok' => (int) env('AI_ROUTE_ROUTINE_OUTPUT_PRICE_MICROUSD_PER_MTOK', 600_000),
            'max_input_tokens' => (int) env('AI_ROUTE_ROUTINE_MAX_INPUT_TOKENS', 4000),
            'max_output_tokens' => (int) env('AI_ROUTE_ROUTINE_MAX_OUTPUT_TOKENS', 800),
            'max_request_cost_microusd' => (int) env('AI_ROUTE_ROUTINE_MAX_REQUEST_COST_MICROUSD', 50_000),
            'price_version' => (int) env('AI_ROUTE_ROUTINE_PRICE_VERSION', 1),
        ],
        'compaction' => [
            'provider' => 'openai',
            'model' => env('AI_ROUTE_COMPACTION_MODEL', 'gpt-4o-mini'),
            'input_price_microusd_per_mtok' => (int) env('AI_ROUTE_COMPACTION_INPUT_PRICE_MICROUSD_PER_MTOK', 150_000),
            'cached_input_price_microusd_per_mtok' => (int) env('AI_ROUTE_COMPACTION_CACHED_INPUT_PRICE_MICROUSD_PER_MTOK', 75_000),
            'output_price_microusd_per_mtok' => (int) env('AI_ROUTE_COMPACTION_OUTPUT_PRICE_MICROUSD_PER_MTOK', 600_000),
            'max_input_tokens' => (int) env('AI_ROUTE_COMPACTION_MAX_INPUT_TOKENS', 8000),
            'max_output_tokens' => (int) env('AI_ROUTE_COMPACTION_MAX_OUTPUT_TOKENS', 500),
            'max_request_cost_microusd' => (int) env('AI_ROUTE_COMPACTION_MAX_REQUEST_COST_MICROUSD', 50_000),
            'price_version' => (int) env('AI_ROUTE_COMPACTION_PRICE_VERSION', 1),
        ],
        'reasoning' => [
            'provider' => 'openai',
            'model' => env('AI_ROUTE_REASONING_MODEL', 'gpt-4o'),
            'input_price_microusd_per_mtok' => (int) env('AI_ROUTE_REASONING_INPUT_PRICE_MICROUSD_PER_MTOK', 2_500_000),
            'cached_input_price_microusd_per_mtok' => (int) env('AI_ROUTE_REASONING_CACHED_INPUT_PRICE_MICROUSD_PER_MTOK', 1_250_000),
            'output_price_microusd_per_mtok' => (int) env('AI_ROUTE_REASONING_OUTPUT_PRICE_MICROUSD_PER_MTOK', 10_000_000),
            'max_input_tokens' => (int) env('AI_ROUTE_REASONING_MAX_INPUT_TOKENS', 6000),
            'max_output_tokens' => (int) env('AI_ROUTE_REASONING_MAX_OUTPUT_TOKENS', 1200),
            'max_request_cost_microusd' => (int) env('AI_ROUTE_REASONING_MAX_REQUEST_COST_MICROUSD', 300_000),
            'price_version' => (int) env('AI_ROUTE_REASONING_PRICE_VERSION', 1),
            // §11.2 — `reasoning` is used only when the remaining Workspace
            // budget is at least this multiple of the route's own request
            // cap. Otherwise the request is downgraded to `routine`.
            'min_headroom_multiple' => (int) env('AI_ROUTE_REASONING_MIN_HEADROOM_MULTIPLE', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Category -> route selection policy (contract §13 table)
    |--------------------------------------------------------------------------
    |
    | Every AiUsageCategory case must appear here exactly once. This is the
    | DEFAULT route; AiModelRouter still applies the reasoning headroom
    | check and the downgrade-then-refuse rule on top of this mapping.
    |
    */
    'category_routes' => [
        'coo_diagnosis' => 'routine',
        'coo_interactive' => 'routine',
        'conversation_compaction' => 'compaction',
        'website_generation' => 'routine',
        'campaign_message_draft' => 'routine',
        'agency_prospect_reply' => 'routine',
    ],

];
