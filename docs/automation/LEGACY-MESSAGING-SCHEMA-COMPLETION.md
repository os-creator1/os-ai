# LEGACY MESSAGING SCHEMA COMPLETION

**Status:** Schema-completion lane, independent of Chat A. Three paths: one
forward-only migration, one focused schema test, and this document. No
production code, model, route or existing test is edited.

**Base:** `origin/main` at `e5499df2d304572d49b26cdc35c05f32c26ac98a`
(PR #232). Database: `ultimatesms_testing_lane_e_schema` — a validated
`TestDatabaseSafety` sibling, owned by this lane alone, 251 migrations,
0 pending.

---

## ⚠️ 1. One blocker requires an owner decision before this can merge green

**This branch turns exactly one merged test red, and no in-scope change can
prevent it, because that test asserts the bug this lane exists to fix.**

`tests/Feature/Outreach/OutreachCorrection1Test::test_legacy_campaign_builder_still_creates_ai_prospecting_rows_unchanged`
proves the legacy AI-prospecting hook is *not* gated for legacy callers. It
proves it by asserting the hook **throws**, and that the exception names the
missing schema:

```php
$this->fail('Expected the legacy AI-prospecting hook to attempt chat_boxes/ai_box_campaign_map and fail on a pre-existing missing schema piece.');
} catch (\Illuminate\Database\QueryException $exception) {
    $this->assertTrue(
        str_contains($exception->getMessage(), 'ai_box_campaign_map') || str_contains($exception->getMessage(), 'ai_stage'),
        ...
```

Its own comment says the gap is *"out of scope to fix here"* — written when
nobody intended to fix it. Once the schema exists the hook succeeds, no
exception is thrown, and `$this->fail(...)` fires. **Measured, not
predicted:** that is the exact failure this branch produces.

This is not a defect in the migration and not something a different schema
shape avoids. Any correct schema makes the code work, and the test asserts
the code does not work.

**What it needs.** The test's intent — "the legacy path is not gated" — is
still worth proving, and is now provable *positively*: assert that the hook
created the `chat_boxes` and `ai_box_campaign_map` rows, instead of asserting
it crashed. That is a rewrite of one test method in
`tests/Feature/Outreach/OutreachCorrection1Test.php`, **which is outside this
lane's three-path allowlist**, so this lane did not make it. It needs either
an allowlist extension or a follow-up authorized lane.

**Until then, merging this branch leaves `tests/Feature/Outreach` at 1
failure.** That is stated here rather than buried so the decision is taken
deliberately.

---

## 2. The gap, traced mechanically

Every executable reference in the repository, excluding `vendor/` and
`node_modules/`:

| Site | Operation | Schema piece |
|---|---|---|
| `app/Http/Controllers/Customer/DLRController.php:619` | `DB::table('chat_boxes')->where('id', …)->update(['reply_by_customer' => 1, 'ai_replied' => 0])` | `chat_boxes.ai_replied` |
| `app/Repositories/Eloquent/EloquentCampaignRepository.php:1196` | `DB::table('chat_boxes')->insertGetId([… 'ai_stage' => 1 …])` | `chat_boxes.ai_stage` |
| `app/Repositories/Eloquent/EloquentCampaignRepository.php:1215` | `DB::table('ai_box_campaign_map')->insert($mapRows)` | `ai_box_campaign_map` |

**There are exactly three writers and, today, zero readers.** The two
controllers that read this schema — `AiAnalyticsController` and
`HotLeadController` — were removed by B5, which `routes/web.php` records in
place, noting that "the live producers of that untracked schema in
`EloquentCampaignRepository` and `DLRController` are stop-listed for B5 and
are deliberately untouched (a separate contract)". This lane is that
schema-side contract; it leaves both producers untouched.

Confirmed against the migration-built database before any change:
`chat_boxes` carried `id, uid, user_id, from, to, notification, created_at,
updated_at, sending_server_id, reply_by_customer, pinned` — no `ai_replied`,
no `ai_stage` — and `ai_box_campaign_map` did not exist.

---

## 3. How each shape was derived

Nothing here is a guess. Each shape comes from how the unmodified production
code already uses the column or table, corroborated by the documentation that
recorded the gap (`B5-BUSINESS-ANALYTICS-CONTRACT.md` §14,
`DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md` §3.6).

### `chat_boxes.ai_replied` → `boolean`, `default(false)`, not nullable

`DLRController` writes the literal `0` in the *same update* that writes
`reply_by_customer => 1`. `reply_by_customer` is already migrated
(`2023_05_07_163338_add_reply_by_customer_to_chat_boxes.php`) as
`$table->boolean('reply_by_customer')->default(false)`. Mirroring its sibling
exactly is the only shape that needs no invention: every pre-existing row
gets "has not been AI-replied to", which is what the writer implies.

### `chat_boxes.ai_stage` → `unsignedTinyInteger`, **nullable**, no default

`campaignBuilder` writes the literal `1` when it enrols a contact. The
documented value range is 1–6 and 99.

**Nullable is the load-bearing decision, and it is deliberate.** Chat boxes
are also created by the ordinary inbound-message path, which never sets a
stage. "Not in the AI state machine" is a different fact from "stage 0", and
a `default(0)` would assert the second for every row that only ever meant the
first — including every row already in a live installation. A nullable column
with no default states only what is actually known.

### `ai_box_campaign_map` → `id`, `box_id`, `campaign_id`, `created_at`

The insert supplies exactly `box_id`, `campaign_id`, `created_at`. The
removed reader joined `chat_boxes.id = map.box_id` and filtered on
`map.campaign_id`. **No `updated_at`**, because nothing writes one — adding
it would be inventing schema rather than completing it.

### Both foreign keys `CASCADE`, because existing behaviour requires it

Not a stylistic default. Each cascade is the only rule under which existing,
unmodified code still works:

* **`campaign_id`** — `campaignBuilder` inserts the map rows and only *then*
  counts `subscribersToSend()`; when that count is zero it calls
  `$new_campaign->delete()` with the map rows already present.
  `App\Models\Campaigns` does not soft-delete, so the delete really reaches
  the database. A restricted key would turn that existing path into a
  constraint violation.
* **`box_id`** — `chat_boxes` rows are hard-deleted in two places:
  `app/Console/Commands/ClearChatbox.php` (rows untouched for seven days) and
  `app/Http/Controllers/Admin/CustomerController.php:620` (when a customer is
  removed). A restricted key would break both.

The index `(campaign_id, box_id)` serves the join the table existed for and
backs the campaign foreign key.

### Idempotency and reversibility

Each addition is guarded by `Schema::hasColumn` / `Schema::hasTable`,
following the repository's own convention for retrofitting legacy schema
(`2024_03_05_162536_update_contacts_table.php`,
`2025_05_29_131834_add_direction_to_reports_table.php`,
`2025_10_13_144953_add_performance_indexes_to_reports_and_others.php`). Long-
lived installations already carry these pieces out-of-band — that is exactly
why the code works in production and not on a fresh migrate — so replay must
be, and is, safe.

`down()` drops the mapping table *before* the `chat_boxes` columns, so the
column change never runs against a live foreign key, and drops each column
only if present. No chat box, campaign or message row is read, rewritten or
deleted in either direction.

---

## 4. A second, separate defect found — reported, not fixed

The `campaignBuilder` insert at `:1192` supplies
`user_id, to, from, ai_stage, created_at, updated_at` but **not `uid`**,
which `chat_boxes` declares `char(36) NOT NULL` with no default.

Under the server's own `STRICT_TRANS_TABLES` mode that insert fails even with
this migration applied — verified directly:

```
ERROR 1364 (HY000): Field 'uid' doesn't have a default value
```

It does not fail through the application, because `config/database.php` sets
`'strict' => false`, so Laravel's session runs `NO_ENGINE_SUBSTITUTION` only
and MySQL silently substitutes `''`. Verified: the Laravel session's
`@@SESSION.sql_mode` is `NO_ENGINE_SUBSTITUTION`.

So the schema completed here **is** sufficient for the application as
configured, and this branch's verification proves that end to end. But the
row it writes carries an empty `uid` where every other chat box carries a
UUID. Fixing that means editing `EloquentCampaignRepository`, which this
lane is explicitly forbidden to touch, so it is recorded here for whoever
owns that stop-listed producer.

---

## 5. Verification

All runs against `ultimatesms_testing_lane_e_schema`, a validated
`TestDatabaseSafety` sibling owned by this lane alone. The canonical
`ultimatesms_testing` was never created, reset, migrated or written.

### 5.1 Migration mechanics

| Step | Result |
|---|---|
| Forward | `ai_replied` `tinyint(1) NOT NULL DEFAULT 0`; `ai_stage` `tinyint unsigned NULL`; mapping table present |
| Rollback one step | all three **absent** — exactly what `up()` added, nothing else |
| Re-forward | all three present again, identical shapes |
| Complete `migrate:fresh` | **251 migrations, 0 pending** |

### 5.2 Schema, index and foreign-key assertions

After `migrate:fresh`:

| Column | Type | Null | Default |
|---|---|---|---|
| `ai_box_campaign_map.id` | `bigint unsigned` | NO | — |
| `ai_box_campaign_map.box_id` | `bigint unsigned` | NO | — |
| `ai_box_campaign_map.campaign_id` | `bigint unsigned` | NO | — |
| `ai_box_campaign_map.created_at` | `timestamp` | YES | NULL |

| Foreign key | References | On delete |
|---|---|---|
| `box_id` | `chat_boxes` | **CASCADE** |
| `campaign_id` | `campaigns` | **CASCADE** |

Indexes: `PRIMARY(id)`, `ai_box_campaign_map_campaign_box_index(campaign_id, box_id)`,
and the `box_id` index MySQL creates to back its foreign key.

### 5.3 The focused suite

`tests/Feature/Messaging/LegacyAiMessagingSchemaTest.php` —
**12 tests, 38 assertions, green.**

It deliberately replays the producers' own column sets rather than restating
the migration: a test that mirrors the migration proves only that the file
was read. Neither producer is imported or executed — both are stop-listed —
so the writes are byte-for-byte copies of their column sets, kept close so
drift in either producer surfaces here. Covered: both columns and the table
exist; `ai_replied` is a non-nullable `0`-defaulted flag; `ai_stage` is
nullable with no default; the table has exactly the writer's columns and no
`updated_at`; both cascading foreign keys; the join index; the real inbound
update; the real enrolment write; a chat box outside the state machine
keeping a null stage; campaign delete removing only its own mapping rows;
chat-box delete removing only its own; and a neighbouring chat-box row
surviving a full lifecycle byte-identical.

### 5.4 Head-to-head against pristine `origin/main`

Same machine, same database, `migrate:fresh` before each side. The migration
and the new test were moved aside for the pristine run and restored after.

| Suite | pristine `main` | this branch | delta |
|---|---|---|---|
| `tests/Feature/Messaging` | did not exist | 12 tests, 38 assertions, green | **+12 tests, +38 assertions** |
| `tests/Feature/Dashboards` | 14 tests, 41 assertions, green | 14 tests, 41 assertions, green | **identical** |
| `tests/Feature/Outreach` | 35 tests, 98 assertions, green | 35 tests, 97 assertions, **1 failure** | **the §1 collision** |

The single failure is
`OutreachCorrection1Test::test_legacy_campaign_builder_still_creates_ai_prospecting_rows_unchanged`,
for the reason §1 gives. There is no second delta.

---

## 6. Scope

Exactly three paths:

1. `database/migrations/2026_09_12_100006_complete_legacy_ai_messaging_schema.php`
   — timestamped after Chat A's `2026_09_12_100005`, which is not yet on
   `main`, so this orders correctly whether Chat A merges before or after.
2. `tests/Feature/Messaging/LegacyAiMessagingSchemaTest.php`
3. `docs/automation/LEGACY-MESSAGING-SCHEMA-COMPLETION.md`

No existing migration was edited. `DLRController`,
`EloquentCampaignRepository`, models, routes, Chat A tests and Chat A
migrations are all untouched, as are `app/`, `config/`, `resources/`,
`public/`, `bootstrap/cache/`, `vendor/` and every dependency file.
