# TELNYX MANAGED MESSAGING ARCHITECTURE DECISION — §28.3 GATE-CLEARING PASS

**Status:** Bounded research and repository-audit document. No Telnyx API call, account, number, brand, campaign, or rate was created, activated or modified while producing this document. This document does not authorize Slice 3 or Slice 4 implementation; it resolves the architectural question that blocks them.

---

## 1. Executive decision

**Correction Round 1 (2026-09-08).** This section replaces Round 1's executive decision. Round 1 selected Telnyx Managed Accounts as the launch mechanism. That selection is **withdrawn as the launch decision** — the underlying research was accurate, but it committed the platform to a mechanism that, on its own evidence (§3 #2), requires a paid Telnyx commit plan starting at roughly $1,000/month before any corresponding customer revenue exists. Technical isolation purity does not override the platform's locked low-cost launch model (no included telecom credit, customers funding their own wallets, uncertain initial volume, an explicit goal of avoiding large fixed provider commitments ahead of revenue). That tradeoff is the owner's to make, and the owner has made it: **do not commit to Managed Accounts at launch.**

**Selected launch mechanism: one platform-owned, Pay-as-you-go Telnyx account, with one dedicated Messaging Profile and dedicated phone number(s) per Business** (Candidate B, §4), resolved through an internal, provider-neutral `BusinessMessagingIdentity` record (§8). Telnyx credentials are a platform-level secret; customers never see, enter, or choose a provider (§6).

**Telnyx Managed Accounts (Candidate A, with Rollup Billing) is retained as the documented scale-stage migration target, not a launch requirement.** §5 records a concrete migration outline. Migration is evaluated by the platform owner — on commercial and operational grounds — never triggered by an automatic threshold in application code. Reasonable triggers to *evaluate* migration (not to *automate* it) include: ordinary Telnyx usage naturally approaching the commit-plan spend level; customer revenue comfortably supporting the commitment; Telnyx offering an affordable startup/partner exception (§18, migration-only Q5); or provider-level suspension isolation becoming worth more than the added fixed cost.

**The isolation requirement is corrected to match what the launch mechanism can actually deliver, honestly, rather than overclaiming.** §11.2 of the messaging contract previously stated an unqualified guarantee ("a compromise or suspension of one Business's provider resources must not disable another Business") that no single-Telnyx-account mechanism can satisfy at the account level. §11.2 is amended (in the same commit as this document) into two explicit levels:

1. **Application/resource-level isolation (mandatory for Slice 3 completion):** one Messaging Profile per Business, one authoritative number-to-Business mapping, Business-scoped wallet/usage/kill-switch, fail-closed webhook routing, no customer-accessible platform credentials. This is **architecturally achievable under Candidate B** — nothing about the chosen mechanism prevents it — but it is a Slice 3 build requirement, not something this document, or the current repository, already provides (§7, §14's audit findings).
2. **Platform-provider failure domain (explicitly accepted for launch):** a suspension, KYC issue, balance failure, credential compromise, or Telnyx enforcement action against the **shared** platform Telnyx account can affect every managed Business at once. This risk is real, is not eliminated by any application-level control, and is accepted by the owner for launch in exchange for avoiding the Managed-Account commit-plan cost. It is mitigated operationally (§7), never claimed away.

**Gate status:** §28.3 is resolved for launch. The launch mechanism requires **no** Telnyx commercial commitment and **no** Telnyx approval — Slice 3's real (non-fake) provider foundation may be built against it now, with actual outbound Telnyx calls remaining inert until real platform credentials are deliberately supplied. Slice 4 remains blocked exactly as originally contracted (§28.4, §28.1, Slice 5) — the Managed-Account commit-plan/approval question is now correctly scoped as a **future migration precondition**, not a Slice 3 or Slice 4 blocker. See §19 for the full, corrected gate statement.

**Correction Round 2 (2026-09-08).** Two further corrections, applied throughout §7, §8, §12, and §15: (a) every place that read as if Slice 3's isolation controls already existed is restated as an approved, mandatory *requirement* the current repository does not yet meet (§14's audit is the evidence); and (b) the launch identity schema (§8) no longer includes any Managed-Account-specific column, dormant or otherwise — a future dedicated-account mode adds its own genuinely provider-neutral column via its own additive migration, only if and when that future slice is authorized.

---

## 2. Evidence date

**2026-09-08** (initial research). **Correction Round 1: also 2026-09-08** — a same-day commercial re-evaluation of the same evidence, not a new research pass. No new external source was needed to reach the corrected decision: every fact cited in §1's correction (the commit-plan pricing tiers, the Pay-as-you-go exclusion, the approval requirement) was already gathered and cited in Round 1's §3 evidence table below, which is preserved unchanged. See §3 for the access-method disclosure this environment required.

---

## 3. Official sources

**Preserved unchanged from the initial research pass.** Correction Round 1 changed which candidate this evidence supports selecting for *launch*; it did not invalidate or require re-gathering any citation below — every row remains accurate evidence about what Telnyx actually offers for both Candidate A and Candidate B.

**Access-method disclosure (read before evaluating confidence tiers).** This sandbox's network egress policy blocks direct HTTPS access to `telnyx.com`, `developers.telnyx.com`, and `support.telnyx.com` (confirmed: `curl` to `developers.telnyx.com` returns `CONNECT tunnel failed, response 403`; the `WebFetch` tool returns `EGRESS_BLOCKED` for all three hosts). Two access paths remained and were used together:

- **`WebSearch`**, which returns synthesized snippets from those official domains with source URLs. Used for initial discovery, not treated as a fully-opened page on its own.
- **`raw.githubusercontent.com`** reads of **`github.com/team-telnyx/knowledge-base`** and **`github.com/team-telnyx/ai`** — both are Telnyx's own GitHub organization (`team-telnyx`). The `knowledge-base` repository's own `README.md` states it is *"the source of truth for the published Telnyx Support Knowledge Base"* and that its `support-docs/` tree is *"the canonical, git-reviewed source for Telnyx Support Knowledge Base articles"* which is *"built from `support-docs/`"* and *"deployed to `support.telnyx.com`"* on merge to `main`. Each article file carries a `source_url` front-matter field pointing at the exact public `support.telnyx.com` (or `developers.telnyx.com`) URL it is the deploy source for, plus a `content_hash` and a `scraped` date. This was used as a first-party mirror of the primary source, not a third-party paraphrase — every quote below is copied verbatim from that file, and every citation gives both the mirror path actually read and the `source_url` it declares.
- `team-telnyx/ai`'s `skills/telnyx-account-management-java/SKILL.md` is explicitly `generated_by: telnyx-openapi-pipeline` — i.e., mechanically generated straight from Telnyx's own OpenAPI specification, not narrative documentation subject to editorial drift.

This is disclosed so confidence tiers below can be read honestly: claims sourced from a `support-docs/*.md` file with a `source_url` are treated as **proven** (first-party content, just relocated); claims sourced only from a `WebSearch` synthesis without an opened mirror file are marked **conditional**.

| # | Claim | Official URL (source_url) | Mirror actually read | Access date | Tier |
|---|---|---|---|---|---|
| 1 | A Managed Account is an independent Telnyx organization created from a manager account; each has its own balance, API keys, usage, settings | `support.telnyx.com/en/articles/4951492-managed-accounts` | `team-telnyx/knowledge-base` `support-docs/en--articles--4951492-managed-accounts.md` | 2026-09-08 | Proven |
| 2 | Managed Accounts require a paid Starter/Growth/Enterprise commit plan; not available on Pay-as-you-go | same as #1 | same as #1 | 2026-09-08 | Proven |
| 3 | Rollup Billing: manager pays; spend records written to the manager account; cannot be changed after account creation | same as #1 | same as #1 | 2026-09-08 | Proven |
| 4 | Manager accounts are limited to 1000 managed sub-accounts by default | same as #1 | same as #1 | 2026-09-08 | Proven |
| 5 | Managed Accounts are recommended for resellers/MSPs/multi-tenant SaaS platforms; distinct from Organizations (one shared balance, not for giving customers direct separate accounts) | `support.telnyx.com/en/articles/4951492-managed-accounts` and `support.telnyx.com/en/articles/1189141-get-started-with-organizations` | `en--articles--4951492-managed-accounts.md` and `wiki/support-docs/telnyx-account-management-and-billing--part-2.md` | 2026-09-08 | Proven |
| 6 | "Users need to be explicitly approved by Telnyx in order to become manager accounts" (API description, verbatim) | `developers.telnyx.com/api-reference/managed-accounts/*` (OpenAPI spec) | `team-telnyx/ai` `skills/telnyx-account-management-java/SKILL.md` | 2026-09-08 | Proven |
| 7 | Managed account create/list/retrieve/update/enable/disable API shape (`api_key`, `api_token`, `balance`, `manager_account_id`, `managed_account_allow_custom_pricing`, `rollup_billing`) | `developers.telnyx.com/api-reference/managed-accounts/*` | same as #6 (OpenAPI-generated) | 2026-09-08 | Proven |
| 8 | Disabling a managed account "forbid[s] it to use Telnyx services, including sending or receiving phone calls and SMS messages... will no longer be able to log in" — scoped to the one account ID | same as #7 | same as #6 | 2026-09-08 | Proven |
| 9 | "Absolutely! The Telnyx Mission Control platform was designed to support multi-tenant environments..." — reselling/platform use is officially sanctioned | `support.telnyx.com/en/articles/1130655-can-i-resell-your-services` | `en--articles--1130655-can-i-resell-your-services.md` | 2026-09-08 | Proven |
| 10 | A Messaging Profile groups numbers, defines webhook URL(s), is the assignment unit for a phone number; per-ISV-customer profile isolation is a documented pattern | `developers.telnyx.com/docs/messaging/messages/messaging-profiles-overview`, `.../configuration-and-usage` | `wiki/dev-docs/telnyx-messaging-2--part-1.md` (scraped snapshot) | 2026-09-08 | Proven (scraped dev-docs mirror, not narrative support content — see caveat below) |
| 11 | ISVs (defined as businesses selling to other end-user businesses — Telnyx's own SaaS example matches our shape) must create a **separate 10DLC brand per end-user**, then a campaign per brand, and must never share a number across brands/campaigns | `support.telnyx.com/en/articles/5593977-isvs-10dlc` | `en--articles--5593977-isvs-10dlc.md` | 2026-09-08 | Proven |
| 12 | Sole-proprietor 10DLC registration path exists for businesses without an EIN; requires legal name, phone, address, and a URL demonstrating legitimacy; brand verified via a manual OTP-PIN email loop; campaign approval typically 3–7 business days | `support.telnyx.com/en/articles/13545282-...` | `en--articles--13545282-...md` | 2026-09-08 | Proven |
| 13 | 10DLC fees: brand registration ≈$4.50 one-time, campaign review $15 per submission, monthly campaign fee $1.50–$10 depending on use case; Telnyx applies no markup on 10DLC pass-through fees | `support.telnyx.com/en/articles/5634625-10dlc-fees-and-charges` | `en--articles--5634625-10dlc-fees-and-charges.md` | 2026-09-08 | Proven |
| 14 | Webhook events are signed with Ed25519 public-key signing (`telnyx-signature-ed25519`, `telnyx-timestamp` headers); signature = Base64(`{timestamp}\|{payload}`); public key rotatable via a two-step inactive→activate flow, up to 60 minutes to propagate, one inactive key at a time, scoped **per organization (i.e., per Managed Account)** | `support.telnyx.com/en/articles/4334722-how-to-leverage-webhooks`, `.../8370064-update-webhook-sign-key-guide` | `en--articles--4334722-...md`, `en--articles--8370064-...md` | 2026-09-08 | Proven |
| 15 | Webhook retry: one retry after a 2000ms timeout if the endpoint doesn't respond with 2xx; failover URL supported; delivery-status and inbound-message payloads carry both `messaging_profile_id` and `organization_id` | same as #14 | same as #14 | 2026-09-08 | Proven |
| 16 | 10DLC campaigns auto-suspend after 15 consecutive days of inactivity with zero assigned active numbers (a T-Mobile dormancy-fine avoidance measure); reactivation is a documented two-step number re-assignment; a webhook notification is available | `support.telnyx.com/en/articles/10723378-10dlc-campaign-suspended` | `en--articles--10723378-...md` | 2026-09-08 | Proven |
| 17 | Port-out is always available: the customer's winning carrier contacts Telnyx's porting team (`lnp@telnyx.com`); Telnyx states it "will always accommodate your business's needs" | `support.telnyx.com/en/articles/1130635-can-i-port-out-my-telnyx-number` | `en--articles--1130635-...md` | 2026-09-08 | Proven |
| 18 | Acceptable Use Policy for messaging: explicit opt-in required (specific invalid-opt-in examples given), STOP/unsubscribe must be honored within 24 hours, ≤10 messages/24h to a non-conversational recipient, no spoofing, CAN-SPAM/CASL/CTIA referenced; account suspension/closure is the stated consequence of violation | `support.telnyx.com/en/articles/1310359-acceptable-use-policy-for-messaging` | `en--articles--1310359-...md` | 2026-09-08 | Proven |
| 19 | Telnyx is prepaid, pay-as-you-go; no contracts, no cancellation fee, no post-paid service; billing tracked to 4 decimal places; auto-recharge exists account-side (distinct from and in addition to AI Business OS's own wallet) | `support.telnyx.com` Account Management and Billing articles | `wiki/support-docs/telnyx-account-management-and-billing--part-1.md` and `--part-2.md` | 2026-09-08 | Proven |

**Caveat on tier "dev-docs mirror" (#10):** the `wiki/dev-docs/*.md` files are explicitly labelled in the `knowledge-base` repo's own `README.md` as *"scraped during the monthly LLMWiki refresh"* from `developers.telnyx.com` — i.e., a secondary compiled artifact rather than the `support-docs/` tree's git-reviewed primary content. It is still first-party (Telnyx's own scrape of Telnyx's own developer docs), but it is one step further from the primary source than the `support-docs/` citations, and its `updated_at` timestamp (2026-06-11) is the scrape date, not necessarily the date the underlying developer-docs page last changed. Treated as proven for the specific, narrow, mechanical API-shape claims quoted (field names, endpoint paths), which are exactly the kind of content a scrape reproduces faithfully.

**Absence is not proof of permission (per the task's own instruction).** No source above states an explicit per-second or per-day message-throughput ceiling *per Messaging Profile*, nor an explicit statement of whether managed-account throughput/verification tiers are independent per Managed Account or inherited from the manager. Both are marked **unclear** in §4 and are exact candidate Telnyx support questions in §18.

---

## 4. Candidate comparison

**Preserved unchanged from the initial research pass; the conclusion drawn from it is corrected in §5.** Both tables below remain the accurate factual comparison — including Candidate B's honestly-stated "None" for account-level suspension isolation, which Correction Round 1 does not soften, only accepts as a disclosed launch-stage tradeoff (§1, §7).

### A. Telnyx Managed Accounts

| Criterion | Finding | Tier |
|---|---|---|
| Official feature name | "Managed Accounts" (`/managed_accounts` API, "Advanced Features → Managed Accounts" in portal) | Proven |
| API availability | Full CRUD + enable/disable via API v2, and via portal | Proven |
| Account eligibility | Requires a paid Starter ($1,000/mo min spend)/Growth ($2,000/mo min spend)/Enterprise ($5,000+/mo) plan; **not available on Pay-as-you-go** | Proven |
| Telnyx approval requirement | Explicit: "Users need to be explicitly approved by Telnyx in order to become manager accounts" | Proven |
| Child-account credentials | Each Managed Account gets its own `api_key`/`api_token`, distinct from the manager's | Proven |
| Parent access | Manager can log into any Managed Account via portal, or control it via an API key scoped to that Managed Account | Proven |
| Billing/balance behavior | Each Managed Account has its own balance/payment method/invoices by default; **Rollup Billing** (opt-in at creation, immutable after) instead writes spend records to the manager account, i.e. centralizes billing | Proven |
| Number ownership | Not explicitly stated whether a number literally belongs to the Managed Account's own organization record or is merely accessible through it; the account-isolation model (distinct organization) strongly implies the former | Conditional |
| Messaging profiles | Not documented as different from the standard Messaging Profile object; a Managed Account is expected to have its own Messaging Profiles the same way any Telnyx organization does | Conditional |
| Webhook configuration | Same mechanism as any account (Messaging Profile-level or request-level URLs); public signing key is confirmed per-organization, so it is per-Managed-Account, not shared platform-wide | Proven (signing key), Conditional (profile behavior identical to any account — not separately documented) |
| A2P responsibility | Orthogonal to this axis — 10DLC brand/campaign registration is per end-user business regardless of Managed Accounts vs. single account (§4C below) | Proven |
| Suspension and closure | `disable` action is scoped to exactly one Managed Account ID; the manager and sibling Managed Accounts are unaffected | Proven |
| Number transfer | Not documented between Managed Accounts under one manager; port-out (§3 #17) is the general exit mechanism | Unclear (inter-Managed-Account transfer), Proven (port-out) |
| Suitability for a SaaS platform | Explicitly stated as the intended use case ("ideal for resellers, MSPs, and multi-tenant platforms... If you're running a SaaS platform... this is the right solution") | Proven |

### B. One platform account with one Messaging Profile per Business

| Criterion | Finding | Tier |
|---|---|---|
| Profile-level Business isolation | A Messaging Profile groups numbers and defines webhook URL(s); documented ISV pattern is "a single account can create separate messaging profiles and numbers for each customer" | Proven |
| Number-to-profile assignment | `PATCH /v2/messaging_phone_numbers/{number}` with `messaging_profile_id`; one profile per number at a time | Proven |
| Profile-specific webhook URLs | Yes — `webhook_url`/`webhook_failover_url` are profile-level fields | Proven |
| Authoritative event identifiers | Every message event payload carries both `messaging_profile_id` and `organization_id` | Proven |
| Credential scope | One account-wide API key for the whole platform (unless additional API keys are created and scoped by permission group — Telnyx's permission-group model, §3 #5's mirror, supports Messaging-only, Numbers-only, etc. keys, but not a key scoped to one Messaging Profile) | Proven (no profile-scoped key exists) |
| API-key blast radius | A single compromised platform-wide API key can act across every Business's numbers/profiles | Proven (follows from the above) |
| Throughput/rate behavior | Account-wide, tiered by verification level (Level 1: 600 msgs/day baseline before 10DLC per the FAQ context; Level 2: up to 3000 account-wide, 1200 toll-free) — **shared across every Business on the platform**, not per-profile | Proven (tiers exist and are account-wide); exact per-profile behavior under Number Pool is documented (weights, `skip_unhealthy`) but a hard per-profile message-rate ceiling independent of the account tier is **unclear** |
| A2P brand/campaign mapping | Same as Candidate A — one brand+campaign per end-user Business, orthogonal to account architecture | Proven |
| Billing attribution | One account balance; Billing Groups can categorize numbers/outbound-profiles for reporting, but this is a reporting tag, not a balance split | Proven |
| Suspension isolation | **None at the account level.** A platform-wide suspension (KYC failure, payment-method flag, ToS violation anywhere on the account) affects every Business simultaneously. Per-number/per-campaign issues (10DLC campaign suspension, a single number flagged for spam) remain isolated to that number/profile, same as under Candidate A | Proven |
| Profile and number limits | Not found — no documented hard cap on Messaging Profiles per account | Unclear |
| Number movement between profiles | Yes, a single PATCH call moves a number between profiles at any time | Proven |

### C. Another officially supported mechanism

No third Telnyx-documented mechanism was found. "Organizations" (sub-members/sub-accounts sharing one balance) was investigated and explicitly ruled out by Telnyx's own documentation for this use case: *"An organization has only one net running balance and payment method — it is not designed for resellers to give customers direct account access. For that, use Managed Accounts."* Organizations exist for internal team-member access control, not per-customer isolation, so it is not a genuine Candidate C — it is evidence for, not against, Candidate A. No hybrid is invented; per the task's own instruction, none is proposed.

---

## 5. Chosen architecture

### 5.1 Launch: Candidate B — one platform account, one Messaging Profile and dedicated number(s) per Business

**Corrected 2026-09-08 (Correction Round 1).** Candidate B is the launch architecture. Reasoning against the same 18 evaluation criteria used in Round 1, corrected where the commercial fact changes the weighting:

1. **Tenant isolation** — A: strong (separate organization per Business). B: strong at the number/profile/wallet level, **absent** at the provider-account level — stated honestly, not minimized (§7).
2. **Credential isolation** — A: each Managed Account has its own `api_key`. B: **one shared, platform-level, high-value secret** across all Businesses, held only by the server-side provider adapter, never per-Business, never customer-visible (§6).
3. **Webhook routing** — B resolves via an internal opaque route token plus `messaging_profile_id`/phone number cross-checked against `BusinessMessagingIdentity` (§9); A would additionally have had `organization_id` as a discriminator, which B does not need because the route token already does that job.
4. **Number ownership** — B: explicitly the platform account, mapped to a Business only via `BusinessMessagingIdentity` (§8).
5. **Usage attribution** — `messaging_profile_id` plus the internal route token/identity id give exact per-Business attribution under B (§9, §10).
6. **Wallet/billing compatibility** — B is one Telnyx account, so provider billing is centralized by construction — "AI Business OS pays Telnyx centrally" (§12 of the messaging contract) is trivially true without any Rollup Billing configuration (§10).
7. **A2P 10DLC** — Unchanged finding: one brand + one campaign per Business, never shared, required under **either** candidate (§4C, §11).
8. **US/Canada compliance** — Unchanged: identical under either candidate.
9. **Customer exit and portability** — Unchanged: port-out is a carrier-level process independent of account architecture (§3 #17).
10. **Suspension blast radius** — **B has no account-level confinement.** This was Round 1's deciding criterion in A's favor; Correction Round 1 does not dispute the fact, it changes which requirement governs the tradeoff. §11.2 no longer states an unqualified guarantee B cannot meet; it now states the two-level rule B **can** meet in full (application/resource level) plus an explicitly accepted risk (account level) — see §7. The commercial cost of eliminating the accepted risk at launch (§1) is judged to exceed its value before revenue exists.
11. **Rate limits** — B: one shared account-wide throughput tier every Business's traffic competes for (Level 2 verification recommended, §4). This is a real, accepted operational constraint at launch, mitigated by per-Business sending limits (§7) rather than by account separation.
12. **Operational complexity** — B is simpler to build now: one account, one key, one profile-management surface, no Managed-Account CRUD/enable-disable/per-account key issuance.
13. **Migration complexity** — Real, not zero, and stated as such (§5.2's outline) — not claimed to be trivial or automatic.
14. **Provider cost** — No per-message carrier-cost difference between candidates.
15. **Agency client support** — B works via `BusinessMessagingIdentity` alone; no Managed Account entity is required to make an Agency's client Businesses individually attributable.
16. **Later BYO compatibility** — Unaffected either way; BYO remains wholly separate (§13).
17. **Telnyx approval requirements** — **This is the deciding criterion for launch.** B requires only a standard verified Telnyx account; it requires no commit plan and no manager-account approval, unblocking Slice 3/4 work immediately.
18. **Whether provider terms support the SaaS/platform use** — Both are affirmatively supported by Telnyx's own documentation (§3 #9); B is exactly the documented ISV pattern ("a single account can create separate messaging profiles and numbers for each customer," §4).

**Why B over A for launch, stated plainly:** criterion 17 (no commercial gate) now governs, because the platform's own locked launch constraints (§1) make a mandatory $1,000+/month fixed cost before proportional revenue exists an unacceptable tradeoff, regardless of A's technical superiority on criterion 10. This is a commercial decision the owner made, not a re-litigation of the underlying Telnyx research, which stands.

### 5.2 Scale stage: migration path to Telnyx Managed Accounts

Retained as a concrete, non-trivial, non-automatic future migration, evaluated by the owner (§1):

1. **Commercial review and Telnyx approval** — the owner commits to a qualifying plan (§3 #2) and Telnyx approves manager-account status (§3 #6) before any of the following steps begins.
2. **Create one Managed Account with Rollup Billing** for the first migrating Business (§3 #1, #3) — Rollup Billing is chosen deliberately and once, since it is immutable after creation.
3. **Create that Managed Account's own dedicated Messaging Profile.**
4. **Recreate or transfer the Business's A2P brand/campaign relationship** as Telnyx's process requires — not assumed to be a simple copy; §18 Q2 is the open question on whether a direct transfer exists at all.
5. **Transfer, port, or reassign the Business's number(s)** only through an officially supported process (port within Telnyx, or the standard number-to-profile reassignment API, §4B) — never an unsupported workaround.
6. **Rotate the Business's `BusinessMessagingIdentity` to the new Managed-Account credentials**, issued and stored exactly as the platform's shared-secret model already requires (§6), scoped now to one Business instead of shared.
7. **Add the new dedicated-account column(s) via an ordinary additive migration** (§8) — e.g. a genuinely provider-neutral `provider_account_reference` column, never named after Telnyx's "Managed Accounts" product — before any Business is cut over, so the column exists platform-wide before it is ever populated. **Then, per migrated Business, flip `provider_mode` to the new dedicated-account case and populate that Business's new column(s) atomically**, in the same transaction that records the new provider identifiers; no window where the record is ambiguous about which mode is authoritative.
8. **Verify webhook routing** against the new Managed Account/Messaging Profile identifiers before cutting outbound traffic over — the fail-closed chain (§9) is re-proven against real values, not assumed to carry over from the fake/shared-account configuration.
9. **Drain in-flight events from the old (shared-account) profile** before fully decommissioning that profile's association with the migrated Business, so no event is lost mid-cutover.
10. **Retain immutable historical attribution** — every ledger/audit row written under the shared-account period keeps its original provider identifiers; migration never rewrites history (mirrors the messaging contract's own migration discipline, §23 of that contract).
11. **Roll back safely if validation fails** — the migrated Business's `BusinessMessagingIdentity` can revert to the prior shared-account values if the new Managed Account fails verification, without data loss.
12. **Migrate incrementally, one Business at a time** — never a bulk cutover; each migration is its own auditable operation, so a single failure never becomes a platform-wide outage.

**This is not free and not automatic.** It requires real engineering work (steps 3–9), carries real risk (a botched cutover could interrupt a Business's messaging), and is gated on the owner's commercial decision (step 1) before any of it begins. No part of this outline is implemented by this document.

---

## 6. Credentials

**Corrected 2026-09-08 (Correction Round 1).** Under Candidate B there is exactly **one** Telnyx credential set for the entire platform at launch, and it must be documented and designed as a **shared, high-value secret** — not as N independent per-Business secrets that merely simulate isolation.

- **Custody:** The platform's single Telnyx `api_key` is platform configuration — encrypted at rest, stored once, never in this or any other contract, never in a log, never in a ledger row, never in a queue payload — exactly as §11.2/§11.3 of the messaging contract require.
- **Never stored per-Business to simulate isolation.** `BusinessMessagingIdentity` (§8) holds no credential of any kind, only opaque, non-secret provider identifiers (a Messaging Profile id, a phone number). Storing a copy of the shared key per Business would create N places for it to leak without adding any real isolation, since it is the same underlying account either way — this is explicitly rejected.
- **Never rendered to any customer role**, in any serialization (T-PROV-1/2, unchanged from Round 1).
- **Customers never log into the platform Telnyx account** and never know or choose the provider — there is no per-Business Telnyx login to offer under this model, unlike Candidate A's (unused, per §10.2) Managed-Account portal access.
- **All Telnyx operations go through exactly one server-side provider adapter** (`app/Library/Messaging/**`, §15) that holds the shared key; no other code path is authorized to call the Telnyx API directly, mirroring the existing seam-discipline pattern already used elsewhere in this repository (e.g. `WebsiteDraftPageService`'s single-writer seam).
- **Least-privilege scoping, only where Telnyx actually documents it.** Telnyx's permission-group model supports category-scoped API keys — e.g. a key restricted to the "Messaging" category (messaging profiles CRUD) rather than full account access (§3 #5's mirror, "Account Management and Billing" part 2, Permission Groups). The platform should create its production key with the narrowest category scope that covers Messaging Profile/number/message operations, as a defense-in-depth measure. **This is not per-Messaging-Profile isolation and must never be described as such** — Telnyx does not document a way to scope a key to one Messaging Profile; a Messaging-category key still spans every Messaging Profile on the account. §18's operational Q7 asks Telnyx directly whether finer scoping exists.
- **Rotation:** the account's webhook signing key rotates via the documented inactive→activate flow (§3 #14), a single account-wide operation under Candidate B — simpler than Candidate A's manager-plus-per-Managed-Account rotation, and another point in B's favor operationally, not just commercially.
- **Design consequence:** because this is one shared secret, its compromise is a platform-wide event by definition. This is the credential-level expression of the accepted platform-provider failure domain (§1, §7) — mitigated by rotation discipline and least-privilege scoping, never eliminated by them.

---

## 7. Business isolation

**Corrected 2026-09-08 (Correction Round 1) — the honest two-level model, matching the corrected §11.2 of the messaging contract exactly.**

### 7.1 Application/resource-level isolation — architecturally achievable under Candidate B, mandatory for Slice 3 completion

**Implementation status, stated precisely so this section is never mistaken for a completion report:**

- **What is approved by this decision:** the architecture (Candidate B) and the isolation *requirements* below. Nothing about Candidate B prevents any of them from being built.
- **What Slice 3 must implement:** every bullet below. None of them exist yet — this document specifies what Slice 3 has to build, not what already runs.
- **What the repository audit found missing today (§14):** there is no `BusinessMessagingIdentity` model, no Messaging-Profile-per-Business assignment, no Business-scoped kill switch, and no fail-closed webhook attribution. The legacy `DLRController::inboundTelnyx()` does the opposite of the required mapping — it defaults an unmatched number to user id `1` rather than rejecting — which is the concrete, present-day gap this section's requirements replace, not extend.
- **What verifies completion:** T-PROV-1/2 and the wider §24 test matrix, run against Slice 3's actual implementation (real adapter or fake, §9's closing note) before Slice 3 is declared complete per the messaging contract's own §21.1 exit criteria.

**Requirements Slice 3 must implement:**

- **One Messaging Profile per Business**, never shared, created and owned by the platform.
- **One authoritative number-to-Business mapping** — `BusinessMessagingIdentity` (§8) must be built as the single source of truth; no code path may be authorized to infer a Business from a number any other way.
- **Business-scoped wallet and usage records** — every reservation/settlement (RFC-005) must attribute to exactly one Business via the internal identity, independent of the fact that the underlying Telnyx account is shared (§10).
- **Business-scoped kill switch** — the platform must be able to pause one Business's sending (e.g. on a compliance signal, §12) without touching any other Business's Messaging Profile or numbers.
- **Fail-closed webhook routing** keyed to the internal identity, never to a shared discriminator alone (§9).
- **No customer-accessible platform credentials** — satisfies §11.2's "Support boundary" row once built: the platform, not the customer, holds the one Telnyx relationship; customers must never receive a Telnyx login.
- **Per-Business rate/volume controls** — to be enforced at the application layer (message velocity limits, §12) since the underlying Telnyx throughput tier is shared, not per-Business (§4B).
- **Fail-closed conflict detection** on every inbound event (§9).

Once Slice 3 implements every item above, a compromise, configuration error, single number's suspension, a single 10DLC campaign's suspension, or a Business-level pause will be contained to that Business — this is the acceptance bar §11.2's application-level row states, and T-PROV-1/2 (§24 of the messaging contract) is how the platform proves it, not something true of the repository as it stands today.

### 7.2 Platform-provider failure domain — explicitly accepted for launch, not eliminated

Because every Business shares one Telnyx account, Candidate B genuinely **cannot** guarantee that a platform-wide Telnyx-side event — account suspension, a KYC re-review, a payment-method failure, a credential compromise, or a Telnyx AUP enforcement action against the shared account — stays confined to one Business. This is stated here exactly as it is stated in §11.2: a known, disclosed, owner-accepted launch-stage risk, not something this architecture eliminates or something this document claims is eliminated.

**Mitigations (operational, not architectural elimination):**

- Platform-only credentials (§6), never customer-held.
- Least-privilege, category-scoped API keys where Telnyx's permission-group model supports it (§6).
- Regular key rotation.
- Signed-webhook verification on every inbound event (§9).
- Strict internal enforcement of Telnyx's Acceptable Use Policy (§11) across every Business's traffic, since one Business's AUP violation risks the shared account.
- Per-Business anomaly detection (unusual volume, complaint-rate spikes).
- Business-level sending/volume limits (§7.1) that also reduce the chance any single Business drives the shared account into an AUP or throughput problem.
- A rapid platform-wide kill switch, for the rare case a shared-account-level response is actually required.
- Telnyx account-balance monitoring (§10) so the shared balance never silently runs dry mid-flight.
- Compliance review before activating any new Business's messaging capability.
- Immutable attribution/audit records, so a shared-account incident can still be reconstructed per-Business after the fact.
- The documented migration path to Telnyx Managed Accounts (§5.2), which is the only investigated mechanism that removes this specific risk, when the owner judges it worth the commercial cost.

---

## 8. Number ownership

**Corrected 2026-09-08 (Correction Round 2) — the launch schema now stores only what Candidate B's shared-account implementation actually needs. No dormant, future-facing, or Managed-Account-specific field is added merely to avoid a later migration; a later additive migration is normal, safe schema evolution, not something the launch schema needs to pre-empt.** Recommended internal record (new, Slice 3-owned): **`BusinessMessagingIdentity`**, one row per Business's default messaging identity, holding only opaque, non-secret provider identifiers (§11.3: "admin-visible only"), never a credential (§6):

| Column | Purpose |
|---|---|
| `business_id` | FK, unique per active identity (§10.4: at most one active default identity per Business) |
| `provider` | closed enum, `telnyx` only at launch |
| `provider_mode` | closed enum with exactly two cases at launch: `managed_shared` (Candidate B, the launch mode) or `byo` (§13). No third case is pre-declared or reserved (§5.2) |
| `provider_messaging_profile_id` | the platform account's Messaging Profile `id` dedicated to this Business (opaque) |
| `provider_phone_number_id` | the assigned number's Telnyx-side id (opaque) |
| `phone_number` | the E.164 number itself (customer-visible, "your business phone") |
| `webhook_route_token` | an opaque internal UID (never a Telnyx identifier) embedded in this Business's webhook URL path — the primary routing key, §9 |
| `provider_brand_id`, `provider_campaign_id` | the Business's own 10DLC brand/campaign (opaque, admin-visible only) — required by A2P regardless of account architecture (§11) |
| `status` | activation state machine value (§10.1 step 1, §7.7 of the messaging contract) |
| `created_at`/`updated_at`, audit fields | |

**No Managed-Account identifier of any kind — dedicated, nullable, or otherwise — is part of the launch schema.** A dormant column that exists only "so a migration isn't needed later" is itself a misleading artifact: it implies a capability (dedicated-account support) that is not built, is not authorized by this document, and may never be built at all. Additive migrations are a normal, low-risk, well-understood Laravel operation (the messaging contract's own §23 migration discipline already assumes exactly this pattern for every other slice) — there is no real cost being avoided by adding the field early, only a real cost being incurred (a column every reader has to understand the meaning, or lack of meaning, of).

**If a future slice implements dedicated provider accounts** (§5.2), that slice adds what it needs through its own ordinary additive migration at that time: a new `provider_mode` enum case, and a new column using genuinely provider-neutral terminology — e.g. **`provider_account_reference`**, never `managed_account_id`, `telnyx_managed_account_id`, or any name tied to Telnyx's "Managed Accounts" product specifically, since a different future provider or provider capability could reuse the same seam. That migration is additive only: it adds a column and a case, it does not rewrite `BusinessMessagingIdentity`'s existing columns, and (per the requirement below) it does not require rewriting the wallet, conversation, automation, or customer-facing layers.

No Telnyx identifier of any kind is ever rendered to a customer; the `phone_number` field alone is customer-visible, exactly matching §10.2's forbidden-fields list.

This record is the single join point the webhook router (§9) and `SendMessageAction` (§10.4 of the messaging contract) both resolve through, under either `provider_mode` case that exists at any given time. The provider adapter (§6) is written against `provider_mode` and the interface in `app/Library/Messaging/Contracts/**` (§15) — not against any specific column set — specifically so a future migration can add a new mode and its own new column(s) without any caller of `SendMessageAction` or the webhook router needing to change. That is what makes the future migration additive rather than a rewrite; it does not require this document to add the future column now.

---

## 9. Webhook routing

**Corrected 2026-09-08 (Correction Round 1) — resolution chain adapted for a single shared platform account, where `organization_id` is the same value on every event platform-wide and therefore cannot be used as a per-Business discriminator (it discriminates only "is this our account," not "which Business").** Resolution now leans on the internal route token as the primary key, exactly as the task requires:

> **Telnyx event → provider account/profile/number → `BusinessMessagingIdentity` → Business**

**Fail-closed resolution chain, in order, each step required before the next:**

1. **Verify the Telnyx webhook signature** (`telnyx-signature-ed25519` against the account's stored public key) and timestamp presence before touching any business logic (§3 #14).
2. **Apply replay/idempotency protection** — reject a `telnyx-timestamp` older than a short bounded window (Telnyx documents no replay-protection primitive beyond the timestamp itself, §3 caveat; the window is this platform's own defense) and de-duplicate by the event's own id (persisted per step 9 below) so a Telnyx retry (§3 #15, one retry after a 2000ms timeout) never double-processes.
3. **Read the internal opaque route token** from the webhook URL path — every Business's Messaging Profile is configured with its own `webhook_url` (§4B: `webhook_url`/`webhook_failover_url` are profile-level fields) encoding that Business's `BusinessMessagingIdentity.webhook_route_token`. This is the **primary** lookup key, not a cross-check, because under a shared account `organization_id` alone cannot do this job.
4. **Resolve the provider Messaging Profile identifier** (`messaging_profile_id`, present on every event, §3 #15) from the payload.
5. **Resolve the destination/from phone number** from the payload as appropriate for inbound vs. outbound delivery-status events.
6. **Require all available identifiers to agree on exactly one `BusinessMessagingIdentity`** — the route token's own row, the payload's `messaging_profile_id`, and the payload's phone number must all point at the same row. Disagreement is a **conflicting mapping**, not a "best guess," and fails closed.
7. **Resolve that identity to exactly one active Business.**
8. **Reject** unknown, conflicting, inactive, or cross-Business mappings — see the table below.
9. **Persist the provider event id** (from the payload, §3 #14's example payload has a top-level `id`) for idempotency, following the newer `agency_prospect_messages.provider_message_id` unique-constraint pattern already in this repository (§14), never the legacy `reports.status`-string-packing pattern.
10. **Never fall back to a global/default Business.** This is stated explicitly because the audit found the existing legacy path does exactly this today (`DLRController` defaults to user id `1` on no match, §14) — that is the precise defect this chain replaces, not extends.

| Case | Check |
|---|---|
| Invalid signature | Reject before any business logic runs (step 1) |
| Replay | Reject a stale/duplicate timestamp or already-processed event id (step 2) |
| Unknown number/profile/route-token | No matching `BusinessMessagingIdentity` row → reject, log, alert — **never** default to any Business (step 8, 10) |
| Conflicting mapping | Route token, `messaging_profile_id`, and phone number do not all agree on one row → reject (step 6) |
| Cross-Business mismatch | Resolved Business does not match the route token's own `business_id` → reject |
| Inactive Business | Business is soft-deleted/deactivated → reject, never process |
| Suspended identity | `BusinessMessagingIdentity.status` is not active → reject, never process, never silently re-activate |

**This is a design, not an implementation.** §21.2's fake can implement this exact chain against an in-memory `BusinessMessagingIdentity` table today (satisfying T-PROV-1/2, §24 of the messaging contract), with zero Telnyx-specific assumption beyond "one opaque profile/number/route-token identifier resolves to one Business." The existing `AgencyProspectingWebhookController::telnyx()` pattern (HMAC-token-scoped URL, tenancy resolved from the URL's own token rather than trusted payload fields alone, §14) is the closest existing precedent in this repository and should be followed, not `DLRController`.

---

## 10. Wallet/provider billing

**Corrected 2026-09-08 (Correction Round 1) — Rollup Billing no longer applies; the platform account's own single balance is the entire mechanism.**

**Provider billing facts (§3 #19):**

- Telnyx itself is prepaid, pay-as-you-go, 4-decimal-place billing, no contracts (§3 #19) — under Candidate B this applies directly to the **one platform account**, with no manager/Managed-Account layer at all.
- There is exactly one Telnyx balance. It funds every Business's managed sending. Telnyx's own auto-recharge (if enabled on this account) and AI Business OS's own customer-facing auto-recharge (§12.2 of the messaging contract) are **two separate systems that must never be conflated**: Telnyx's auto-recharge (if used) tops up the platform's own prepaid balance from the platform's own payment method; AI Business OS's auto-recharge tops up a customer's internal wallet from that customer's payment method. A customer's wallet showing "funded" says nothing about whether the shared Telnyx balance can actually send right now.
- 10DLC fees (brand/campaign registration and monthly maintenance, §3 #13) are carrier pass-through fees charged against the one platform account regardless of which Business's campaign incurred them.

**Mapping onto the intended internal model (§5 of the task), confirmed compatible under Candidate B:**

1. Customer funds AI Business OS through Stripe → unchanged, RFC-005.
2. AI Business OS reserves the customer's internal (Business-scoped) wallet → unchanged, `UsageWalletManager::reserve()` (§12.2 of the messaging contract).
3. AI Business OS sends through the Business's own dedicated Messaging Profile → the platform's one shared `api_key` (§6) performs the Telnyx API call, scoped to that Business's Messaging Profile/number; the customer never touches this credential.
4. Provider cost is attributed using message/profile/number identifiers → the route token, `messaging_profile_id`, and phone number (§9) resolve every event to exactly one Business, so cost attribution does not depend on the account being shared.
5. AI Business OS settles the customer-visible retail amount later under the approved rate card → unchanged, `commit()`, gated on §28.1a exactly as before.
6. AI Business OS pays Telnyx through the shared platform account → true by construction under Candidate B; no Rollup Billing configuration is needed because there is only ever one account and one balance to begin with.

**Platform-balance monitoring is now a load-bearing operational requirement, not a nice-to-have.** Because every Business draws from the same Telnyx balance, a customer's own wallet can appear fully funded (AI Business OS's own ledger, RFC-005) while the platform's own Telnyx balance is simultaneously too low to actually place the send — these are two different balances, and only the platform's own monitoring closes that gap. Recommended: alert well before the platform balance is exhausted, and treat "Telnyx balance sufficient" as its own precondition checked before attempting a send, distinct from and in addition to the customer wallet reservation check (§10.6 of the messaging contract already requires reserving the customer wallet first; this adds a platform-side check that must also pass).

**Cost/usage attribution identifiers available:** `messaging_profile_id` on every message event (§3 #15) plus the internal route token (§9); the 10DLC fee schedule (§3 #13) for registration/maintenance line items; standard per-message carrier fee tables are published (not reproduced in full here — embedding current numbers into a rate card is §28.1a's job, not this document's).

**When message cost becomes final:** unchanged finding from Round 1 — not explicitly documented as a single sentence in the sources read; the `message.finalized` event type (§3 #14's payload example) and its `cost` field are the closest confirmed signal, but the exact settlement timing relative to an end-of-month invoice reconciliation is **unclear** (§18, classified as operational, not a Slice 3/4 blocker).

**Insufficient platform-balance behavior:** unchanged finding from Round 1 — not found in the sources read with Telnyx-confirmed specificity (one unopened article title only: `8648864-what-happens-with-my-numbers-after-my-account-gets-abolished-for-negative-balance`, not opened in either research pass). Because AI Business OS's own wallet reservation is designed to always reserve before any provider call, the platform's own Telnyx balance should not go negative if platform-balance monitoring (above) keeps it safely ahead of the reservation total; this is an operational buffer this document recommends, not something independently documented by Telnyx.

---

## 11. A2P/compliance

**Preserved unchanged from the initial research pass — this section was already correctly orthogonal to the A-vs-B choice, and Correction Round 1 confirms it stays that way.** Under launch Candidate B, every Business still gets its own separate 10DLC brand and campaign inside the one shared platform account, exactly as it would under Candidate A; the account architecture never changes this requirement.

**Confirmed, decisive, and orthogonal to the A-vs-B choice (§3 #11, #12, #13, #16):**

- AI Business OS is an ISV by Telnyx's own definition ("a SaaS product that sells messaging services to doctor's offices is considered an ISV" — directly analogous to a Business).
- **Every Business needs its own separate 10DLC brand and campaign.** A number may never be shared across brands/campaigns; doing so risks carrier fines and traffic blocking, with no special ISV exception available outside a rare, franchise-style, T-Mobile-approved number-pooling agreement (not our shape).
- **Sole proprietor vs. EIN:** both paths exist. Sole Proprietor registration (no EIN required) suits an individual-operator small business; standard registration requires an EIN. The platform's onboarding flow (§10.1 step 3 of the messaging contract, "Business details in normal language") must therefore branch on whether the Business has an EIN, and populate the correct brand `Entity Type`.
- **Required business information:** legal name, a permanent email, a mobile number capable of receiving SMS (for OTP-PIN identity verification, Sole Proprietor path only), a physical address (no PO boxes), and a URL demonstrating legitimacy (website or professional social profile).
- **Opt-in/STOP/HELP:** explicit, freely-given opt-in is required (specific invalid patterns are named: harvesting a number for one purpose then messaging for another, buying/renting a lead list, silently upgrading a transactional opt-in into a recurring campaign). STOP/unsubscribe must be honored within 24 hours of receipt. This maps directly onto the messaging contract's already-locked T-STOP-1/2.
- **Sample-message requirements:** at least two sample messages per campaign, each including the brand name and explicit opt-out instructions (e.g., "Reply STOP to unsubscribe").
- **Approval states:** brand → campaign → number assignment, in that order; Sole Proprietor brands require a manual OTP-PIN email verification loop (24-hour window); campaign approval is typically 3–7 business days; a campaign can independently reach a `DORMANT`/suspended TCR status after 15 days of inactivity with no assigned active number, recoverable by reassigning numbers (§3 #16).
- **Registration/recurring fees:** brand ≈$4.50 one-time; campaign review $15 per submission (repeat submissions during vetting can each incur this — Telnyx recommends emailing `10dlcquestions@telnyx.com` in advance if resubmission should be avoided); monthly campaign fee $1.50–$10 depending on declared use case (never declare a false use case to lower this — carriers audit and fine for it). No Telnyx markup on any of these. These are exactly the line items §10.6 of the messaging contract's itemised upfront estimate must include.
- **Canadian traffic:** not covered by 10DLC (a US carrier registry); no Canadian-specific compliance detail was retrieved in this pass beyond CASL's general applicability to commercial electronic messages sent to/from Canada (§3 #18) — **this is incomplete** and is a candidate follow-up, not a launch blocker, since §28.4 already scopes Slice 4's launch countries decision separately.
- **Prohibited categories:** the Acceptable Use Policy names sexual/pornographic content, harassment, firearms, alcohol/tobacco/drugs, several categories of "High Risk Financial" content (loans, credit repair, debt collection, cryptocurrency including OTPs), gambling, investment offers, unsolicited real-estate outreach, MLM, and persistent third-party OTP relaying. This is a **content policy** an AI Business OS customer could violate through their own automation copy; it does not change the architecture, but it is a fact the AI-generation guardrails (§5.2/§8.5 of the wider Website contract's sibling documents, and this contract's own §5's forbidden-content rules) should be cross-checked against.
- **Retention obligations:** not explicitly documented in the sources read for 10DLC-specific retention beyond the platform's own general audit/ledger requirements already contracted elsewhere. **Unclear**, candidate follow-up.
- **ISV/platform model confirmed:** yes — Telnyx explicitly documents the ISV reseller model, including a recommended per-end-user migration sequence (create Messaging Profile → dedicated number → 10DLC brand → 10DLC campaign → assign campaign to number) that maps almost verbatim onto §10.1's own onboarding step table.

**This document gives no legal guarantee.** The distinction the task requires is preserved: Telnyx's *registration mechanics* (brand/campaign creation, fees, states) are provider requirements, evidenced above; whether a specific customer's business model, message copy, or consent flow is *lawful* under TCPA/CASL/state law is legal-counsel territory this document does not and cannot resolve.

---

## 12. Suspension and compliance containment

**Corrected 2026-09-08 (Correction Round 1) — five distinct failure modes, kept explicitly separate so the platform never confuses a narrow, Business-scoped issue with the one genuinely shared risk.** As with §7.1, the "Business-scoped" column below states the **target property Slice 3 must build and preserve**, not a property the current, pre-Slice-3 repository already exhibits — §14's audit confirms none of the underlying isolation mechanisms (per-Business Messaging Profile, `BusinessMessagingIdentity`, the kill switch) exist yet.

| Failure mode | Scope | Required isolation once Slice 3 is built (Candidate B) |
|---|---|---|
| **Individual number suspension/flagging** (e.g. a single number flagged for spam by a carrier) | One phone number | Must be Business-scoped — only the Business owning that number is affected; the platform reassigns or replaces the number within that Business's Messaging Profile |
| **Individual 10DLC campaign suspension** (15-day dormancy rule, §3 #16) | One Business's brand/campaign | Must be Business-scoped — reactivation is the documented two-step number reassignment (§3 #16), and must not touch any other Business's campaign |
| **Messaging Profile configuration failure** (e.g. a misconfigured webhook URL on one profile) | One Business's Messaging Profile | Must be Business-scoped — one Profile's misconfiguration must not affect another Profile's delivery |
| **Business-level AI Business OS pause** (the platform's own kill switch for one Business, §7.1) | One Business, applied by the platform itself | Must be Business-scoped by design — this is the platform's own control to build, not a Telnyx-side event |
| **Platform Telnyx account suspension** (KYC re-review, payment-method failure, AUP enforcement against the shared account, §3 #18/#19) | The entire shared platform account | **Cannot be isolated — every Business is affected.** This is the accepted platform-provider failure domain (§1, §7.2), not eliminated by Candidate B and not something any amount of Slice 3 engineering closes. |

**The first four must remain, and under Candidate B do remain, Business-scoped wherever Telnyx's own mechanisms permit** (number-level, campaign-level, and profile-level actions are all independently scoped by Telnyx itself, §3 #10/#16; the Business-level pause is the platform's own control, not Telnyx's). **The fifth is the one genuinely shared, accepted risk** — no application-level design closes it; only the §5.2 migration to Managed Accounts does, at its commercial cost.

**Recommendations to keep the first four narrow in practice, not just in theory:**

- One A2P brand/campaign per Business where required (§11) — never mix unrelated Businesses under one campaign merely to save setup effort, even though it is technically possible within one shared account, because doing so would collapse the campaign-level isolation row above back into a shared one.
- Business-level message velocity limits (§7.1), so one Business's traffic spike cannot itself trigger a carrier-side response against the shared account.
- Complaint/opt-out monitoring per Business (§11's AUP requirements), since AUP violations are what actually put the shared account at risk (row 5).
- Automatic Business-level pause on serious compliance signals (repeated complaints, a carrier filtering warning tied to one Business's traffic) — applied narrowly to that Business, before it becomes a shared-account problem.
- Platform-owner alerting on any of the above, and separately on any sign of a shared-account-level issue (row 5), since the response differs: a Business-level issue is handled by the platform automatically; a shared-account issue needs the platform owner's direct attention.
- **No silent rerouting through another Business's Profile or number, ever** — if a Business's own Profile/number is unavailable, the correct behavior is to fail closed and alert, never to borrow another Business's identity to keep messages moving, which would violate §11.2's per-Business-isolation and no-shared-identity requirements outright.

**Offboarding a Business** under Candidate B means deactivating its `BusinessMessagingIdentity` (status transition, §8) and releasing or reassigning its Messaging Profile/number — port-out remains available to the customer independent of that action (§3 #17); this interaction was not separately Telnyx-documented for the shared-account case either and remains a candidate follow-up (§18, operational Q8) before Slice 4 implements offboarding.

---

## 13. BYO boundary

Unaffected by this correction. §11.4/§11.5 of the messaging contract already define BYO as a wholly separate, customer-owned-credential path (`CustomerBasedSendingServer`, already implemented in the repository per the audit, §14) that never touches the platform's own shared Telnyx account (or, later, a Managed Account under §5.2's migration), never reserves/debits the wallet for transport, and remains Agency-only, gated behind `manage_advanced_provider`. The repository's existing `MessagingChannelsController` (B2) is exactly this today; per §22.1 Slice 3's allowlist and §27 C-5, its docblock's rules become the description of the **future BYO path**, while the **default** path for Core/Growth/Agency-default customers becomes the new shared-platform-account managed flow this document describes (§5.1).

---

## 14. Repository impact

**Preserved unchanged from the initial research pass — every finding below is independent of the A-vs-B choice and remains accurate.** Full mechanical inventory (background repository audit, read-only, no files modified):

- **Every Telnyx credential field today** lives in the generic `sending_servers` table (`app/Models/SendingServer.php`, ~250 provider `TYPE_*` constants including `TYPE_TELNYX`/`TYPE_TELNYXNUMBERPOOL`), with **no encryption** on any credential column (`api_key`, `c1` = `messaging_profile_id`, `c2` = `messaging_connection_id`) — a real gap against §11.3's "encrypted at rest" requirement that Slice 3 must close for the new managed path (the existing BYO path inherits this gap and is explicitly out of Slice 3's remit to fix retroactively, since BYO is being relocated, not rebuilt, per §11.4).
- **Provider dispatch today** is a single 18,188-line Eloquent model (`app/Models/SendCampaignSMS.php`) with a raw-`curl` `switch` statement per provider, duplicated three times (plain SMS, voice, MMS) — no abstraction/interface exists to build the new managed path on top of; a newer, cleaner example already exists in the same repository (`app/Library/AgencyProspecting/ProviderAgencyProspectingMessageSender.php`, a `match`-based sender using Laravel's `Http` facade) that Slice 3's provider-agnostic interface (§21.2) should resemble far more than the legacy god-model.
- **Inbound webhook routes today** are public and unauthenticated by design (`routes/public.php`'s own header comment: "No middleware will not affect these routes"), and Business attribution is fragile: `DLRController::inboundTelnyx()` resolves a `PhoneNumbers` row by raw number match and **defaults the owning user to id `1` if no match is found** — a real "unknown number" fail-**open** behavior that is the exact opposite of what §9 of this document (and T-PROV-1/2) requires for the new managed path. The newer `AgencyProspectingWebhookController::telnyx()` pattern (HMAC-token-scoped URL, tenancy resolved from the URL's own token rather than trusted payload fields) is the right existing precedent to build the new managed-messaging webhook on, not `DLRController`.
- **Provider message ID / delivery status today**, for the legacy path, is packed into a single `reports.status` string as `"{Status}|{message_id}"` (`SendCampaignSMS.php:1404`) — not a queryable identifier. The newer `agency_prospect_messages` table already has a proper, uniquely-constrained `provider_message_id` column used for idempotency — this is the pattern `BusinessMessagingIdentity`/its associated message log should follow, not the legacy `reports` shape.
- **No `BusinessMessagingIdentity` model, `app/Library/Messaging/**`, `config/messaging.php`, or `app/Enums/Messaging/**` exist yet** — confirmed absent, clean for Slice 3 to introduce exactly as its allowlist (§22.1 of the messaging contract) already assumes.
- **No usage-wallet hook exists on any message send today** — `UsageWalletManager` is used only for a coarse, read-only entitlement-capacity check in the new Agency Prospecting jobs, never a reserve/debit tied to an actual SMS send. Slice 3's "measurement without a rate" (§21.2) and Slice 4's real reservation/debit are both genuinely new wiring, not an extension of an existing hook.
- **`MessagingChannelsController`'s existing test suite** (`tests/Feature/Business/MessagingChannelsTest.php`, 790 lines) already proves the B2 tenancy/credential-non-leak/cross-Business-isolation model the new managed path must preserve when B2 is relocated to Settings → Advanced as the BYO path (§11.4).

**How the current B2 customer-entered model becomes the future Agency BYO path:** today, `MessagingChannelsController`'s docblock (quoted in full by the audit) describes the *only* model that exists — a customer enters their own Telnyx/Twilio credentials directly, and B2 builds a Business-scoped `SendingServer` from them. Under this decision, that entire flow does not change in shape; it changes in **position and default status**. It moves from the normal onboarding surface into `Settings → Advanced`, becomes reachable only behind `manage_advanced_provider` (Agency-only, §11.4), and stops being the only path — the new managed path (shared-platform-account-backed at launch, §5.1, credential-free from the customer's point of view) becomes the default for Core/Growth and Agency-default. No code in `MessagingChannelsController` needs to be rewritten for this repositioning beyond its route/menu placement and gating; §27 C-5 already requires Slice 3's own contract to restate its rules explicitly, since no standalone B2 contract document exists to correct in place.

---

## 15. Slice 3 allowlist recommendation

**Corrected 2026-09-08 (Correction Round 1).** This document does not authorize Slice 3; it confirms the messaging contract's existing §22.1 Slice 3 allowlist is **already correctly shaped**, and — now that the launch mechanism carries no commercial/approval gate (§1) — Slice 3 may build its **real** (non-fake) provider adapter against Candidate B immediately, not only the fake:

- `app/Library/Messaging/**` (new) — the provider-agnostic interface (send, number search, number order, registration submit, status callback) in **platform** vocabulary, per §21.2, with `BusinessMessagingIdentity` as its central resolved record (§8 above) and `provider_mode` as the seam the future §5.2 migration turns on.
- `app/Library/Messaging/Contracts/**` (new) — the interface itself. Slice 3 implements both a deterministic fake (mirroring `FakeGoogleBusinessProfileReadClient`, for tests) **and** the real Telnyx-backed adapter against the shared platform account (§6) — the real adapter no longer needs to wait on any external gate; it only needs real credentials deliberately supplied before it is ever actually invoked against Telnyx.
- `app/Models/BusinessMessagingIdentity.php` (new) — exactly the shape in §8; no Managed-Account-specific column of any kind, not even a dormant nullable one, is part of this launch migration.
- `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` (existing) — repositioned to the BYO/Advanced path per §13, not rewritten.
- `app/Enums/Messaging/**` (new) — a closed provider enum (`telnyx` only at launch), a `provider_mode` enum (`managed_shared`, `byo` at launch), and an identity-status enum.
- `resources/views/customer/business/MessagingChannels/**` and `resources/views/customer/settings/advanced/**` (new) — the relocated BYO surface.
- `config/services.php`, `config/messaging.php` (new) — no live Telnyx credential belongs in either file per §11.3; `config/messaging.php` should hold only the closed provider list, the `provider_mode` default (`managed_shared`), and non-secret defaults.

**Nothing about the allowlist's paths changes as a result of this correction — it is confirmation, not a new allowlist.** What changes is how far Slice 3 may go against those paths: real (non-fake) implementation against Candidate B is now in-scope, not blocked. Per §21.2 (already locked, and unaffected by this correction), the forbidden list stands: no *assumed* Managed-Account-specific structure of any kind — no Managed-Account identifier column (dormant, nullable, or otherwise), no per-Managed-Account credential model, no migration that hardcodes a future account structure Telnyx hasn't confirmed the shape of — is encoded anywhere the launch identity is used. §8's later, genuinely-additive migration (adding a provider-neutral `provider_account_reference` column, only if and when that future slice is authorized) is what keeps §5.2's migration possible without a rewrite — not anything added to the schema now.

---

## 16. Slice 4 prerequisites

**Corrected 2026-09-08 (Correction Round 1) — the commit-plan/approval precondition Round 1 added to Slice 4 is withdrawn; Slice 4's prerequisites revert to exactly what the messaging contract already stated, unaffected by this decision.** Slice 4 requires **3 and 5 complete, and §28.3, §28.4, and §28.1 all recorded** (messaging contract §21.1). This document resolves §28.3 in full for launch, with **no additional Telnyx commercial or approval precondition** — that precondition applies only to the future §5.2 migration, never to launch-stage Slice 4:

- §28.3 is now recorded (this document, corrected). No commit-plan decision and no Telnyx approval stand between Slice 4 and provisioning a real number under Candidate B.
- §28.4 (launch countries/compliance scope) and §28.1 (retail rates) remain exactly as previously gated — this document does not touch either.
- Before Slice 4 provisions its first real number for a real Business, the platform must have its **own** verified Telnyx account (standard verification, Level 2 recommended given shared account-wide throughput, §4B), the fail-closed webhook router (§9) built against real Messaging-Profile/route-token identifiers rather than the fake's placeholder ones, and platform-balance monitoring (§10) live before any customer-facing send depends on it.
- **Separately, and explicitly not a Slice 4 blocker:** the §5.2 migration to Managed Accounts has its own precondition (owner commercial commitment + Telnyx approval, §3 #2/#6) that gates *that migration project* only, whenever the owner chooses to start it — it does not gate Slice 3, Slice 4, or any other slice in this contract.

---

## 17. Risks

**Corrected 2026-09-08 (Correction Round 1) — reordered so the launch-stage accepted risk leads, and the commit-plan item is reframed as a future-migration cost rather than a launch blocker.**

- **Shared-account blast radius (accepted for launch, §7.2):** every Business shares one Telnyx account and one credential. A platform-wide Telnyx-side suspension, KYC re-review, payment-method failure, or credential compromise affects every Business simultaneously. This is the central, explicitly disclosed tradeoff of choosing Candidate B for launch — mitigated operationally (§7.2), never eliminated, and closed only by the §5.2 migration.
- **Shared throughput contention:** every Business's traffic competes for the same account-wide verification-tier throughput ceiling (§4B) — a high-volume Business (or several at once) can degrade delivery for others sharing the account. Mitigated by per-Business velocity limits (§7.1, §12) but not eliminated.
- **Single-credential compromise:** the one shared `api_key` (§6), if compromised, can act across every Business's numbers/profiles — mitigated by least-privilege scoping and rotation, not eliminated.
- **10DLC operational load scales with Business count:** every Business, regardless of account architecture, needs its own brand + campaign + fees + a 3–7 business day approval wait (or a 24-hour manual OTP loop for sole proprietors) before it can send compliant traffic — a real onboarding-latency and per-Business-cost factor for §10.6's upfront estimate, not a one-time platform cost, and unaffected by this correction.
- **Dormancy risk:** a low-activity Business's 10DLC campaign can auto-suspend after 15 days (§3 #16); the platform should monitor this per-identity and treat it as a distinct alert from the shared-account risk above, or a customer could see "your number stopped working" with no account-wide cause.
- **Future migration cost and timing (deferred, not eliminated):** the §5.2 migration to Managed Accounts, whenever the owner chooses to start it, carries a real $1,000+/month-and-up recurring commercial cost plus non-trivial engineering work (§5.2's 12 steps) and its own scale ceiling (the default 1000-managed-sub-account limit, §3 #4, raisable on request). This is deferred by this correction, not resolved — the owner will need to revisit it when a migration trigger (§1) is judged to apply.
- **Unclear per-Managed-Account throughput independence:** relevant only to the future migration's value proposition — if Managed Accounts turn out to inherit a single throughput ceiling from the manager rather than each having its own, migrating would not fully remove the throughput-contention risk above either. Marked unclear in §4/§18.
- **Access-method risk (this document's own methodology, unchanged from Round 1):** direct primary-source verification was blocked by sandbox network policy; the GitHub-mirror workaround (§3) is first-party but is still one hop from the live page. A human with direct portal/API access should spot-check the handful of "Proven" claims most load-bearing for this decision (the ISV/single-account messaging-profile pattern, and the AUP/suspension consequences) before launch traffic depends on them.

---

## 18. Provider questions

**Corrected 2026-09-08 (Correction Round 1) — reclassified by what each question actually blocks, per the correction's own instruction not to leave every question as a generic blocker.** None of these block Slice 3 or Slice 4 as newly scoped by this correction; that is itself the point of separating them out.

**Blockers for Slice 3:** none. The real (non-fake) Candidate B adapter can be built without any of these answers.

**Blockers for Slice 4:** none additionally introduced by this document. Slice 4 remains gated only by §28.4, §28.1, and Slice 5 (§16), none of which these questions touch.

**Blockers only for a future Managed Account migration (§5.2) — must be answered before that migration is planned in detail, not before launch:**

1. "Does a Managed Account have its own independent message-throughput/verification tier, or does it inherit the manager account's tier and share the same ceiling with every other Managed Account under that manager?" (also informs whether migration actually removes the throughput-contention risk, §17)
2. "Can a phone number and its 10DLC campaign be transferred directly from one Managed Account to another (or from a shared platform account into a new Managed Account) without a port-out/port-in cycle?" — directly informs §5.2 step 5.
3. "What happens to a Managed Account's numbers and active 10DLC campaigns if that Managed Account's balance goes negative or its payment method fails, specifically (not the general-account article found, which does not appear to be Managed-Account-specific)?"
4. "For an ISV managing many end-user Managed Accounts, is there a bulk/managed workflow for 10DLC brand and campaign creation, or must each be created one at a time via the standard single-brand API per Managed Account?" — informs the cost/effort of §5.2 step 4 at scale.
5. "Does Telnyx offer a startup/partner program providing Managed Accounts access below the standard $1,000/month commit threshold?" — directly relevant to when the owner should re-evaluate the migration trigger (§1).

**Operational questions that do not block design, but should be answered before they become customer-facing surprises:**

6. "For a message sent from this account, at what exact point does its cost become final and immutable against our own ledger — the `message.finalized` webhook event, or a later invoice/reconciliation step — and can the provider price for an in-flight message change between send and that finalization?" (informs §10's platform-balance monitoring and §28.1a's rate-card timing rules, does not block Slice 3's interface design)
7. "Does a Messaging-category-scoped API key (§6) support any narrower scoping than the whole account's Messaging Profiles, e.g. to a named subset of profiles?" (informs credential least-privilege design, §6; the shared-secret model in §6 is correct either way this is answered)
8. "Does releasing or deactivating a Business's Messaging Profile/number interact with an in-flight port-out request (e.g., does deactivation block a pending port-out, or are they independent)?" (informs §12's offboarding flow, not required before Slice 3/4 design)

---

## 19. Gate status

**Corrected 2026-09-08 (Correction Round 1). §28.3: resolved for launch, with no outstanding commercial or approval precondition blocking Slice 3 or Slice 4.**

- **Launch architecture decision:** resolved. Selected mechanism: **one shared, platform-owned, Pay-as-you-go Telnyx account, with one dedicated Messaging Profile and dedicated phone number(s) per Business**, resolved through the provider-neutral `BusinessMessagingIdentity` record (§8).
- **Slice 3 provider foundation may be implemented against this architecture now** — the real adapter, not only the fake, per §15.
- **Provider calls remain disabled until credentials/configuration are deliberately supplied.** Nothing in this correction authorizes an actual Telnyx API call, account, number, brand, campaign registration, or rate activation — those remain exactly as prohibited as in Round 1.
- **No telecom retail rate may activate until §28.1a is approved** — unchanged.
- **Slice 4 remains blocked on its existing prerequisites only:** §28.4 (launch countries/compliance), §28.1 (retail rates), and Slice 5 (funding) — per §16, unchanged from the messaging contract's original §21.1, with Round 1's added commit-plan/approval precondition withdrawn from Slice 4 entirely.
- **Telnyx Managed Accounts are a documented future scale-stage migration (§5.2), not a launch prerequisite for any slice in this contract.**
- **The $1,000/month-and-up commit-plan requirement and Telnyx's manager-account approval no longer block MVP Slice 3 or Slice 4** — they gate only the future §5.2 migration, whenever the owner chooses to start it.
- **Provider-level account-wide suspension remains a disclosed, accepted launch risk** (§7.2, §12), mitigated operationally, never claimed to be eliminated by the launch architecture.
- The contract's §11.2 and §28.3 rows are amended narrowly, in place, to record this correction (see the diff to `CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` in this same commit).

---

`TELNYX MANAGED MESSAGING ARCHITECTURE DECISION — CORRECTION ROUND 1 READY FOR HUMAN/CHATGPT REVIEW`
