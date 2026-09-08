# TELNYX MANAGED MESSAGING ARCHITECTURE DECISION — §28.3 GATE-CLEARING PASS

**Status:** Bounded research and repository-audit document. No Telnyx API call, account, number, brand, campaign, or rate was created, activated or modified while producing this document. This document does not authorize Slice 3 or Slice 4 implementation; it resolves the architectural question that blocks them.

---

## 1. Executive decision

**Selected mechanism: Telnyx Managed Accounts, with Rollup Billing enabled, one Managed Account per Business.**

This is the only Telnyx-documented mechanism that satisfies §11.2's locked isolation requirement ("a compromise or suspension of one Business's provider resources must not disable another Business") at the **account** level, and it is Telnyx's own stated solution for exactly this product shape: *"Managed Accounts are intended for businesses that manage multiple customer accounts... This structure is ideal for resellers, MSPs, and multi-tenant platforms... If you're running a SaaS platform or white-labeling Telnyx services, this is the right solution."* (§4 below, source cited).

**The architectural question is resolved. A separate, new commercial/approval condition is not resolved and is not this document's to resolve:**

1. Managed Accounts require AI Business OS to be on a **paid commit plan** — Starter ($1,000/month minimum spend, quarterly billing) or Growth ($2,000/month minimum spend, quarterly billing) or Enterprise ($5,000+/month). **Managed Accounts do not exist on Pay-as-you-go.** This is a real financial commitment, not a formality, and it is an owner-level business decision, not an engineering one.
2. Becoming a "manager account" additionally requires Telnyx's explicit approval (stated directly in both the support article and the auto-generated SDK reference cited below).

**Gate status:** §28.3 is cleared for **architectural selection** (the mechanism, its isolation properties, its billing shape, its webhook routing shape, and its A2P shape are now defined with evidence, as the contract requires in §28.3's own clearing condition). §28.3 is **not** cleared for **production activation** — that requires the owner's commit-plan decision and Telnyx's manager-account approval, exactly the kind of distinction §28.3 itself anticipates ("If Telnyx approval remains necessary, distinguish architectural selection from production activation"). Slice 3 may proceed exactly as far as §21.2 already allows (provider-agnostic interface + fake + measurement, no assumed account structure baked in yet — satisfied here, see §14). Slice 4 remains blocked, as already contracted, on §28.3 (now resolved to "Managed Accounts, pending commit-plan + approval"), §28.4, §28.1, and Slice 5.

If the owner declines the commit-plan commitment, the fallback is Candidate B (one platform account, one Messaging Profile per Business) — also evidenced below — which works on any plan including Pay-as-you-go but has a materially larger suspension blast radius (§12). This document recommends Candidate A but does not foreclose B; §14's interface is designed so the choice is swappable per §21.2's own requirement.

---

## 2. Evidence date

**2026-09-08.** Every citation below was retrieved on this date. See §3 for the access-method disclosure this environment required.

---

## 3. Official sources

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

**Telnyx Managed Accounts, Rollup Billing enabled, one Managed Account per Business**, gated on the owner's commit-plan decision and Telnyx's manager-account approval (§1).

**Reasoning against the 18 evaluation criteria (§4 of the task):**

1. **Tenant isolation** — A: strong (separate organization per Business). B: weak at the account level, strong at the number/profile level.
2. **Credential isolation** — A: each Managed Account has its own `api_key`; the platform (manager) never needs to give that key to the customer (§10.2 is satisfied by simply never surfacing it, exactly as it would be for B's shared key). B: one shared platform-wide key across all Businesses.
3. **Webhook routing** — Both support `messaging_profile_id`/`organization_id` in every event; A adds `organization_id` as a genuine per-Business discriminator, B relies on `messaging_profile_id` alone (still sufficient, see §9).
4. **Number ownership** — A: implied to belong to the Managed Account's own organization (conditional). B: explicitly the platform account, mapped to a Business only via internal `BusinessMessagingIdentity` records.
5. **Usage attribution** — Both: `organization_id`/`messaging_profile_id` on every event give exact attribution.
6. **Wallet/billing compatibility** — A with Rollup Billing centralizes provider billing to the manager exactly as the contract's wallet model requires ("AI Business OS pays Telnyx centrally," §12 of the messaging contract); B is inherently one account, so it's already centralized by construction. Both are compatible; A requires the deliberate Rollup Billing choice.
7. **A2P 10DLC** — Identical requirement under both: one brand + one campaign per Business, never shared (§4C, §11).
8. **US/Canada compliance** — Identical under both (10DLC governs US long-code; Canadian traffic notes in §11).
9. **Customer exit and portability** — Identical under both: port-out is a carrier-level LNP process independent of Telnyx account architecture (§3 #17).
10. **Suspension blast radius** — **This is the deciding criterion.** A confines a suspension to one Business by construction (§3 #8). B has no such confinement at the account level — the entire platform is one blast radius. §11.2 is a **locked** contract requirement ("A compromise or suspension of one Business's provider resources must not disable another Business"), and only A satisfies it without qualification.
11. **Rate limits** — A: potentially independent per Managed Account (conditional, not fully documented). B: shared account-wide tier across every Business — a platform-wide throughput ceiling that every Business's traffic competes for.
12. **Operational complexity** — B is simpler to build (one account, one key, one profile-management surface). A adds one more entity layer (Managed Account CRUD, enable/disable, per-account key issuance) but the contract's own abstraction requirement (§21.2, §11.2) means this complexity is contained behind the same internal interface either way.
13. **Migration complexity** — Starting on B and migrating to A later is possible in principle (create a Managed Account, re-provision the Business's number/profile under it, port or re-point) but is not a zero-cost operation and is not separately documented by Telnyx as a supported "account migration" path. Starting on A avoids this migration entirely.
14. **Provider cost** — No difference in per-message carrier cost between A and B; Managed Accounts inherit the manager's negotiated rate by default (§3 #7's field list) or can carry custom pricing per account.
15. **Agency client support** — A maps naturally onto the contract's own Workspace→Business hierarchy for Agency clients (§4, §5 of the messaging contract): a Managed Account is a clean 1:1 companion to a Business record. B works too, via `BusinessMessagingIdentity` alone.
16. **Later BYO compatibility** — Unaffected by this choice; BYO (§11.4 of the messaging contract) is a wholly separate, customer-owned-credential path that never touches whichever managed mechanism is chosen here.
17. **Telnyx approval requirements** — A requires explicit manager-account approval AND a paid commit plan (materially higher bar). B requires only a standard verified Telnyx account (Level 2 verification recommended given the account-wide throughput this contract's later scale will need).
18. **Whether provider terms support the SaaS/platform use** — Both are explicitly, affirmatively supported by Telnyx's own documentation (§3 #9); A is the one built and marketed specifically for this shape.

**Why A over B despite B's lower activation cost:** §11.2 is a locked, non-negotiable isolation requirement in the already-approved messaging contract, not an open decision this document can trade away for convenience. B cannot satisfy it at the account level under any configuration Telnyx documents. A can, by construction, the moment the commercial/approval condition clears.

---

## 6. Credentials

- **Custody:** Exactly as §11.2/§11.3 of the messaging contract already require. The **manager account's** own API credentials are platform configuration: encrypted at rest, stored once, never in this or any other contract, never in a log, never in a ledger row, never in a queue payload.
- **Per-Business credentials:** Each Business's Managed Account issues its own `api_key`/`api_token` (§3 #7). These are stored the same way as the manager's own key — platform configuration, encrypted at rest — keyed to the Business's internal `BusinessMessagingIdentity` record. **No customer role ever reads this value** (T-PROV-1/2 already contracted); the customer never logs into the Managed Account's own portal even though Telnyx's UI would technically let them, because §10.2 forbids exposing any Telnyx-facing surface to an ordinary customer.
- **Rotation:** Webhook signing keys rotate per the documented inactive→activate flow (§3 #14); this is a manager-level (per-organization) operation applied once for the manager and, separately, once per Managed Account if that Managed Account's own webhooks are configured directly (see §9 for the recommended simplification that avoids this).

---

## 7. Business isolation

- One Managed Account per Business, created by the manager (platform) account, never by the customer.
- Blast radius: disabling a Business's Managed Account (`POST /managed_accounts/{id}/actions/disable`) affects only that Business — confirmed scoped to the one account ID (§3 #8).
- Cost attribution: with Rollup Billing, provider-side spend is written to the manager account, but every event still carries the Managed Account's own `organization_id`, so AI Business OS's own ledger (RFC-005) attributes every reservation/settlement to exactly one Business by that identifier — never by inference from a shared pool.
- Support boundary: the platform, not the customer, is the manager; customers never receive a Telnyx login, satisfying §11.2's "Support boundary" row exactly.

---

## 8. Number ownership

Recommended internal record (new, Slice 3-owned): **`BusinessMessagingIdentity`**, one row per Business's default messaging identity, holding only opaque provider identifiers (§11.3: "admin-visible only"):

| Column | Purpose |
|---|---|
| `business_id` | FK, unique per active identity (§10.4: at most one active default identity per Business) |
| `provider` | closed enum, `telnyx` only at launch |
| `provider_managed_account_id` | the Business's Managed Account `id` (opaque) |
| `provider_messaging_profile_id` | the Managed Account's own Messaging Profile `id` (opaque) |
| `provider_phone_number_id` | the assigned number's Telnyx-side id (opaque) |
| `phone_number` | the E.164 number itself (customer-visible, "your business phone") |
| `provider_brand_id`, `provider_campaign_id` | the Business's own 10DLC brand/campaign (opaque, admin-visible only) |
| `status` | activation state machine value (§10.1 step 1, §7.7 of the messaging contract) |
| `created_at`/`updated_at` | |

This record is the single join point the webhook router (§9) and `SendMessageAction` (§10.4 of the messaging contract) both resolve through. No Telnyx identifier is ever rendered to a customer; the `phone_number` field alone is customer-visible, exactly matching §10.2's forbidden-fields list (Messaging Profile IDs, connection IDs, provider account identifiers are never shown).

---

## 9. Webhook routing

Recommended resolution chain, exactly the shape requested:

> **Telnyx event → provider account/profile/number → `BusinessMessagingIdentity` → Business**

Concretely: an inbound webhook event's `organization_id` (the Managed Account) and `messaging_profile_id` together (belt-and-suspenders, since both are present on every event, §3 #15) are looked up against `BusinessMessagingIdentity.provider_managed_account_id` / `provider_messaging_profile_id`. A match on both, but not either alone, resolves the event; a mismatch (an event whose `organization_id` and `messaging_profile_id` point at two different Businesses' rows — only possible if data is corrupt, since one Managed Account maps to one Business) is a **conflicting mapping** and fails closed.

**Practical simplification worth adopting:** configure the webhook URL at the **Messaging Profile** level inside each Managed Account, with the URL itself encoding the internal `BusinessMessagingIdentity` id (e.g. a UUID path segment), so resolution never depends solely on trusting payload fields — the URL path is the primary lookup key, and the payload's `organization_id`/`messaging_profile_id` are the fail-closed cross-check against that same row. This mirrors the existing repository's own webhook-token pattern already proven in `AgencyProspectingWebhookController` (per the repository audit, §14 below) rather than inventing a new pattern.

**Fail-closed cases, each explicitly required by the task and each mapped to a concrete check:**

| Case | Check |
|---|---|
| Invalid signature | Verify `telnyx-signature-ed25519` against the manager's (or, if configured per-Managed-Account, that account's) stored public key before touching any business logic |
| Replay | Reject if `telnyx-timestamp` is older than a short bounded window (Telnyx does not document a replay-protection primitive beyond the timestamp itself, §3 caveat — the window is this platform's own defense, not a Telnyx guarantee) |
| Unknown number/profile/account | No matching `BusinessMessagingIdentity` row → reject, log, alert (never silently drop) |
| Conflicting mapping | URL-encoded identity id and payload `organization_id`/`messaging_profile_id` disagree → reject |
| Cross-Business mismatch | Resolved Business does not match the URL-encoded identity's own `business_id` → reject |
| Inactive Business | Business is soft-deleted/deactivated → reject, never process |
| Suspended identity | `BusinessMessagingIdentity.status` is not active → reject, never process, never silently re-activate |

This satisfies T-PROV-1/2 (§24 of the messaging contract) directly: the fake implementation required by §21.2 can implement this exact same resolution chain against an in-memory `BusinessMessagingIdentity` table today, with zero Telnyx-specific assumption beyond "one opaque account/profile/number identifier resolves to one Business."

---

## 10. Wallet/provider billing

**Provider billing facts (§3 #7, #19):**

- Telnyx itself is prepaid, pay-as-you-go, 4-decimal-place billing, no contracts (§3 #19) — this applies to the **manager account's own relationship with Telnyx**, unaffected by how many Managed Accounts sit underneath it.
- With Rollup Billing, each Managed Account still nominally has a `balance` object (§3 #7's field list) but its **spend records are written to the manager account** — meaning the manager's own prepaid balance funds every Managed Account's usage, and the manager is the party Telnyx auto-recharges/invoices, never the end customer.
- Rollup Billing is set at Managed Account creation and is immutable afterward (§3 #3) — this must be decided once, correctly, before the first production Managed Account is created; it is not a per-Business toggle that can be flipped later without recreating the account.
- 10DLC fees (brand/campaign registration and monthly maintenance, §3 #13) are carrier pass-through fees charged against whichever account holds the campaign — i.e., against the Managed Account, which under Rollup Billing settles against the manager.

**Mapping onto the intended internal model (§5 of the task), confirmed compatible:**

1. Customer funds AI Business OS through Stripe → unchanged, RFC-005.
2. AI Business OS reserves the customer's internal wallet → unchanged, `UsageWalletManager::reserve()` (§12.2 of the messaging contract).
3. AI Business OS performs managed provider work → the Business's own Managed Account (its own `api_key`) performs the Telnyx API call; the manager never needs to proxy the call through its own key, since the Managed Account's key is itself platform-held, never customer-held.
4. AI Business OS settles exact internal retail usage → unchanged, `commit()`.
5. AI Business OS pays Telnyx centrally → **Rollup Billing is the exact mechanism that makes this literally true at the Telnyx layer**, not just true at the AI Business OS wallet layer. Without Rollup Billing (each Managed Account billed independently), AI Business OS would need to fund every Managed Account's own balance separately — operationally possible but a needless indirection when Rollup Billing does this natively.

**Cost/usage attribution identifiers available:** `organization_id` (Managed Account) and `messaging_profile_id` on every message event (§3 #15); the 10DLC fee schedule (§3 #13) for registration/maintenance line items; standard per-message carrier fee tables are published (not reproduced in full here, see §18 for the follow-up needed to get current numbers embedded in a rate card, which is §28.1a's job, not this document's).

**When message cost becomes final:** not explicitly documented as a single sentence in the sources read; the `message.finalized` event type (§3 #14's payload example) and its `cost` field are the closest confirmed signal — cost appears to finalize at that event, but the exact settlement timing relative to `message.finalized` vs. an end-of-month invoice reconciliation is **unclear** and is a candidate Telnyx question (§18).

**Insufficient Telnyx platform-balance behavior:** not found in the sources read for a Managed Account specifically. General Telnyx account behavior on a negative balance is referenced by one article title only (`8648864-what-happens-with-my-numbers-after-my-account-gets-abolished-for-negative-balance`, not opened in this pass) — **unclear**, and because AI Business OS's own wallet reservation (§10.6 of the messaging contract) is designed to always reserve before any provider call, the manager account's own Telnyx balance should never actually go negative if the wallet's reservation total is kept safely ahead of Telnyx's own settlement lag; this is an operational buffer this document recommends but did not find independently documented by Telnyx.

---

## 11. A2P/compliance

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

## 12. Suspension/offboarding

- **Business-level suspension:** disabling a Business's Managed Account (§3 #8) stops that Business's sending/receiving and login capability without touching any other Business — this is the isolation property §11.2 requires and the reason Candidate A was selected.
- **Campaign-level suspension:** a Business's own 10DLC campaign can independently go dormant/suspended (15-day inactivity rule, §3 #16) even while its Managed Account remains fully enabled. This is a distinct, narrower failure mode the platform should monitor per-Business (webhook-driven where configured) and must not confuse with account-level suspension in customer-facing messaging.
- **Platform-level risk:** the manager account itself could in principle be suspended by Telnyx for a platform-wide ToS/AUP violation (§3 #18) or a payment-method/KYC failure (§3 #19's payment-method-review process) — this is a genuine, if rare, single point of failure that Candidate A does **not** eliminate (it isolates Business-to-Business blast radius, not manager-to-all blast radius). No mechanism investigated eliminates this entirely; it is mitigated by keeping the manager account's own compliance posture (payment method validity, AUP adherence across the whole platform) clean, which is an operational discipline, not an architecture choice.
- **Offboarding a Business:** disable its Managed Account; port-out remains available to the customer independent of that action (§3 #17) — a disabled Managed Account does not, per the documentation read, block a port-out request initiated by the winning carrier, though this exact interaction (port-out timing versus a disabled/enabled Managed Account) was not separately documented and is a candidate follow-up before Slice 4 implements offboarding.

---

## 13. BYO boundary

Unaffected by this decision. §11.4/§11.5 of the messaging contract already define BYO as a wholly separate, customer-owned-credential path (`CustomerBasedSendingServer`, already implemented in the repository per the audit, §14) that never touches a Managed Account, never reserves/debits the wallet for transport, and remains Agency-only, gated behind `manage_advanced_provider`. The repository's existing `MessagingChannelsController` (B2) is exactly this today; per §22.1 Slice 3's allowlist and §27 C-5, its docblock's rules become the description of the **future BYO path**, while the **default** path for Core/Growth/Agency-default customers becomes the new Managed-Account-backed managed flow this document describes.

---

## 14. Repository impact

Full mechanical inventory (background repository audit, read-only, no files modified):

- **Every Telnyx credential field today** lives in the generic `sending_servers` table (`app/Models/SendingServer.php`, ~250 provider `TYPE_*` constants including `TYPE_TELNYX`/`TYPE_TELNYXNUMBERPOOL`), with **no encryption** on any credential column (`api_key`, `c1` = `messaging_profile_id`, `c2` = `messaging_connection_id`) — a real gap against §11.3's "encrypted at rest" requirement that Slice 3 must close for the new managed path (the existing BYO path inherits this gap and is explicitly out of Slice 3's remit to fix retroactively, since BYO is being relocated, not rebuilt, per §11.4).
- **Provider dispatch today** is a single 18,188-line Eloquent model (`app/Models/SendCampaignSMS.php`) with a raw-`curl` `switch` statement per provider, duplicated three times (plain SMS, voice, MMS) — no abstraction/interface exists to build the new managed path on top of; a newer, cleaner example already exists in the same repository (`app/Library/AgencyProspecting/ProviderAgencyProspectingMessageSender.php`, a `match`-based sender using Laravel's `Http` facade) that Slice 3's provider-agnostic interface (§21.2) should resemble far more than the legacy god-model.
- **Inbound webhook routes today** are public and unauthenticated by design (`routes/public.php`'s own header comment: "No middleware will not affect these routes"), and Business attribution is fragile: `DLRController::inboundTelnyx()` resolves a `PhoneNumbers` row by raw number match and **defaults the owning user to id `1` if no match is found** — a real "unknown number" fail-**open** behavior that is the exact opposite of what §9 of this document (and T-PROV-1/2) requires for the new managed path. The newer `AgencyProspectingWebhookController::telnyx()` pattern (HMAC-token-scoped URL, tenancy resolved from the URL's own token rather than trusted payload fields) is the right existing precedent to build the new managed-messaging webhook on, not `DLRController`.
- **Provider message ID / delivery status today**, for the legacy path, is packed into a single `reports.status` string as `"{Status}|{message_id}"` (`SendCampaignSMS.php:1404`) — not a queryable identifier. The newer `agency_prospect_messages` table already has a proper, uniquely-constrained `provider_message_id` column used for idempotency — this is the pattern `BusinessMessagingIdentity`/its associated message log should follow, not the legacy `reports` shape.
- **No `BusinessMessagingIdentity` model, `app/Library/Messaging/**`, `config/messaging.php`, or `app/Enums/Messaging/**` exist yet** — confirmed absent, clean for Slice 3 to introduce exactly as its allowlist (§22.1 of the messaging contract) already assumes.
- **No usage-wallet hook exists on any message send today** — `UsageWalletManager` is used only for a coarse, read-only entitlement-capacity check in the new Agency Prospecting jobs, never a reserve/debit tied to an actual SMS send. Slice 3's "measurement without a rate" (§21.2) and Slice 4's real reservation/debit are both genuinely new wiring, not an extension of an existing hook.
- **`MessagingChannelsController`'s existing test suite** (`tests/Feature/Business/MessagingChannelsTest.php`, 790 lines) already proves the B2 tenancy/credential-non-leak/cross-Business-isolation model the new managed path must preserve when B2 is relocated to Settings → Advanced as the BYO path (§11.4).

**How the current B2 customer-entered model becomes the future Agency BYO path:** today, `MessagingChannelsController`'s docblock (quoted in full by the audit) describes the *only* model that exists — a customer enters their own Telnyx/Twilio credentials directly, and B2 builds a Business-scoped `SendingServer` from them. Under this decision, that entire flow does not change in shape; it changes in **position and default status**. It moves from the normal onboarding surface into `Settings → Advanced`, becomes reachable only behind `manage_advanced_provider` (Agency-only, §11.4), and stops being the only path — the new managed path (Managed-Account-backed, credential-free from the customer's point of view) becomes the default for Core/Growth and Agency-default. No code in `MessagingChannelsController` needs to be rewritten for this repositioning beyond its route/menu placement and gating; §27 C-5 already requires Slice 3's own contract to restate its rules explicitly, since no standalone B2 contract document exists to correct in place.

---

## 15. Slice 3 allowlist recommendation

This document does not authorize Slice 3; it confirms the messaging contract's existing §22.1 Slice 3 allowlist is **already correctly shaped** for this decision and needs no path additions:

- `app/Library/Messaging/**` (new) — the provider-agnostic interface (send, number search, number order, registration submit, status callback) in **platform** vocabulary, per §21.2, with `BusinessMessagingIdentity` as its central resolved record (§8 above).
- `app/Library/Messaging/Contracts/**` (new) — the interface itself, implemented first by a deterministic fake (mirroring `FakeGoogleBusinessProfileReadClient`, per §21.2) and only later by a real Telnyx-backed implementation once §1's commercial/approval gate clears.
- `app/Models/BusinessMessagingIdentity.php` (new) — exactly the shape in §8.
- `app/Http/Controllers/Customer/Business/MessagingChannelsController.php` (existing) — repositioned to the BYO/Advanced path per §13, not rewritten.
- `app/Enums/Messaging/**` (new) — a closed provider enum (`telnyx` only at launch) and an identity-status enum.
- `resources/views/customer/business/MessagingChannels/**` and `resources/views/customer/settings/advanced/**` (new) — the relocated BYO surface.
- `config/services.php`, `config/messaging.php` (new) — no live Telnyx credential belongs in either file per §11.3; `config/messaging.php` should hold only the closed provider list and non-secret defaults.

**Nothing here changes as a result of this document; it is confirmation, not a new allowlist.** Per §21.2 (already locked), the forbidden list stands unchanged: no sub-account identifier, no messaging-profile identifier, no connection identifier, and no assumed-account-structure column or migration until §1's gate actually clears — the fake and the interface are what Slice 3 may build today.

---

## 16. Slice 4 prerequisites

Unchanged in structure, updated in content, from the messaging contract's own §21.1: Slice 4 requires **3 and 5 complete, and §28.3, §28.4, and §28.1 all recorded**. This document resolves §28.3's *architectural* half; it adds one concrete, evidenced prerequisite that did not previously appear in the contract:

- **New, concrete precondition for real (non-fake) Slice 3/4 provider work:** the platform owner's decision to commit to a qualifying Telnyx plan (Starter $1,000/month minimum spend or Growth $2,000/month minimum spend, both quarterly-billed, or Enterprise) and Telnyx's explicit approval of manager-account status (§1, §3 #2/#6). Until both are true, Slice 3 ships only the interface + fake + measurement (already permitted), and Slice 4 cannot provision a single real number.
- §28.4 (launch countries/compliance scope) and §28.1 (retail rates) remain exactly as previously gated — this document does not touch either.
- Before Slice 4 provisions its first real number for a real Business, the platform must also have created and verified its **own** Telnyx manager account, decided Rollup Billing at Managed-Account-creation time (immutable after, §3 #3), and built the fail-closed webhook router (§9) against the real Managed-Account/Messaging-Profile identifiers rather than the fake's placeholder ones.

---

## 17. Risks

- **Commercial commitment risk:** committing to a $1,000+/month Telnyx plan before the platform has proportional messaging volume is a real cost the owner must weigh; this document cannot make that tradeoff.
- **Scale ceiling:** the default 1000-managed-sub-account limit (§3 #4) will eventually need a support request to raise if AI Business OS's Business count approaches it; this is a known, requestable limit, not a hard wall, but should be tracked before it becomes urgent.
- **Manager-account single point of failure:** a platform-wide AUP/KYC/payment issue on the manager account itself is not isolated by this architecture (§12) — this risk exists under both candidates and is not eliminated by choosing A.
- **Unclear per-Managed-Account throughput independence:** if Managed Accounts turn out to inherit a single throughput ceiling from the manager rather than each having its own, high-volume Businesses could still contend with each other indirectly even under Candidate A. Marked unclear in §4/§18.
- **10DLC operational load scales with Business count:** every Business, regardless of account architecture, needs its own brand + campaign + fees + a 3–7 business day approval wait (or a 24-hour manual OTP loop for sole proprietors) before it can send compliant traffic — this is a real onboarding-latency and per-Business-cost factor for §10.6's upfront estimate, not a one-time platform cost.
- **Dormancy risk:** a low-activity Business's 10DLC campaign can auto-suspend after 15 days (§3 #16); the platform should monitor for this per-identity and treat it as a distinct alert from account-level suspension, or a customer could see "your number stopped working" with no account-level cause.
- **Access-method risk (this document's own methodology):** direct primary-source verification was blocked by sandbox network policy; the GitHub-mirror workaround (§3) is first-party but is still one hop from the live page. A human with direct portal/API access should spot-check the handful of "Proven" claims most load-bearing for §1's decision (the commit-plan pricing tiers, and the explicit multi-tenant/SaaS recommendation) before the owner commits money.

---

## 18. Provider questions

The smallest exact set needed to fully close the remaining "unclear" items, for Telnyx support/sales:

1. "Does a Managed Account have its own independent message-throughput/verification tier, or does it inherit the manager account's tier and share the same ceiling with every other Managed Account under that manager?"
2. "Can a phone number and its 10DLC campaign be transferred directly from one Managed Account to another under the same manager, without a port-out/port-in cycle?"
3. "For a Managed Account, at what exact point does a message's cost become final and immutable against our own ledger — the `message.finalized` webhook event, or a later invoice/reconciliation step — and can the provider price for an in-flight message change between send and that finalization?"
4. "What happens to a Managed Account's numbers and active 10DLC campaigns if that Managed Account's balance goes negative or its payment method fails, specifically (not the general-account article we found, which does not appear to be Managed-Account-specific)?"
5. "Does disabling a Managed Account interact with an in-flight port-out request for that account's numbers (e.g., does disabling block a pending port-out, or are they independent)?"
6. "For an ISV managing many end-user Managed Accounts, is there a bulk/managed workflow for 10DLC brand and campaign creation, or must each be created one at a time via the standard single-brand API per Managed Account?"

---

## 19. Gate status

**§28.3: Architecturally resolved. Production-activation-blocked pending an owner commercial decision and a Telnyx approval, both newly identified by this evidence and neither previously named in the contract.**

- Mechanism selected: Telnyx Managed Accounts, Rollup Billing, one per Business.
- Credential custody, isolation, webhook routing, number ownership, billing, A2P responsibility, suspension, portability, and the BYO migration path are all defined above with cited evidence (§4–§13), satisfying §28.3's own stated clearing bar.
- Slice 3 may proceed exactly as far as §21.2 already permits — no more, no less than before this document.
- Slice 4 remains blocked exactly as before, with one concrete new item added to what "§28.3 recorded" now means in practice (§16).
- The contract's §28.3 row is amended narrowly, in place, to record this (see the diff to `CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` in this same commit).

---

`TELNYX MANAGED MESSAGING ARCHITECTURE DECISION — READY FOR HUMAN/CHATGPT REVIEW`
