# Implementation Contract 08B — Location ACL Consumer Wiring

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 02 (Location ACL foundation) and, per this contract's
own findings, effectively also on Contract 06 (Conversation Location
scoping) plus two **new** location_id migrations this contract itself
must add for Contacts and Opportunities — see §3's inventory before
assuming the Roadmap's original four-resource scope is achievable as
written.

## 1. Objective

Wire Contract 02's `LocationAccessGuard` into every Location-bound
controller — but **first**, per the required deep-dive, inventory every
such controller/route and prove, resource by resource, whether the
underlying record even carries a `location_id` to check yet. **Finding,
stated up front:** it does not, for two of the Roadmap's four named
resources.

## 2. Governing authority

- Blueprint §4, §5, §9, §10, §26 (Location-bound records, staff ACL).
- Addendum §4, §5.
- Roadmap Slice 8(b).
- Contract 02 (the guard this slice wires in), Contract 06 (the one
  resource that already has `location_id`).

## 3. Current repository reality — full coverage inventory (deep-dive requirement)

**Two parallel controller families exist for Business-scoped resources,
confirmed via `git grep`, not assumed:**

**Family A — modern, `ResolvesBusinessTenancy` trait
(`app/Http/Controllers/Customer/Business/Concerns/ResolvesBusinessTenancy.php`,
full relevant portion read):** exposes `resolveBusinessTenancy(string
$workspaceUid, string $businessUid, bool $requireActive = true): array`
and `resolveEntitledBusinessTenancy(string $workspaceUid, string
$businessUid, string $featureKey): array` — the **single shared seam**
every controller in this family runs its Business-tenancy chain through.
Eleven controllers confirmed consuming it: `AutomationsController`,
`BusinessKnowledgeProfileController`, `BusinessSettingsController`,
`CrmOpportunitiesController`, `CrmPipelinesController`,
`GoogleBusinessProfileController`, `MessagingChannelsController`,
`TextMessagingController`, `WebsiteController`, `OutreachController`, plus
the trait's own `ResolvesAutomationWorkflows` concern. **This is the
correct, single place to add a Location-scoped sibling method** —
mirroring the trait's own existing pattern exactly, not eleven individual
controller edits.

**Family B — legacy, ad hoc per-controller tenancy**
(`app/Http/Controllers/Customer/ContactsController.php`,
`OpportunityController.php`, `ChatBoxController.php`, and others at the
same directory level): no shared trait; each controller resolves tenancy
its own way (this session's own prior work, PR #302, added specific
parent-child mismatch checks directly inside `ContactsController`'s
`updateContact()`/`deleteContact()` methods — precedent for "fix each one
individually," not a shared seam). Route parameters here are frequently
the **group/parent** ID, not the leaf resource (`contacts/{contact}/...`
where `{contact}` is confirmed, from this session's own prior work, to
often be the **ContactGroup**, with the true leaf Contact `{uid}` appearing
deeper in the path) — Location resolution in this family must be added
**per controller**, following the same "verify the true parent-child
relationship explicitly, don't trust the route shape" discipline this
session's own PR #302 Correction established for Business tenancy.

**Coverage matrix (resource → route family → parent → Location
derivation → guard target), built from actual inspection, not assumption:**

| Resource | Controller family | Route parent shape | Does the model have `location_id` today? | Location ACL wiring point |
|---|---|---|---|---|
| Conversations (`ChatBox`) | B (legacy, `ChatBoxController`) | `chat-box/...`, plus writes from `DLRController`/`ConversationHistoryWriter`/`EloquentCampaignRepository` (Contract 06) | **Yes, after Contract 06** — `chat_boxes.location_id` | `ChatBoxController`'s read/list/show actions, individually wired |
| Contacts (`Contacts`) | B (legacy, `ContactsController`) | `contacts/{contact}/...` — `{contact}` frequently the **group**, per this session's own PR #302 finding | **No** — confirmed via model inspection, no `location_id`/`business_location_id` column exists | **Blocked** until this contract adds a `location_id` migration+backfill for `Contacts` (mirroring Contract 06's exact philosophy) — see §5 |
| Opportunities (`Opportunity`) | Two controllers exist: legacy `OpportunityController` (`opportunities/{opportunity}`, numeric ID) **and** modern `CrmOpportunitiesController` (Family A, `resolveEntitledBusinessTenancy(..., PlatformFeature::Crm->value)`) | Both | **No** — confirmed via model inspection, no `location_id` column exists on `Opportunity` | **Blocked**, same reason; once added, wire into **both** controllers (they are not the same code path) |
| Calendar / Booking | N/A | N/A | **N/A — module does not exist yet** (traceability matrix row 9, confirmed unimplemented) | **Not in scope for this slice** — nothing to wire |
| Automations (runs) | A (`AutomationsController`, `ResolvesAutomationWorkflows`) | `{workspaceUid}/businesses/{businessUid}/automations/...` | Not inspected in this pass (flagged — automation *run* Location-binding is Blueprint §13's own concern, likely a separate execution-time concern rather than a static column check) | Deferred — flagged, not silently skipped |
| Website (pages) | A (`WebsiteController`) | same shape | `WebsitePage` — not inspected for `location_id` in this pass | Deferred — flagged |
| GBP | A (`GoogleBusinessProfileController`) | same shape | Not inspected | Deferred — flagged |
| Messaging channels | A (`MessagingChannelsController`) | same shape | `BusinessMessagingNumber` — confirmed in Contract 06 §3 to have **no** Location column | Deferred, same root gap as Contract 06 flagged |
| Outreach | A (`OutreachController`) | same shape | Out of scope — Outreach targets prospective clients, not Location-bound operational records, per Blueprint §29 | **Not applicable**, correctly excluded, not merely skipped |

**This inventory is the deep-dive's required deliverable.** It proves the
Roadmap's original four-resource framing ("Contacts/Opportunities/
Calendar/Conversations") was written before this contract's own evidence
pass — only Conversations is actually wireable today, and only after
Contract 06.

## 4. Delta from current state to target

**Corrected scope, based on §3's findings — three parts, not four:**

**Part 1 (achievable now, depends only on Contract 02 + Contract 06):**
wire `LocationAccessGuard` into `ChatBoxController`'s read paths.

**Part 2 (this contract's own new prerequisite work, mirroring Contract
06's exact pattern):** add `location_id` to `Contacts` and `Opportunity`
— new nullable columns, new conservative backfill classes
(`ContactsLocationBackfillV1`, `OpportunityLocationBackfillV1`, each
mirroring `ChatBoxBusinessBackfillV1`'s philosophy: resolve only on clear
evidence, `NULL` on ambiguity, idempotent, immutable once shipped),
resolved at each resource's own creation site (not enumerated exhaustively
here — that is this contract's own follow-on deep-dive at implementation
time, exactly mirroring Contract 06 §3's "trace actual creation paths"
discipline, not assumed).

**Part 3 (only after Part 2 lands):** wire `LocationAccessGuard` into
`ContactsController`, `OpportunityController`, and
`CrmOpportunitiesController`'s read/write paths, following the exact
per-controller, verify-the-true-parent discipline PR #302 already
established for this codebase's Family-B controllers.

**Explicitly does NOT change:** Automations, Website, GBP, Messaging
Channels, Outreach — all deferred per §3's inventory, each requiring its
own dedicated evidence pass this contract does not perform (flagged, not
silently included or silently dropped).

## 5. Data model contract

**`contacts` — one new column** (exact table name to confirm — the
model's own table may differ from `contacts`; verify at implementation
time): `location_id`, nullable, FK → `business_locations.id`,
`restrictOnDelete()`, mirroring Contract 06 §5's exact column shape.

**`opportunities` — one new column**, same shape.

**No column added to any Family-A resource in this slice** (§4, Part 4 is
out of scope).

## 6. Authority / security contract

Once wired (Part 1 for Conversations now; Part 3 for Contacts/
Opportunities after Part 2): identical to Contract 02 §6's table, applied
per resource — a `Selected`-scope staff member with no grant for a
record's Location is refused, re-derived from the repository on every
request (never trusting a route-bound ID), matching Contract 02's own
IDOR-safe design exactly.

**Forbidden-actor tests required per wired controller (not once
globally):** a Selected-scope staff member attempting to reach a record at
an ungranted Location via its real ID, for **each** of
`ChatBoxController`, `ContactsController`, `OpportunityController`,
`CrmOpportunitiesController` individually — Family B's per-controller
tenancy pattern means a guard added to one does not protect another, so
each needs its own explicit adversarial test, exactly mirroring how PR
#302's own Business-tenancy fix had to be applied to `ContactsController`
and `ContactsHTTPController`/`ContactsHTTPController` **separately** even
though they share a domain.

## 7. Transaction / concurrency boundary

Not applicable to the guard-wiring itself (read-time authorization
checks, no new writes). Part 2's backfill migrations follow Contract 06
§8's exact chunked, idempotent pattern.

## 8. Migration / backfill

Part 2's two new backfill classes, each mirroring
`ChatBoxBusinessBackfillV1`/Contract 06's `ChatBoxLocationBackfillV1`
exactly: chunked, idempotent (`WHERE location_id IS NULL`), immutable once
shipped, aggregate-only logging, single-Active-Location-or-`NULL`
resolution where the existing evidence (the record's own `business_id`)
doesn't itself disambiguate Location.

**Staff-access-grant seeding**, exactly as the Roadmap's own original note
already anticipated: before Part 3's enforcement goes live, every existing
`Selected`-scope `WorkspaceMembership` needs an equivalent
`workspace_membership_locations` grant seeded (Contract 02's own
backfill already defaults everyone to `All`, so this is only consequential
for any membership an owner has *already* explicitly narrowed to
`Selected` on the Location axis between Contract 02 landing and this
slice's enforcement going live — a narrow window, but not zero-risk,
hence still requiring the check).

## 9. Backwards compatibility

Part 1 and Part 3's enforcement is the **first real narrowing** of access
in this entire roadmap (every prior contract was additive-only) — this is
explicitly called out as the one slice where "never silently widen or
narrow access" (Contract 02's own stated risk) becomes live. Every
existing test for `ChatBoxController`/`ContactsController`/
`OpportunityController`/`CrmOpportunitiesController` must be re-run and
individually confirmed unaffected for `All`-scope actors (the default —
Contract 02 §8), with new tests added only for `Selected`-scope cases.

## 10. Events / audit

No new events. Refusals should be logged/auditable consistent with how
this codebase already handles authorization refusals elsewhere (existing
`403`/`404` pattern per the Exceptions-Handler existence-disclosure rule
this session's own PR #302 work already relies on) — no new audit
mechanism invented here.

## 11. Billing/provider safety

Not directly applicable — no payer/wallet action. Indirectly relevant:
`ContactsController`'s `message()` action (found in the route list, §3)
sends a paid SMS — this slice's Location ACL check gates *reaching* the
Contact, not the message-sending paid-side-effect check itself (untouched,
governed by Blueprint §20 elsewhere).

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_2x_100009_add_location_id_to_contacts_table.php`
- `database/migrations/2026_09_2x_100010_add_location_id_to_opportunities_table.php`
- `app/Library/Contacts/Migration/ContactsLocationBackfillV1.php` (namespace to confirm against actual codebase convention at implementation time)
- `app/Library/Opportunities/Migration/OpportunityLocationBackfillV1.php`
- `tests/Feature/Contacts/ContactsLocationAclTest.php`
- `tests/Feature/Opportunities/OpportunityLocationAclTest.php`
- `tests/Feature/Conversations/ChatBoxLocationAclTest.php`

**Existing files modified:**
- `app/Http/Controllers/Customer/ChatBoxController.php`
- `app/Http/Controllers/Customer/ContactsController.php`
- `app/Http/Controllers/Customer/OpportunityController.php`
- `app/Http/Controllers/Customer/Business/CrmOpportunitiesController.php`
- `app/Models/Contacts.php`, `app/Models/Opportunity.php` — add `location_id`/`location()` relation.

**Explicitly not touched (§4):** `AutomationsController`, `WebsiteController`, `GoogleBusinessProfileController`, `MessagingChannelsController`, `OutreachController`, `ResolvesBusinessTenancy.php` itself (no Location-scoped sibling method added in this slice, since none of Family A's resources are wired here — flagged for a future slice if any Family-A resource needs it).

## 13. Required tests

Per §6 — one adversarial forbidden-actor test per wired controller
(four total: ChatBox, Contacts, Opportunity legacy, CrmOpportunities),
plus the two new backfill classes' own tests mirroring Contract 06 §13's
shape, plus a full re-run of every existing test for all four wired
controllers to prove zero regression for `All`-scope actors.

## 14. Acceptance criteria

1. §3's coverage matrix is re-verified accurate at implementation time
   (re-run the same `git grep`/model inspection — controllers may have
   changed since this contract was written).
2. `location_id` exists and is correctly backfilled for `Contacts` and
   `Opportunity`, mirroring Contract 06's own acceptance bar.
3. Every wired controller passes its adversarial forbidden-actor test.
4. Zero regression for `All`-scope actors across all four controllers.
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not wire Location ACL into Automations, Website, GBP, Messaging
Channels, or any other Family-A resource — explicitly deferred (§3), not
silently included. Does not add a Location column to
`BusinessMessagingNumber` (same gap Contract 06 already flagged, not
re-solved here). Does not implement Calendar/Booking (doesn't exist).

## 16. Merge prerequisites

Contract 02 (hard). Contract 06 (hard, for Part 1). Part 2/3 has no
further external prerequisite beyond this contract's own two new
migrations landing first internally.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 02 | consumes `LocationAccessGuard` | Serialize (prerequisite) |
| Contract 06 | consumes `chat_boxes.location_id`; this contract's Part 2 mirrors its backfill pattern for two more tables | Serialize (prerequisite) |
| Contract 13 | this slice's transitional Contract-02-§5 cross-check becomes vacuous once Contract 13 lands, same as Contract 02's own note | Serialize, downstream |

## 18. Implementation prompt

```
You are implementing Slice 8(b) of the V1 architecture migration for the
os-creator1/os-ai repository: Location ACL consumer wiring, per docs/
product/implementation-contracts/08B-LOCATION-ACL-CONSUMER-WIRING.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 02 and 06 are merged to main -- hard prerequisites. If
   either is missing, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-08b-location-acl-consumer-wiring).
4. Re-read the full contract, especially SS3's coverage matrix -- it is
   the authoritative scope boundary. Re-run the same git grep/model
   inspection this contract used to confirm the matrix is still accurate
   -- controllers may have changed since this contract was written. If
   you find a Location-bound resource this contract's matrix missed, or
   find that a "deferred" resource now has a location_id column some
   other slice added, STOP and report before proceeding, since it changes
   this slice's correct scope.
5. Inspect ResolvesBusinessTenancy.php, ContactsController.php,
   OpportunityController.php, CrmOpportunitiesController.php, and
   ChatBoxController.php in their actual current state.

Implement exactly the corrected three-part scope in SS4: Part 1 (wire
ChatBoxController now), Part 2 (add location_id + backfill to Contacts
and Opportunity, tracing every real creation path the way Contract 06
did -- do not merely add the column), Part 3 (wire the four controllers
once Part 2 lands). Do NOT wire any Family-A controller (Automations,
Website, GBP, Messaging Channels, Outreach). Do NOT implement Calendar.

After implementing:
- Run the new focused test files, including the adversarial forbidden-
  actor test for each of the four wired controllers.
- Re-run every existing test for all four controllers to confirm zero
  regression for All-scope actors.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, the backfill classes' resolved/unresolved/ambiguous
counts, and explicit confirmation of zero regression for All-scope
actors. Do NOT begin or authorize Contract 09 or any other later slice.
```
