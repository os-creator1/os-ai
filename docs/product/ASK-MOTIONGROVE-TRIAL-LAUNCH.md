# Ask MotionGrove V1 — production configuration and staging smoke test

Every key below exists in the code today (`config/services.php`, `config/ai.php`, `config/assistant.php`, `config/queue.php`,
`config/cache.php`, `app/Console/Kernel.php`). Nothing here adds a key. Defaults are shown; only the **required** ones must be set.

## 1. Required to turn the assistant's model calls on

| Variable | Required | Notes |
|---|---|---|
| `OPENAI_ACTIVE` | yes | `config('services.openai.active')`, default **false**. The gateway's first gate (`AiGateway::complete`) refuses every AI call while it is false; the provider client also refuses. Chips that need no model keep working when it is off. |
| `OPENAI_API_KEY` | yes | The provider client refuses to call without it. Use a key whose provider-side monthly spend limit is set to a small value for the trial (the in-app budget below is a second, independent limit). |
| `OPENAI_ORGANIZATION`, `OPENAI_PROJECT` | optional | Read by `services.openai.*` if the provider account needs them. |

Budget enforcement is not a switch: `AskAssistant` and `ConversationCompaction` are always hard-enforced
(`AiUsageCategory::isAlwaysHardEnforced`), a workspace without a valid plan resolves to a zero cap and is refused, and
`AI_ENFORCE_BUDGETS_FOR_EXISTING_CATEGORIES` is deprecated and no longer read. So setting `OPENAI_ACTIVE=true` cannot enable AI
without the plan caps; the gateway reserves against the cap before every provider call. The assistant is also gated per plan by the
`ai_coo_basic` entitlement.

## 2. Model selection and per-request limits (all optional, defaults shown)

Assistant turns use route `assistant`; "why/explain" questions after three tool results use `reasoning`; compaction uses `compaction`.
- `AI_ROUTE_ASSISTANT_MODEL=gpt-4o-mini`, `AI_ROUTE_ASSISTANT_MAX_INPUT_TOKENS=7000`, `AI_ROUTE_ASSISTANT_MAX_OUTPUT_TOKENS=700`,
  `AI_ROUTE_ASSISTANT_MAX_REQUEST_COST_MICROUSD=20000`, `AI_ROUTE_ASSISTANT_TIMEOUT_SECONDS=25`, plus the
  `AI_ROUTE_ASSISTANT_INPUT/CACHED_INPUT/OUTPUT_PRICE_MICROUSD_PER_MTOK` and `AI_ROUTE_ASSISTANT_PRICE_VERSION` price keys.
- `AI_ROUTE_REASONING_MODEL=gpt-4o` (and the matching `AI_ROUTE_REASONING_*` keys; max request cost 300000 microUSD, no route timeout:
  the remaining turn time bounds it).
- `AI_ROUTE_COMPACTION_MODEL=gpt-4o-mini` (and the matching `AI_ROUTE_COMPACTION_*` keys).
- If you change a model, change its `*_PRICE_MICROUSD_PER_MTOK` keys with it, or the budget will be computed on the wrong price.

## 3. Budget limits (micro-USD; 1,000,000 = $1)

| Plan | Variable | Default |
|---|---|---|
| Trial (whole trial, does not reset) | `AI_BUDGET_TRIAL_WORKSPACE_CAP_MICROUSD` | 3,000,000 ($3) |
| Core (monthly) | `AI_BUDGET_CORE_WORKSPACE_CAP_MICROUSD` | 5,000,000 ($5) |
| Growth (monthly) | `AI_BUDGET_GROWTH_WORKSPACE_CAP_MICROUSD` | 10,000,000 ($10) |
| Agency (monthly) | `AI_BUDGET_AGENCY_WORKSPACE_CAP_MICROUSD` / `AI_BUDGET_AGENCY_BUSINESS_CAP_MICROUSD` | 25,000,000 / 6,000,000 |
| Interactive share | `AI_BUDGET_{TRIAL,CORE,GROWTH}_INTERACTIVE_SHARE_BPS`, `AI_BUDGET_AGENCY_INTERACTIVE_SHARE_BPS` | 8000, 8000, 8000, 3000 |
| Platform-wide | `AI_PLATFORM_MONTHLY_CAP_MICROUSD` / `AI_PLATFORM_INTERACTIVE_SHARE_BPS` / `AI_PLATFORM_SCOPE_ID` | 20,000,000 / 3000 / 1 |
| Other | `AI_BUDGET_POLICY_VERSION` (bump when an amount changes; open periods keep the cap they started with), `AI_RESERVATION_EXPIRY_MINUTES=15`, `AI_PRESENTATION_NEARING_LIMIT_BPS=8000` | |

Leave these at the defaults for the trial unless you deliberately want a lower cap (lower caps apply to new periods).

## 4. Assistant behaviour, retention, locks (optional)

`ASSISTANT_RETENTION_DAYS=0` (default: keep until the customer deletes; positive = days, max 3650), `ASSISTANT_TURN_BUDGET_SECONDS=75`, `ASSISTANT_DELETE_WAIT_SECONDS=4`,
`ASSISTANT_MAX_MODEL_ITERATIONS=3`.

## 5. Infrastructure that must be true (not code)

- **Shared lock-capable cache:** `CACHE_DRIVER=redis` (or `database`/`memcached`) on every web and queue worker. The repository default
  is `file`, which does not coordinate across servers; per-conversation turn locks and delete/turn coordination depend on it.
- **Scheduler:** the normal `php artisan schedule:run` every minute. It runs `assistant:purge-expired` daily (it deletes nothing under the default `ASSISTANT_RETENTION_DAYS=0`; it enforces a configured positive period) and also
  `queue:work --queue=automation,default,batch` every minute.
- **Queue:** `QUEUE_CONNECTION` (`.env.example` ships `sync`; `config/queue.php` defaults to `database`). The assistant's booking
  emits `AppointmentScheduled`, whose Automations enrolment listener is queued (idempotent); pick a real queue connection in production.
- **Database:** MySQL 8 with all migrations applied (`assistant_*` tables, `ai_usage_ledger`, `ai_usage_periods`).
- **Not done in code (see ASK-MOTIONGROVE-V1.md, "Production prerequisites" 4):** legal review of the first-use AI-data notice, the
  provider data-processing agreement/subprocessor disclosure, and the retention policy. The default `ASSISTANT_RETENTION_DAYS=0` keeps
  conversations until the customer deletes them and needs explicit privacy/legal sign-off; an explicitly configured positive period (for
  example 90 days) has its own review requirements. Check the effective value in the deployment environment before launch.

## 6. Controlled real-provider smoke test (staging only; NOT run, needs explicit authorization before any paid call)

Preconditions: a staging environment with its own database and a **separate provider key capped at a few dollars**; a test Workspace on
the Trial plan with the cap lowered for the test (`AI_BUDGET_TRIAL_WORKSPACE_CAP_MICROUSD=200000`, i.e. $0.20, set before the period
opens; bump `AI_BUDGET_POLICY_VERSION`); synthetic data only (a contact "Test Person", phone `15550001234`, no real customer data).

1. With `OPENAI_ACTIVE=false`: send a model-needing question. Expect the "AI is not available" state, **zero** rows in `ai_usage_ledger`,
   and no provider traffic. Then set `OPENAI_ACTIVE=true` and `OPENAI_API_KEY`.
2. First visit: the AI-data notice is visible under the input before the first send. Send "What should I set up first?"; expect an
   answer and one `committed` ledger row (`category=ask_assistant`) with a small `actual_cost_microusd`.
3. Ask "Is 15550001234 one of my contacts?" and confirm from the provider dashboard's request log (if
   it keeps one) or from the reply that the customer sees the name and number while the provider was only given a handle and a count.
4. Ask for a booking, approve it; expect one appointment, no customer email/text, and the preview wording "No confirmation… sent".
5. Repeat sending until the $0.20 cap is reached: expect the plain "used up" state, a `refused`/`budget_exhausted` ledger row, and no
   further provider call. Confirm a *cheaper* question is still attempted by the gateway rather than blocked by an old refusal.
6. Delete the conversation; expect the thread gone. Run `php artisan assistant:purge-expired` and confirm it is a no-op.
7. Total expected spend: well under $0.20. Restore the production caps, rotate or delete the staging key.
