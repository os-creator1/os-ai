# TELNYX US/CANADA COMPLIANCE AND RETAIL RATE-CARD DECISION — §28.4 AND §28.1a GATE-CLEARING PASS

**Status:** Bounded external-research and repository-audit document. **No Telnyx API call, account, number, brand, campaign, messaging profile, or rate was created, activated, queried or modified while producing this document.** No production provider operation of any kind occurred. This document **recommends**; it does not decide. Every rate in it is a proposal awaiting explicit owner approval, and **no rate described here is activated**.

**Scope.** This document addresses exactly the two gates the merged architecture decision left open:

* **§28.4** — the exact launch-country, number-type and compliance scope.
* **§28.1a** — the complete retail telecom rate-card policy.

**Out of scope, deliberately.** The provider architecture is locked and is not revisited (§1). Voice/calling is excluded (parent contract §10.5). RCS is excluded for the same reason. Managed Accounts are excluded at launch (architecture decision §5.2). No code is written, no contract document is edited, and no pull request is opened by this lane.

**Evidence date for every external claim in this document: 2026-09-09.** Prices move; see §13.4 for the re-verification rule.

---

## Correction Round 1 — 2026-09-09

This document was corrected on the same day it was first written, after review. The corrections are recorded here rather than silently folded in, because two of them changed a factual claim and one changed a recommendation's supporting evidence.

| # | What was wrong | What it now says | Where |
|---|---|---|---|
| **C1** | Brand/campaign cardinality was stated as "one brand and one campaign per Business", which invented a limit the sources do not impose | A brand may hold **up to five campaigns**, a campaign may hold **up to 49 numbers**, and a number belongs to exactly one campaign and its parent brand. Multiple use cases are legitimately served by multiple campaigns or by one Mixed campaign | §2.2 T11, §3.1, §3.6 |
| **C2** | Canadian pricing was described as not existing | Corrected to the narrower evidenced claim: **no authoritative Canadian price sufficient for activation was found in the published material reviewed**, which is not the same as confirmed absent. A Canadian local number price *was* subsequently found and is now cited. The pricing endpoint was deliberately not called | §2.2 T3, T33, §4.3, §5.1, §5.4, §6.7, §8.7, §13.5 |
| **C3** | The negative-balance consequence was overstated as "every Business's numbers are deleted" with restoration impossible | Corrected to the provider's own terminology and timeline, with restoration classified as **known and possible during a two-week hold** and **unknown thereafter** | §2.2 T30, §8.12, §11.8 |
| **C4** | MMS was priced "per part" by analogy to SMS without checking | Verified: Telnyx's published unit is "per message part" for **both** SMS and MMS. The term is supported; the *segmentation rule* behind it is defined only for SMS. Terminology is now unified across rate card, reservation, display copy and tests | §2.2 T1, T32, §7.7, §7.21 |
| **C5** | Base rates were treated as sender-type-independent | Corrected: local, toll-free and short-code senders carry **different** base rates. §5's comparison and §6's inventory now say so | §2.2 T1, §5.1, §6.2 |
| **C6** | The funding sequence relied on splitting an upfront reservation but did not state the rule precisely enough | Rewritten as an explicit provider-commitment-boundary sequence with a full-amount funding gate before any provider cost, and a stated rule that the platform never incurs an unfunded provider cost | §11.4, §9 |
| **C7** | Charge classes were described but not separated as a single normative list | Added an explicit five-class separation | §7.21 |

Everything not listed above is unchanged from the original pass. The **US-only launch recommendation is preserved**; the corrections strengthened rather than weakened its evidence, and §5.4 records why.

---

## 0. How to read this document

The task requires a clean separation between what is proven, what is conditional, what is recommended, and what a human must still decide. Those separations are carried in the section structure:

| Where | What it contains |
|---|---|
| §2 | Evidence register — every citation with URL, access date, exact claim, confidence, price nature |
| §3, §4 | **Proven and conditional current facts** — US and Canada compliance analysis |
| §5, §6 | **Proven and conditional current facts** — number-type comparison and provider-cost inventory |
| §7, §8 | **Recommendations** — retail rate-card policy and margin analysis |
| §9 | **Recommendation** — compliance onboarding state machine |
| §10 | **Telnyx questions**, classified, plus a support-ticket draft |
| §11 | **Implementation consequences** — repository reconciliation |
| §12 | **Owner decisions required** |
| §13 | **Legal-review items**, validation record, gate status |

Confidence tiers used throughout:

* **Proven** — a first-party page was opened at the cited URL on 2026-09-09 and states the claim directly.
* **Conditional** — supported, but its truth depends on a stated condition (a destination, a date, a carrier, an account tier) this document could not fix.
* **Unresolved** — no primary source answers it; carried into §10 as a question, never silently assumed.

**This document gives no legal guarantee.** It records what providers and regulators publish. Whether a specific customer's business model, message copy, or consent flow is *lawful* is counsel's question. §13.2 lists what counsel must see.

---

## 1. Locked context — not revisited

Settled by `docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md` (merged in PR #219) and by the parent contract. This document builds on these and reopens none of them:

1. One platform-owned, **Pay-as-you-go** Telnyx account.
2. One **Messaging Profile** and dedicated number(s) per Business, resolved through an internal `BusinessMessagingIdentity` record.
3. **US and Canada local long-code** as the *proposed* initial scope — the proposal this document tests against primary evidence (§5).
4. Customers fund usage through AI Business OS wallets (RFC-005).
5. **No included telecom credit** on any plan unless the platform owner creates an explicit promotion.
6. **Auto-recharge is off by default.**
7. Managed transport will eventually use an approved retail rate — approving that rate is §7's subject.
8. **BYO transport receives no platform transport charge** (parent contract §11.5).
9. **No Managed Accounts at launch.**
10. **No production provider activity is authorized by this document.**

---

## 2. Evidence register

### 2.1 Access-method disclosure

The architecture decision (its §3) recorded that this sandbox's egress policy blocked `telnyx.com`, `developers.telnyx.com` and `support.telnyx.com`, forcing that pass to work from Telnyx's own GitHub mirror. **That restriction no longer applies.** On 2026-09-09 all three hosts were fetched directly and successfully. Every Telnyx claim below rests on the live primary page, not a mirror — a strict improvement in evidence quality over the prior pass, and independent corroboration of the mirror-sourced findings that pass relied on.

Two access limits did apply and are disclosed rather than hidden:

* `ecfr.gov` redirected to an interstitial and could not be read. 47 CFR 64.1200 was read from Cornell's Legal Information Institute reproduction instead — faithful, but one step from the official codification. Marked accordingly.
* `crtc.gc.ca` returned HTTP 403 and `ctia.org`'s main site failed TLS verification. CASL was therefore read from the **statute itself** on the Department of Justice site (a better source than the regulator's explainer), and CTIA guidance from `api.ctia.org`, which serves CTIA's own published PDF.

### 2.2 Telnyx sources

| # | Claim | URL | Page title | Access date | Confidence | Price nature |
|---|---|---|---|---|---|---|
| T1 | **Base rates differ by sender type**, all quoted verbatim as "per message part" for **both SMS and MMS**. **Local (10DLC):** SMS outbound $0.004, SMS inbound $0.004, MMS outbound $0.015, MMS inbound $0.005. **Toll-free:** SMS outbound $0.0055, SMS inbound $0.0055, MMS outbound $0.016, MMS inbound $0.016. **Short code:** SMS outbound $0.007, SMS inbound $0.007, MMS outbound $0.018, MMS inbound $0.009. All plus carrier fees, USD. "Prices are applied per message part" | `https://telnyx.com/pricing/messaging` | Messaging pricing | 2026-09-09 | Proven | Variable base + pass-through |
| T2 | US carrier fees per part — AT&T out/in SMS $0.0035/$0.0035, MMS $0.009/$0.009; T-Mobile USA $0.0045/$0.0025, MMS $0.01/$0.01; Verizon $0.0045/none, MMS $0.007/none; U.S. Cellular $0.005/none, MMS $0.01/none; Dish Wireless $0.0045/none, MMS $0.01/none | same as T1 | Messaging pricing | 2026-09-09 | Proven | Pass-through |
| T3 | Canadian carriers (Telus, Bell & Virgin, EastLink) appear **only** in the inbound table with no carrier fee. **No Canadian outbound carrier fee and no Canada-destination base rate appears on this page**, and the page carries no country or destination selector; requesting the CAD-currency variant of the page changes no rate table. The page directs destination-specific enquiries to sales | same as T1, plus `https://telnyx.com/pricing/messaging?currency=CAD` | Messaging pricing | 2026-09-09 | **Proven that the page does not publish it — NOT proven that no such price exists.** See §4.3 | Not found in reviewed material / quote-only |
| T4 | The highest per-part outbound SMS carrier fee shown anywhere is Digicel at $0.20; every US and Canadian carrier listed is below $0.01 | same as T1 | Messaging pricing | 2026-09-09 | Proven | Pass-through |
| T5 | "Carrier passthrough and taxes vary by destination." A machine-readable endpoint `GET /v2/public/pricing` (and `?primitive=numbers`) is published as the authoritative per-destination rate source | same as T1 | Messaging pricing | 2026-09-09 | Proven | Variable |
| T6 | Local numbers "From $1 per month" on pay-as-you-go, volume tiers to $0.25/month; "An additional charge of $0.10 per month applies to add SMS and MMS capabilities to a number." No one-time purchase fee for local numbers. No Canada-specific number price published | `https://telnyx.com/pricing/numbers` | Phone number pricing | 2026-09-09 | Proven | Recurring fixed; Canada unknown |
| T7 | 800-prefix toll-free numbers carry "$500 OTC (covers 12-mo term) + $40 MRC (after 12 months)" | same as T6 | Phone number pricing | 2026-09-09 | Proven | One-time + recurring fixed |
| T8 | 10DLC brand registration application fee $4.50; campaign review $15 "per Campaign Review (manual review fee passed through from carriers)"; monthly campaign fees — Low Volume Mixed $1.5/mo, Standard $10/month, Charity $3/mo, Emergency $5; sole-proprietor campaign monthly $2. "Campaign fees are billed for three months initially, then subsequently on a monthly recurring basis." "Telnyx does not currently charge a markup on 10DLC fees. All 10DLC-related fees are passed on to the customer at cost." | `https://support.telnyx.com/en/articles/5634625-10dlc-fees-and-charges` | 10DLC Fees and Charges | 2026-09-09 | Proven | Compliance fee, pass-through |
| T9 | Sole-proprietor path: brand registration $4.00 one-time charged post-verification, campaign vetting $15 per submission, monthly maintenance $2.00; 1 campaign per brand, **1 phone number per campaign**, max 3 SP brands per mobile number; throughput "Low-volume (varies by carrier)"; OTP valid for a 24-hour window; "Sole Proprietor campaigns are typically auto-approved and become ACTIVE immediately" | `https://developers.telnyx.com/docs/messaging/10dlc/sole-proprietor` | Sole Proprietor 10DLC Registration | 2026-09-09 | Proven | Compliance fee; **conflicts with T8 on the brand fee** |
| T10 | "An Independent Service Vendor (ISV) is a type of business that sells products and services to other end users who are also businesses." "for every end-user you're reselling to, you will need to create a separate brand." "No two brands can be on the same number. If you are currently sharing numbers across brands, unless you have a special arrangement with the mobile network operators, you will need to update your messaging architecture such that only one brand is using any given number to send messages." "each campaign can only be associated with one brand." "You can assign a maximum number of 49 phone numbers." Onboarding sequence: create a Messaging Profile → buy or use a dedicated number for it → create the brand for the end-user → create the campaign → assign the campaign to the number | `https://support.telnyx.com/en/articles/5593977-isvs-10dlc` | ISVs & 10DLC | 2026-09-09 | Proven. **Corrected in Round 1:** the constraint is *campaign→one brand*, which does **not** imply one campaign per brand. The original pass read it as if it did | n/a |
| T11 | **Cardinality, verbatim:** "A Brand can have multiple Campaigns, with a maximum of five Campaigns per Brand." "A Campaign can have multiple Numbers. As of September 2023, the T-Mobile Limit is 49 numbers." "A Number can only be used in one Campaign and its parent Brand." **Blocking:** "From February 3rd 2025, any 10DLC traffic which is not registered will be blocked altogether." **Throughput:** AT&T — "Each Campaign's throughput is determined by its AT&T 'Message Class' (a score determined by Use Case and, in many cases, a Vetting Score)", 75–4500 TPM by vetting score. T-Mobile — "T-Mobile sets limits at the Brand level, not Campaign", and "T-Mobile determines throughput as a daily messaging limit at the Brand level, meaning that all your Campaigns combined must share the daily limit", daily caps 2,000 (low) to 200,000 (top tier, 75–100 vetting score). "The same MPS limit applies whether you send all traffic through one number or split it up across multiple numbers." Brand and campaign data go to TCR for manual review. **No mention of Canada anywhere in this FAQ** | `https://support.telnyx.com/en/articles/3679260-frequently-asked-questions-about-10dlc` | Frequently asked questions about 10DLC | 2026-09-09 | Proven. **This row carries the Round 1 cardinality correction (C1)** | n/a |
| T12 | Required brand fields: Legal Company Name, DBA/Brand Name, organization legal form, vertical, Country of Registration, Website, EIN Issuing Country, EIN, business address/city/state/postal, stock symbol and exchange (public only), authorized-representative email and phone. Entity types: Charity/Non-Profit, Government, Private Company, Publicly Traded Company. **Canadian companies supply "Provincial or Federal Corporation/Registry ID Numbers" instead of an EIN**, and "Please avoid using your Canadian Federal Business Number (BN) or Canadian Revenue Agency Tax Account Numbers in the EIN section" | `https://support.telnyx.com/en/articles/5896911-how-to-create-a-10dlc-brand` | How to create a 10DLC brand | 2026-09-09 | Proven | n/a |
| T13 | Required campaign fields: Brand, Use case, Vertical, Campaign description, Sample messages, campaign/content attributes, Message Flow, Opt-in Keywords, Opt-in Message, Opt-out Keywords, Opt-out Message, Help Keywords, Help Message. "You need one sample message for each selected use case. A marketing use case requires 2 sample messages." Field lengths — description 40–4096, help message 20–320, message flow 40–2048, opt-in message 20–320, opt-out message 20–320 | `https://support.telnyx.com/en/articles/6339152-how-to-create-a-10dlc-campaign` | How to create a 10DLC campaign | 2026-09-09 | Proven | n/a |
| T14 | Campaign compliance: opt-in via keyword, website form (phone field must be optional, not mandatory), verbal consent, or signed written consent. "Opt-in language must be specific just for text messages. It can not include e-mail or phone calls, this should be separate." CTA must show program/brand name, "Message frequency may vary", "Standard Message and Data Rates may apply", "Reply STOP to opt out", "Reply Help for help", and links (not pop-ups) to Terms and Privacy Policy. Privacy policy must contain: "We will not share your opt-in to an SMS campaign with any third party for purposes unrelated to providing you with the services of that campaign." Opt-out reply must confirm no further messages; HELP reply must give program name and customer-care contact. "Popups are not a method for displaying terms and conditions." | `https://support.telnyx.com/en/articles/9940291-10dlc-campaign-compliance-requirements` | 10DLC Campaign Compliance Requirements | 2026-09-09 | Proven | n/a |
| T15 | Acceptable Use: "SMS recipients must have explicitly opted in to receiving messages from you." Invalid opt-ins include collecting numbers for another purpose, purchased lists, and converting a transactional opt-in into a recurring campaign. On STOP/UNSUBSCRIBE, "you have up to 24 hours to remove the recipient from your list." "You may not send more than 10 messages to a recipient in any 24 hour period" absent two-way engagement or explicit consent to frequent messaging. Telnyx "reserves the right to suspend or close your account" for violations | `https://support.telnyx.com/en/articles/1310359-acceptable-use-policy-for-messaging` | Acceptable Use Policy for messaging | 2026-09-09 | Proven | n/a |
| T16 | Forbidden use cases (US **and Canada**): illegal/controlled substances, cannabis and CBD including dispensary promotions, gambling, SHAFT categories, deceptive financial and crypto content, securities trading, SEO services, debt collection, unregistered third-party traffic. US may permit alcohol messaging with age verification, "but policies vary by carrier"; "Canadian carriers enforce additional restrictions on alcohol-related content" and "stricter rules for age-sensitive content". Generic URL shorteners are "frequently flagged by carrier filters and are a major source of message blocking" | `https://support.telnyx.com/en/articles/14286763-forbidden-messaging-use-cases-in-the-us-and-canada-10dlc-toll-free-and-short-code` | Forbidden Messaging Use Cases in the US and Canada | 2026-09-09 | Proven | n/a |
| T17 | Campaign suspension: "No activity for 15 consecutive days" plus no active assigned numbers triggers automatic suspension, framed as protection from T-Mobile's "$250 per month fine for campaigns they deem as inactive". Reactivation requires assigning numbers twice (the first attempt reactivates and the assignment fails; the second succeeds), typically 1–5 minutes; reactivation is free. A `DORMANT` webhook notification is available | `https://support.telnyx.com/en/articles/10723378-10dlc-campaign-suspended` | 10DLC Campaign Suspended | 2026-09-09 | Proven | Carrier fine risk |
| T18 | "Each number can only be assigned to one campaign at a time." A number "must be assigned to a messaging profile first". After assignment, wait "24-72 hours for all carriers to propagate" | `https://developers.telnyx.com/docs/messaging/10dlc/phone-number-assignment` | 10DLC Phone Number Assignment | 2026-09-09 | Proven | n/a |
| T19 | Country compliance table — **United States**: Alphanumeric ID ❌, Long Code ✅ (10DLC), Short Code ✅, Toll-Free ✅, Pre-Registration "10DLC required". **Canada**: Alphanumeric ID ❌, Long Code ✅, Short Code ✅, Toll-Free ✅, Pre-Registration "Short code approval". North America requirements — Governing law: "TCPA + CTIA guidelines" (US) / "CASL" (Canada); Consent type: "Express written (marketing) / Express (transactional)" (US) / "Express or implied" (Canada); Opt-out: "STOP keyword mandatory" (US) / "Unsubscribe mechanism required" (Canada); Record retention: "Recommended 4+ years" (US) / "Duration of consent" (Canada); Pre-registration: "10DLC / toll-free verification" (US) / "Short code approval" (Canada). "Alphanumeric sender IDs are **not supported** for US and Canadian destinations." | `https://developers.telnyx.com/docs/messaging/messages/international-sms-compliance` | International SMS Compliance Guide | 2026-09-09 | Proven | n/a |
| T20 | The 10DLC quickstart describes 10DLC as "the industry standard for application-to-person (A2P) messaging on **US long code numbers**" and makes no statement about Canada | `https://developers.telnyx.com/docs/messaging/10dlc/quickstart/index` | 10DLC Quickstart | 2026-09-09 | Proven (as an absence) | n/a |
| T21 | "U.S. wireless carriers require toll-free numbers used for SMS/MMS to complete verification." Verification "typically takes 1-2 weeks". Verified toll-free supports "up to 20 MPS". "Unverified toll-free numbers have limited throughput and may experience carrier filtering." **From 17 February 2026** three business-registration fields become mandatory: `businessRegistrationNumber`, `businessRegistrationType`, `businessRegistrationCountry` | `https://developers.telnyx.com/docs/messaging/toll-free-verification` | Toll-Free Verification | 2026-09-09 | Proven | n/a |
| T22 | "We support SMS. Toll-Free MMS is supported (US and Canada _only_)." Use-case approval "is now 2 weeks". "Use of non verified toll free numbers may result in spam blocks at any time." | `https://support.telnyx.com/en/articles/5353868-toll-free-messaging` | Toll-Free Messaging | 2026-09-09 | Proven | n/a |
| T23 | Short codes are "5- or 6-digit phone numbers designed for high-volume, application-to-person (A2P) messaging"; carriers named are US only (AT&T, T-Mobile, Verizon, US Cellular); "Carrier certification typically takes 8-12 weeks", 8–12 weeks random and 10–12 weeks vanity; up to 1,000 messages per second; two-way supported ("Yes (keyword-based)"); cost "Higher (monthly lease + per-message)" with no figure published; **no mention of Canada** | `https://developers.telnyx.com/docs/messaging/messages/short-code` | Short Code | 2026-09-09 | Proven | Quote-only |
| T24 | Rate limits — non-US long code 0.1 MPS per number; 10DLC varies by carrier and campaign (AT&T 15 TPM for Sole Proprietor up to 9,000 TPM; T-Mobile daily brand caps 2,000–200,000); toll-free 20 MPS per number; short code 1,000 MPS per number. **Account-wide ceilings: SMS 50 MPS, MMS 15 MPS, RCS 1 MPS, aggregated across all numbers.** Over-rate messages queue FIFO up to 4 hours (queue = MPS × 14,400s); error 40318 when full. "The most restrictive limit takes precedence" | `https://developers.telnyx.com/docs/messaging/messages/rate-limiting` | SMS messaging rate limits | 2026-09-09 | Proven | n/a |
| T25 | Encoding: GSM-7 is 160 characters single / 153 concatenated; UCS-2 is 70 single / 67 concatenated. "Every SMS message is transmitted in units of **140 bytes**." Charged per segment — "a 161-character GSM-7 message costs twice as much as a 160-character one". "A single non-GSM-7 character (like an emoji or curly quote) switches the **entire message** to UTF-16, cutting capacity from 160 to 70 characters per segment." Smart encoding substitutes GSM-7 equivalents to avoid the switch. "MMS and RCS messages use **UTF-8** encoding by default and are not affected by these limits." | `https://developers.telnyx.com/docs/messaging/messages/message-encoding` | Message Encoding | 2026-09-09 | Proven | n/a |
| T26 | Messaging webhook events are `message.received`, `message.sent`, `message.finalized` (terminal state). `message.finalized` carries `cost` (`amount`, `currency`) and `cost_breakdown` with `carrier_fee` and `rate`, each with amount and currency; the documented example is `cost` $0.0051 = `carrier_fee` $0.00305 + `rate` $0.00205. The payload also carries `parts` and `encoding` | `https://developers.telnyx.com/docs/messaging/messages/receiving-webhooks` | Receiving Webhooks for Messaging | 2026-09-09 | Proven | Variable, per-message actual |
| T27 | Telnyx considers "4 decimal points for billing"; the article's own worked example quotes an SMS rate of $0.0025 | `https://support.telnyx.com/en/articles/3317613-billing-decimal-values-considered` | Billing: Decimal Values Considered | 2026-09-09 | Proven — but the $0.0025 example is **stale** against T1's current $0.004, and T26's example carries **five** decimals | Variable |
| T28 | US customers are subject to sales and telecommunications taxes (state/county/municipal), USF and TRS fees. "Telnyx will calculate and apply taxes to your account daily. We will deduct the tax amount from the customer's balance based on usage and purchases on the following calendar day." "Since taxes are calculated based on multiple factors like service usage and effective tax rates, there is no way to provide an accurate estimate in advance." The page does not address Canadian GST/HST | `https://support.telnyx.com/en/articles/6420959-sales-gst-telecommunication-taxes-usf-fees-trf` | Sales, GST, Telecommunication Taxes, USF Fees & TRF | 2026-09-09 | Proven | **Uncertain tax — explicitly not estimable in advance** |
| T29 | Required documents to acquire numbers: the Canada row shows Local "Not required" and Toll-free "Not required"; the United States row shows "Not required" across Local, National, Mobile and Toll-free. No address or local-presence rule is stated for either country | `https://support.telnyx.com/en/articles/5469551-international-numbers-required-documents` | International Numbers - Required Documents | 2026-09-09 | Proven | n/a |
| T30 | **Verbatim, in the provider's own terminology.** Trigger: "If your account is left with a negative balance for a period of 1 month, an abolishing process will take place. In this process the numbers in your account will be deleted from the account." Then: "After the numbers are deleted from the account they are set to a 'hold' status for the next two weeks." "While the numbers are in this 'hold' status you can still buy them again"; "During this period of time, only you will be able to search for and purchase the numbers." Then: "If you do not buy back your numbers when they are in the 'hold' status, the status will change to 'Aging' and will remain like that for the next two weeks." "While the numbers are in an 'Aging' status no one (including you) can buy them." Finally: "After the numbers have been left in 'Aging' for two weeks they will be released so that they are generally available." Constraint on recovery: "If your account was abolished, you will not be able to get your numbers back by simply adding balance to your account." For numbers in Aging the article directs the reader to `numbering@telnyx.com` or `support@telnyx.com` | `https://support.telnyx.com/en/articles/8648864-what-happens-with-my-numbers-after-my-account-gets-abolished-for-negative-balance` | What happens with my numbers after my account gets abolished for negative balance? | 2026-09-09 | Proven for the quoted wording. **Corrected in Round 1 (C3).** The article says "the numbers in your account", carving out no exception — reading that as *all* the account's numbers is an **inference**, not a quotation. Restoration during hold is **known and possible** by repurchase; the outcome of a support request during Aging is **unknown** | n/a |

**Rows added in Correction Round 1:**

| # | Claim | URL | Page title | Access date | Confidence | Price nature |
|---|---|---|---|---|---|---|
| T31 | "A **messaging profile** is the central configuration object for your Telnyx messaging setup." It groups "your phone numbers, defines webhook URLs, and controls features like number pooling, smart encoding, and spend limits." "Every phone number you use for messaging must be assigned to a messaging profile." **A per-profile daily spend cap exists**: fields `daily_spend_limit_enabled` and `daily_spend_limit`, **disabled by default**; when the limit is reached "New messages are rejected with error `40333`, a webhook notification is sent, an email alert is sent to your account. The limit resets at midnight UTC daily." The page states no relationship between messaging profiles and 10DLC brands or campaigns, and no per-profile throughput cap | `https://developers.telnyx.com/docs/messaging/messages/messaging-profiles-overview` | Messaging Profiles Overview | 2026-09-09 | Proven | Operational control, not a price |
| T32 | Definition of the billing unit: "A message part is either an entire SMS message or a component of one, depending on message characters and encoding. Longer messages may be split into multiple parts, and you are billed per-part." Prices "are applied per message part based on the destination, the number type used, and the carrier the message is sent to" | `https://developers.telnyx.com/docs/v1/messaging/configuration-and-limitations/character-and-rate-limits` and `https://telnyx.com/pricing/messaging` | Character and Rate Limits; Messaging pricing | 2026-09-09 | Proven. **Note the asymmetry:** the definition is written in terms of an *SMS* message, while the pricing page applies the same unit label to MMS | n/a |
| T33 | "Local numbers for Canada start at $1 per month." No setup fee, SMS/MMS capability cost, per-message rate, or documentation/local-presence requirement appears on this page | `https://telnyx.com/phone-numbers/canada` | Buy Canada virtual phone numbers | 2026-09-09 | Proven for the quoted price. It is a "start at" figure, so it is a **floor, not a firm rate** | Recurring fixed, lower bound only |
| T34 | MMS attachment limits: "Total attachment size must be less than 1 MB. Ideally, it should be 1MB minus 100KB to give some space for encoding overhead." Carrier tiers differ — Tier 1 up to 1 MB, Tier 2 up to 600 KB, Tier 3 up to 300 KB. "MMS allows for a significantly higher number of characters per message compared to SMS", with no standardized universal limit. The article does not state how MMS is billed or whether an MMS can be more than one part | `https://support.telnyx.com/en/articles/4450150-faqs-about-mms-at-telnyx` | FAQs about MMS at Telnyx | 2026-09-09 | Proven | Constrains MMS payload, not price |
| T35 | "T-Mobile charges an additional $0.001 for every MB over 5MB in media size on outbound Rich Media messages" — this applies to **RCS Rich Media**, not MMS. "RCS Rich text messages are charged per segment and RCS Rich Media is charged per message." No other carrier imposes a per-megabyte surcharge in the published rate tables | `https://telnyx.com/pricing/messaging` | Messaging pricing | 2026-09-09 | Proven. Relevant only to confirm that **no per-megabyte surcharge applies to MMS**; RCS is out of scope | Pass-through, out of scope |

### 2.3 Regulatory and industry sources

| # | Claim | URL | Page title | Access date | Confidence |
|---|---|---|---|---|---|
| R1 | CASL s.6(1): sending a commercial electronic message is prohibited unless the recipient "has consented to receiving it, whether the consent is express or implied" and the message meets s.6(2). s.6(2): the message must identify the sender and any person on whose behalf it is sent, provide "contact information enabling the person to whom the message is sent to readily contact one of the persons", and set out "an unsubscribe mechanism in accordance with subsection 11(1)". Contact information must be "valid for a minimum of 60 days after the message has been sent". A message is commercial if it "would be reasonable to conclude has as its purpose, or one of its purposes, to encourage participation in a commercial activity" | `https://laws-lois.justice.gc.ca/eng/acts/E-1.6/page-1.html` | CASL, page 1 | 2026-09-09 | Proven (statute) |
| R2 | CASL s.11: effect must be given to an unsubscribe request "without delay, and in any event no later than 10 business days"; the unsubscribe address or web page must be "valid for a minimum of 60 days after the message has been sent". s.10(9) implied consent: existing business relationship and existing non-business relationship, each within "the two-year period immediately before the day on which the message was sent"; an inquiry or application supports implied consent "within the six-month period immediately before"; conspicuous publication is a third category | `https://laws-lois.justice.gc.ca/eng/acts/E-1.6/page-2.html` | CASL, page 2 | 2026-09-09 | Proven (statute) |
| R3 | "You must obtain consent to send commercial electronic messages, including text messages." "It's important to include your business name in commercial messages, including texts." "When you receive a 'STOP' text request from a customer, make sure that you respect it." | `https://ised-isde.canada.ca/site/canada-anti-spam-legislation/en/texting-good-client-relations` | Texting for good client relations | 2026-09-09 | Proven |
| R4 | 47 CFR 64.1200(c)(1) — no telephone solicitation "before the hour of 8 a.m. or after 9 p.m. (local time at the called party's location)". 64.1200(a)(10) — consent revoked by reasonable means is "definitively revoked and the caller may not send additional robocalls and robotexts"; requests must be honoured within ten business days. 64.1200(d)(3) — a do-not-call request must be honoured within a reasonable time "not to exceed ten (10) business days" and remains valid five years | `https://www.law.cornell.edu/cfr/text/47/64.1200` | 47 CFR § 64.1200 | 2026-09-09 | Proven as a reproduction; the official eCFR could not be opened (§2.1) |
| R5 | FCC order DA 26-12 extends the effective date of the 47 CFR 64.1200(a)(10) "revoke-all" requirement — the obligation to treat a revocation sent in response to one type of message as applying to all future robocalls and robotexts on unrelated matters — from 11 April 2026 to **31 January 2027**: "We extend the effective date of any such requirement until January 31, 2027." All other parts of the revocation rule took effect 11 April 2025 | `https://docs.fcc.gov/public/attachments/DA-26-12A1.txt` | FCC DA 26-12 | 2026-09-09 | Proven (FCC order text) |
| R6 | The Eleventh Circuit vacated the FCC's TCPA "one-to-one consent" rule in *Insurance Marketing Coalition v. FCC* days before its 27 January 2025 effective date, holding the FCC exceeded its statutory authority in redefining "prior express consent"; the FCC subsequently removed the vacated language | Law-firm analyses (Morrison Foerster, Wiley, Kelley Drye, Reed Smith, Venable) | — | 2026-09-09 | **Conditional** — no primary court or FCC document was opened; the secondary sources are numerous, independent and consistent, but counsel must confirm the current codified text |
| R7 | CTIA Messaging Principles and Best Practices (May 2023): "A Consumer opt-in to receive messages should not be transferable or assignable. A Consumer opt-in should apply only to the campaign(s) and specific Message Sender for which it was intended or obtained." Consumers must be able to opt out "at any time"; senders "should support multiple mechanisms of opt-out, including phone call, email, or text"; senders should send "one final opt-out confirmation message per campaign" and nothing after it. Standardized STOP wording should be used, but stop/end/unsubscribe/cancel/quit and "please opt me out" "should also be read and acted upon", and validity "should not be impacted by any de minimis variances… such as capitalization, punctuation, or any letter-case sensitivities". Senders "should not use opt-in lists that have been rented, sold, or shared". Senders "should retain and maintain all opt-in and opt-out requests in their records" and should process deactivation files regularly. A recurring-campaign opt-in confirmation must carry program name, customer-care contact, how to opt out, recurrence and frequency, and fee language | `https://api.ctia.org/wp-content/uploads/2023/05/230523-CTIA-Messaging-Principles-and-Best-Practices-FINAL.pdf` | Messaging Principles and Best Practices, May 2023 | 2026-09-09 | Proven for the May 2023 text. **Conditional on being current** — `ctia.org` itself would not load (§2.1), so a newer revision could exist |
| R8 | Brands cannot register with The Campaign Registry directly; a Campaign Service Provider registers on the brand's behalf. TCR guidance for Canadian brands is to enter the CRA-issued Business Number, first nine numeric digits only, with an address matching the Corporations Canada registration | `https://www.campaignregistry.com/` plus TCR CSP user-guide PDFs surfaced in search | The Campaign Registry | 2026-09-09 | **Conditional** — the CSP user guide is a PDF that was not opened; **this directly conflicts with T12**, which tells Canadian brands to avoid the CRA Business Number. Carried to §10 |
| R9 | Third-party platforms report that Canadian long codes purchased on or after 26 March 2025 require A2P registration or persona verification before sending to Canadian subscribers, that numbers bought earlier are exempt for Canada-only traffic, and that Rogers, Bell and Telus filter unregistered A2P traffic aggressively | HighLevel/LeadConnector support articles and vendor blogs surfaced in search | — | 2026-09-09 | **Conditional / contested** — secondary, platform-specific, and **contradicts T19**, which lists Canada's only pre-registration requirement as short-code approval. Carried to §10 as the largest launch blocker |

### 2.4 Repository sources

Every repository citation was read in this lane's worktree at base commit `6c820c801da08ecfd6165d1d3a52ae6336606f0c`.

| # | Claim | Path |
|---|---|---|
| E1 | Launch architecture, ISV/brand-per-Business finding, shared-account risks, Slice 4 prerequisites | `docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md` |
| E2 | §28.1a's required policy shape, §28.4's gate, §10.6's funding sequence, §12 wallet/payer model, §13 number lifecycle, §20 cost-control invariants, §21/§22 slice boundaries | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` |
| E3 | Money is signed 64-bit integer micro-units, 1 unit = 1/1,000,000 of the currency's major unit; no decimal or float money column | `docs/automation/RFC-005-M3-CONTRACT.md:290`, `docs/automation/RFC-005-M2-CONTRACT.md:266` |
| E4 | `BusinessUsageRate` is fully immutable once inserted — no `updated_at`, `$timestamps = false`; fields `meter_key`, `version`, `retail_rate_micro`, `provider_cost_micro`, `unit_label`, `rounding_rule`, `currency_id` | `app/Models/BusinessUsageRate.php` |
| E5 | `RoundingRule` has exactly one case, `RoundHalfUp` | `app/Enums/Usage/RoundingRule.php` |
| E6 | `setActiveRate()` hardcodes `RoundingRule::RoundHalfUp`, allocates the next `version` per meter, writes an activation row and repoints `usage_meters.active_rate_id`, all in one transaction | `app/Library/Usage/UsageWalletManager.php:1083` |
| E7 | `reserve()` resolves the meter by key, rejects a business-scope or currency mismatch, requires a non-null `active_rate_id` and `is_metered`, computes `reserved_amount_micro = bcRoundHalfUp(retail_rate_micro × quantity)`, and snapshots `rate_id`, `rate_version`, `retail_rate_micro`, `provider_cost_micro` and `rounding_rule` onto both the reservation and the ledger row | `app/Library/Usage/UsageWalletManager.php:285` |
| E8 | Reservation TTL is **30 minutes** (`RESERVATION_TTL_MINUTES = 30`); `expireStaleReservations()` releases anything still pending past `expires_at` | `app/Library/Usage/UsageWalletManager.php:74`, `:1063` |
| E9 | `commit()` computes `final_amount_micro = bcRoundHalfUp(reservation.retail_rate_micro × finalQuantity)` — settlement varies **only** by quantity, never by unit price; overage draws available balance then debt; underage releases the difference | `app/Library/Usage/UsageWalletManager.php:544` |
| E10 | `usage_meters.meter_key` carries a **single-column unique index** as well as a `(meter_key, currency_id)` unique; `business_id` is **nullable**, so platform-wide meters are supported | `database/migrations/2026_08_22_120001_create_usage_meters_table.php:18-32` |
| E11 | Ledger entry types include `UsageChargeReversal`, `CorrectionReversal`, `Refund`, `PromotionalCredit`; reservation statuses are Pending/Committed/Released/Expired | `app/Enums/Usage/UsageLedgerEntryType.php`, `app/Enums/Usage/UsageReservationStatus.php` |
| E12 | The phone normalizer parses to E.164 with **no default region** and rejects any number lacking an explicit country code; it does **not** hardcode `+1` | `app/Library/AgencyProspecting/AgencyProspectPhoneNormalizer.php` |
| E13 | `BusinessMessagingIdentity` does not exist yet; `SendingServer::TYPE_TELNYX` is only a legacy/BYO credential holder | `app/Models/SendingServer.php:96`; absence verified across `app/` and `database/` |
| E14 | The only existing metered feature is the RFC-005 Milestone 5 pilot, keyed `conversations.pilot.{businessId}` against `PlatformFeature::Conversations`; no telecom meter exists | `app/Console/Commands/ActivateConversationsUsageRate.php:111` |
| E15 | **Auto-recharge is off by default**, in both the schema default and wallet initialisation — `boolean('auto_recharge_enabled')->default(false)` and `'auto_recharge_enabled' => false` | `database/migrations/2026_08_16_120001_create_business_usage_wallets_table.php:44`; `app/Library/Usage/UsageWalletManager.php:99` |
| E16 | **A reservation's `expires_at` is written once at `reserve()` and is never updated anywhere.** No extension or renewal path exists; `findExpiredPending()` selects purely on `expires_at < now()`. The 30-minute TTL is a hard ceiling, and `RESERVATION_TTL_MINUTES` is a single global constant shared by every metered feature | `app/Library/Usage/UsageWalletManager.php:461`; `app/Repositories/Eloquent/EloquentBusinessUsageReservationRepository.php:35` |

---

## 3. US local long-code launch analysis

**Framing.** AI Business OS is an ISV by Telnyx's own definition — "a type of business that sells products and services to other end users who are also businesses" (T10). Every requirement below therefore applies **per Business**, not once per platform. This is the single largest driver of onboarding cost and latency, and it is architecture-independent (E1 §11).

### 3.1 Proven requirements

| Topic | Requirement | Source |
|---|---|---|
| A2P 10DLC | Mandatory for A2P traffic on US long codes. "From February 3rd 2025, any 10DLC traffic which is not registered will be blocked altogether." | T11, T19, T20 |
| Separate brand per end Business | "for every end-user you're reselling to, you will need to create a separate brand." A number may never carry two brands: "no two brands can be on the same number." | T10 |
| Campaign registration | **At least one** campaign per brand, each with a declared use case, vertical, description, message flow, sample messages, and opt-in/opt-out/HELP keyword and message text. **A brand may hold up to five campaigns** — see §3.6 | T11, T13 |
| Number-to-campaign assignment | "A Number can only be used in one Campaign and its parent Brand." A campaign may hold multiple numbers, up to a T-Mobile limit of 49 stated as of September 2023; the sole-proprietor path allows exactly **1** | T11, T10, T9, T18 |
| Messaging Profile relationship | "Every phone number you use for messaging must be assigned to a messaging profile", and a number "must be assigned to a messaging profile first" before campaign assignment. The profile is the locked architecture's per-Business boundary (§1 item 2). **Profiles are orthogonal to brands and campaigns** — the messaging-profile documentation states no relationship to either | T31, T18, T10 |
| Sole proprietor vs EIN | Both paths exist. Standard registration needs an EIN; the sole-proprietor path needs no EIN but is capped at 1 campaign and 1 number, carries low-volume throughput, and requires SMS OTP identity verification within a 24-hour window | T9, T12 |
| Opt-in evidence | Opt-in must be explicit and text-specific: "Opt-in language must be specific just for text messages. It can not include e-mail or phone calls." Accepted methods: keyword, web form with an **optional** phone field, verbal, or signed written consent. Purchased or repurposed lists are invalid | T14, T15, R7 |
| Privacy policy and terms | Both must be reachable by **link, not pop-up**. The privacy policy must state that opt-in is not shared with third parties for unrelated purposes. Terms must carry program name, message frequency, product description, customer-care contact, opt-out instructions and rate notices | T14 |
| Prohibited content | Cannabis/CBD, gambling, SHAFT, controlled substances, deceptive financial and crypto, securities trading, SEO services, debt collection, unregistered third-party traffic. Alcohol may be permitted with age verification but "policies vary by carrier" | T16 |
| STOP handling | STOP/UNSUBSCRIBE must be honoured within 24 hours (Telnyx AUP). US federal rule is stricter in scope and looser in time: revocation by any reasonable means must be honoured within ten business days, and stop/quit/end/revoke/opt out/cancel/unsubscribe all count | T15, R4 |
| HELP handling | A HELP reply is required and must contain the program name or product description plus customer-care contact information | T14 |
| Quiet hours | 47 CFR 64.1200(c)(1) bars telephone solicitations before 8 a.m. or after 9 p.m. local time at the called party's location | R4 |
| Campaign review timing | Brand and campaign data go to TCR for manual review. Sole-proprietor campaigns "are typically auto-approved and become ACTIVE immediately". Carrier propagation after number assignment takes 24–72 hours | T11, T9, T18 |
| Recurring and one-time fees | See §6 | T8, T9 |
| Resubmission fees | $15 "per Campaign Review" — the fee attaches to the submission, not the outcome | T8 |
| Inactivity suspension | 15 consecutive days with no activity and no active assigned numbers auto-suspends the campaign, avoiding T-Mobile's "$250 per month fine". Reactivation is free but needs a documented double-assignment | T17 |
| Throughput | **AT&T assigns throughput per campaign** by Message Class, 75–4,500 TPM by vetting score; **T-Mobile caps daily volume per brand**, 2,000–200,000, and "all your Campaigns combined must share the daily limit". Sole proprietor gets 15 TPM on AT&T. Splitting traffic across more numbers does not raise the MPS limit. **The whole platform account is additionally capped at 50 MPS SMS and 15 MPS MMS across every Business** | T11, T24 |
| Per-Business provider-side spend cap | A messaging profile carries an optional `daily_spend_limit`, **disabled by default**, that rejects further messages with error `40333` and fires a webhook and email. Because one profile maps to one Business, this is a provider-side per-Business cap that complements the wallet-side cap | T31 |
| Carrier filtering | Unregistered traffic is blocked outright. Generic URL shorteners are "a major source of message blocking" | T11, T16 |
| Record retention | Telnyx's compliance guide states US retention is "Recommended 4+ years"; CTIA says senders "should retain and maintain all opt-in and opt-out requests". The FCC do-not-call rule keeps requests valid five years | T19, R7, R4 |

### 3.2 The decisive operational answer

**May an unregistered number send anything during onboarding? No.** T11 is unambiguous: unregistered 10DLC traffic is blocked altogether, and has been since 3 February 2025. There is no permitted "warm-up", trial send, or test message to a real US handset from an unregistered long code.

This has a direct product consequence. The onboarding flow must never present sending as available before the campaign is approved and the number assigned and propagated. Any "send a test message" affordance before that point will silently fail at the carrier, and the platform would be inferring success from a local row — exactly what the parent contract forbids (E2 §21.2). The state machine in §9 encodes this as a hard block.

### 3.3 Platform responsibilities versus customer responsibilities

| Responsibility | Owner | Basis |
|---|---|---|
| Holding the Telnyx account, credential custody, messaging profiles, webhook verification | **Platform** | E1 §6, §9 |
| Creating and funding one brand and campaign per Business, and paying the provider | **Platform**, recovered from the customer's wallet at retail | T8, T10, E2 §12.1 |
| Truthful business identity, EIN or registry ID, address, website | **Customer**, collected and transmitted by the platform | T12 |
| Lawful, documented opt-in for every recipient | **Customer** | T14, T15 |
| Publishing a compliant privacy policy and terms at a linkable URL | **Customer**; the platform should supply a compliant default and verify the link resolves | T14 |
| Message content staying inside the declared use case and out of forbidden categories | **Customer**, with platform guardrails | T16 |
| Honouring STOP within 24 hours and HELP always | **Platform must implement it in the transport layer**, because the customer cannot be trusted to do it per message and Telnyx holds the account | T15, R7 |
| Retaining consent evidence and opt-out records | **Both** — the customer supplies the evidence, the platform must store it durably and produce it on demand | T19, R7 |
| Monitoring campaign dormancy and reactivating | **Platform** | T17 |

### 3.4 Canadian recipients reached from US numbers

**Unresolved, and it matters.** T19 places Canada's pre-registration requirement at "Short code approval" only, and lists long code as supported. T20 confines 10DLC to US long code numbers. Neither states whether a US 10DLC registration covers, or is required for, delivery to a Canadian handset. R9's secondary sources assert the opposite of T19 for Canadian-origin numbers and say nothing definitive about US-origin traffic to Canada.

This document does not guess. It is question Q1 in §10, and it is a **launch blocker for the Canadian half of the proposed scope** — see §4.6.

### 3.5 Entity model and exact cardinality — Correction Round 1

The original pass wrote "one brand and one campaign per Business". The brand half is right and required; **the campaign half was an invented limit.** The sources impose a maximum, not a minimum of one, and a Business with two genuinely distinct approved use cases may legitimately need two campaigns.

**Four distinct entities, deliberately not conflated:**

| Entity | What it is | Whose identity it carries |
|---|---|---|
| **Brand** | The legal registration of the end Business with The Campaign Registry | The **end Business's** legal identity — name, entity type, EIN or registry ID, address, website |
| **Campaign** | A registered messaging programme for a declared **use case**, with its own sample messages, message flow and opt-in/opt-out/HELP copy | A *purpose* of that Business's messaging, not the Business itself |
| **Messaging Profile** | Telnyx's configuration object: groups numbers, defines webhook URLs, carries smart encoding and the daily spend limit | The **platform's** per-Business routing and control boundary |
| **Phone number** | The sender address | Assigned to one profile and one campaign |

**Exact supported relationships, each traceable to a quotation:**

| Relationship | Cardinality | Source |
|---|---|---|
| Business → Brand | **Exactly 1.** "for every end-user you're reselling to, you will need to create a separate brand" | T10 |
| Brand → Campaign | **1 to 5.** "A Brand can have multiple Campaigns, with a maximum of five Campaigns per Brand" | T11 |
| Campaign → Brand | **Exactly 1.** "each campaign can only be associated with one brand" | T10 |
| Campaign → Numbers | **1 to 49.** "A Campaign can have multiple Numbers. As of September 2023, the T-Mobile Limit is 49 numbers" | T11, T10 |
| Number → Campaign | **Exactly 1.** "A Number can only be used in one Campaign and its parent Brand" | T11, T18 |
| Number → Brand | **Exactly 1, derived** via its campaign. "No two brands can be on the same number" | T10, T11 |
| Number → Messaging Profile | **Exactly 1.** "Every phone number you use for messaging must be assigned to a messaging profile" | T31 |
| Messaging Profile ↔ Brand or Campaign | **No documented relationship.** The profile is orthogonal | T31 |
| Sole proprietor brand → Campaign | **Exactly 1**, and that campaign holds **exactly 1** number | T9 |

**Two ways to serve multiple use cases, both supported:**

1. **One Mixed-use-case campaign**, which Telnyx recommends specifically "in order to reuse the same phone number", requiring a sample message for each declared use case. One number, one campaign, several use cases.
2. **Several campaigns under the one brand**, up to five, **each needing its own number** — because a number belongs to exactly one campaign. This costs an extra number rental and an extra monthly campaign fee per campaign.

**The platform rule that follows.** Do not model "one campaign per Business" in the schema or the onboarding flow. Model **one brand per Business** as the hard invariant that prevents cross-Business sharing, and treat campaigns as a **collection under that brand**, bounded at five, with each campaign owning its own numbers. Cross-Business sharing is prevented by the brand boundary and by the number-to-one-campaign rule, neither of which requires capping a Business at one campaign.

**Cost and throughput consequences of a second campaign**, which the customer must be told before choosing it: one extra number rental and one extra monthly campaign fee (§6.3); AT&T throughput is assigned **per campaign**, so a second campaign gets its own AT&T allowance; T-Mobile's daily cap is **per brand**, so a second campaign gets no extra T-Mobile headroom and the two share one allowance (T11).

**Unresolved.** Whether the five-campaign ceiling and the 49-number figure are still current — the 49 is explicitly dated "as of September 2023" in the source. Question Q10.

### 3.6 What requires counsel or Telnyx confirmation

* Whether TCPA quiet hours (R4) apply to A2P text messages as opposed to voice solicitations, and whether a given customer's messages are "telephone solicitations" — **counsel**.
* Whether a customer's specific opt-in flow constitutes prior express written consent — **counsel**.
* The current codified state of "prior express consent" after the Eleventh Circuit vacatur (R6) — **counsel**.
* The precise 10DLC record-retention obligation, as distinct from a recommendation — **Telnyx**, question Q7.

---

## 4. Canada local long-code launch analysis

**US 10DLC rules are not assumed to apply here, and Canadian rules are not assumed to apply to the US.** Telnyx's own compliance guide treats the two countries as separate regimes with different governing law, consent standards, opt-out mechanics and retention rules (T19).

### 4.1 CASL — proven from the statute

| Requirement | Statutory rule | Source |
|---|---|---|
| Scope | A commercial electronic message is one it "would be reasonable to conclude has as its purpose, or one of its purposes, to encourage participation in a commercial activity". Text messages are covered | R1, R3 |
| Consent | Prohibited to send without consent, "whether the consent is express or implied" | R1 |
| Implied consent | Existing business relationship or existing non-business relationship within the **two-year period** immediately before the message; an inquiry or application within the **six-month period** immediately before; or conspicuous publication of the address | R2 |
| Sender identification | The message must identify the sender and any person on whose behalf it is sent | R1 |
| Contact information | Must be provided and must remain "valid for a minimum of 60 days after the message has been sent" | R1 |
| Unsubscribe mechanism | Must be set out in every message, per s.11(1) | R1 |
| Unsubscribe timing | Effect must be given "without delay, and in any event no later than 10 business days" | R2 |
| Unsubscribe validity | The unsubscribe address or page must be "valid for a minimum of 60 days after the message has been sent" | R2 |
| Recordkeeping | CASL itself imposes the burden of proving consent; Telnyx's guide states Canadian retention runs for the "Duration of consent" | R1, T19 |

**Comparison with the US, stated plainly.** Canada is *looser* on consent (implied consent is a real statutory category with defined windows; the US marketing standard is express written consent) and *stricter* on message content (every message must carry sender identity, valid contact information and an unsubscribe mechanism — the US has no equivalent per-message identification mandate). A US-designed compliance flow will therefore under-serve Canada on message construction while over-serving it on consent capture. The onboarding flow must branch, not reuse.

### 4.2 Toll-free verification applicability

Toll-free MMS is "supported (US and Canada _only_)" (T22), and toll-free verification is framed as a US wireless carrier requirement (T21). Whether Canadian carriers require or honour the same verification is not stated on either page. Unresolved; question Q4.

### 4.3 A2P registration and carrier-specific requirements

This is the central Canadian unknown, and the evidence conflicts:

* **T19 (Telnyx primary):** Canada's pre-registration column reads "Short code approval". Long code is marked supported with no registration precondition.
* **T20 (Telnyx primary):** 10DLC is scoped to "US long code numbers"; Canada is not mentioned.
* **T11 (Telnyx primary):** the 10DLC FAQ never mentions Canada.
* **T12 (Telnyx primary):** yet the brand-creation flow explicitly accommodates Canadian entities, telling them to supply a Provincial or Federal Corporation/Registry ID in place of an EIN — which only makes sense if Canadian brands are expected to register.
* **R9 (secondary, contested):** several downstream platforms state that Canadian long codes bought on or after 26 March 2025 must be A2P-registered before reaching Canadian subscribers, and that Rogers, Bell and Telus filter unregistered A2P traffic.
* **R8 (secondary, contested):** TCR tells Canadian brands to use the CRA Business Number, which directly contradicts T12.

**Conclusion:** Telnyx's own published material is internally consistent that Canada needs no long-code pre-registration, but it is silent on the carrier filtering that R9 describes and on why the brand form accommodates Canadian registries at all. Silence is not permission. §4.6 states the consequence.

### 4.3a Canadian pricing — what was and was not found (Correction Round 1)

The original pass said Canadian pricing "does not exist" and that Canadian costs are "simply not published". **That overstated the evidence.** The accurate statement separates two different things:

| | Status |
|---|---|
| **Canadian local number monthly rental** | **Found, as a lower bound.** "Local numbers for Canada start at $1 per month" (T33). A "start at" figure is a floor, not a firm rate for a specific number, so it is not yet sufficient to derive a margin floor |
| **Whether the $0.10 monthly SMS/MMS capability charge applies to Canadian numbers** | **Not found** in the material reviewed |
| **Canada-destination SMS base rate** | **Not found.** The messaging pricing page publishes no Canada-destination rate and carries no destination selector (T3) |
| **Canada-destination MMS base rate** | **Not found**, same source |
| **Canadian outbound carrier surcharges** | **Not found.** Canadian carriers appear only in the inbound table, showing no fee (T3) |

**"Not found" is not "confirmed absent."** Telnyx publishes `GET /v2/public/pricing` as the authoritative machine-readable per-destination rate source (T5), and directs destination-specific enquiries to sales (T3). Both routes very probably do carry Canadian rates. **This lane deliberately did not call that endpoint**, because the task forbids calling the Telnyx API, and did not contact sales. So the honest position is that an authoritative Canadian price **sufficient for rate activation was not located in the published material reviewed on 2026-09-09** — not that none exists.

**The consequence for the gate is unchanged.** §7.13 forbids activating a rate whose worst-case provider cost is not evidenced. A "start at $1" floor and four unfound figures do not meet that bar. Canada therefore stays blocked on §7.13 for a reason that is now stated precisely: the evidence has not been gathered, and gathering it requires owner authorisation (D10) rather than more searching.

### 4.4 Canadian-origin and US-origin cross-border traffic

Neither direction is documented by a primary source read in this pass:

* **Canadian number → US recipient.** R9 asserts A2P registration is required. This is plausible on its face, because the US requirement (T11) attaches to the *destination* carrier's network, not the origin number's country. But no Telnyx page says so.
* **US number → Canadian recipient.** Undocumented in both directions of inference. See §3.4.

Both are question Q1.

### 4.5 Numbers, presence and prohibited content

| Topic | Finding | Source |
|---|---|---|
| Number purchasing documentation | Canada Local: "Not required". Canada Toll-free: "Not required" | T29 |
| Local presence or address requirement | None stated for Canada or the US | T29 |
| Prohibited content | The same forbidden-use-case list covers both countries, with Canada explicitly stricter on alcohol and age-sensitive content | T16 |
| Carrier filtering and throughput | Telnyx publishes no Canada-specific throughput figure. The generic non-US long code limit is 0.1 MPS per number, but Canada's applicability is not stated. The account-wide 50 MPS SMS ceiling applies regardless | T24 |
| Emergency services | Out of scope — voice is excluded from every slice (E2 §10.5), so no E911 or 9-1-1 obligation attaches to a messaging-only number in this contract. **This must be re-examined the moment voice is contracted.** | E2 §10.5 |
| Alphanumeric sender ID | "not supported for US and Canadian destinations" | T19 |

### 4.6 Can Canada safely share the initial US onboarding model?

**No — not on the evidence available, and not at launch.** Three independent reasons:

1. **Consent capture differs.** CASL's implied-consent categories with two-year and six-month windows have no US analogue. A US-shaped consent intake would either fail to record the basis Canadian law relies on, or would demand express consent the law does not require, needlessly costing the customer opt-ins.
2. **Message construction differs.** CASL requires sender identity, 60-day-valid contact information and an unsubscribe mechanism *in every message* (R1). US 10DLC requires none of that per message. A shared template will be non-compliant in Canada or over-built in the US.
3. **Registration status is genuinely unknown.** §4.3's conflict is unresolved, and it decides whether a Canadian Business needs a brand, a campaign, a review fee and a monthly fee at all — which in turn decides its entire cost model and onboarding latency. Building a Canadian onboarding flow before that answer arrives risks building the wrong one.

**Recommendation:** see §5.4. Canada is deferred to a fast second wave, not cancelled.

---

## 5. Number-type decision

### 5.1 Comparison

| Criterion | US local long-code | Canada local long-code | Toll-free SMS | Short code | Alphanumeric sender ID |
|---|---|---|---|---|---|
| Availability | Yes, instant provisioning (T6, T19) | Yes, no documentation required (T29, T19) | Yes, US and Canada (T22) | US only in Telnyx's docs; no Canada mention (T23) | **Not supported for US or Canadian destinations** (T19) |
| Registration | 10DLC brand + campaign, mandatory (T11) | **Unresolved** (§4.3) | Toll-free verification, plus three new business-registration fields from 17 Feb 2026 (T21) | Carrier certification (T23) | n/a |
| Setup time | Brand then campaign review, then 24–72h carrier propagation (T18); sole proprietor auto-approves (T9) | Unresolved | 1–2 weeks verification (T21), 2 weeks use-case approval (T22) | **8–12 weeks** random, 10–12 weeks vanity (T23) | n/a |
| Recurring cost | $1.00 number + $0.10 messaging + $1.50–$10 campaign (T6, T8) | Number rental published only as **"start at $1 per month"** (T33); campaign fees and whether the $0.10 capability charge applies were **not found** (T3, T6, Q3) | $1.00 number + $0.10 messaging; $500 OTC + $40 MRC for 800-prefix (T6, T7) | "monthly lease" — **no figure published** (T23) | n/a |
| Message cost, per message part | Local sender: $0.004 SMS base + up to $0.005 carrier (T1, T2) | Canada-destination base rate and carrier surcharge **not found in the material reviewed** (T3, §4.3a) | **Different base rates** — SMS $0.0055, MMS out $0.016 (T1) | **Different base rates** — SMS $0.007, MMS out $0.018 (T1), plus lease | n/a |
| Deliverability | High once registered; blocked entirely if not (T11) | Carrier filtering asserted but unquantified (R9) | "may result in spam blocks" if unverified (T22) | Highest | n/a |
| Two-way | Yes | Yes | Yes | Yes, keyword-based (T23) | Typically one-way |
| Customer fit | Excellent — a local business wants a local number recipients recognise | Excellent, same reason | Poor — a toll-free number reads as a call centre, not a neighbourhood business | Very poor — cost and lead time are absurd for a single local business | n/a |
| Operational complexity | High but bounded: one brand per Business, one to five campaigns under it, each campaign owning its own numbers (§3.5) | Unknown until §4.3 resolves | Medium: verification per number, no brand/campaign tree | Very high | n/a |
| Supported by the locked architecture? | **Yes** — this is exactly Telnyx's documented ISV pattern (T10) | Yes architecturally; blocked on compliance facts | Yes | Yes, but one short code cannot be shared across Businesses without violating the one-brand-per-number rule (T10) | No |

### 5.2 What the evidence eliminates

* **Alphanumeric sender ID** is eliminated outright by T19. It is not a judgement call.
* **Short code** is eliminated by lead time and cost. An 8–12 week certification with an unpublished monthly lease cannot serve a self-serve SaaS onboarding flow, and a shared short code would breach the one-brand-per-number rule (T10).
* **Toll-free** is eliminated as the *launch default* on customer fit, not on mechanics. It remains the right answer for a later high-volume or national customer, and it is the natural fallback if Canadian long-code registration turns out to be blocked.

### 5.3 What the evidence supports

**US local long-code is confirmed as the launch number type.** It is Telnyx's own documented ISV pattern (T10), it maps one-to-one onto the locked architecture, it is instantly provisionable, its costs are fully published, and it is what a local business actually wants.

### 5.4 Recommended launch scope — a correction to the expected answer

The task anticipated **US and Canada local long-code**, and instructed that the recommendation change if primary evidence disproves it. It partly does.

> **Recommended launch scope: United States local long-code only, with Canada as a fast-follow second wave gated solely on the answer to §10's Q1–Q4.**

**Why the narrowing is the narrowest *safe* scope, not a retreat:**

* Every US requirement is proven from primary sources and every US price is published (§3, §6). Nothing about US launch rests on an unresolved fact.
* Canada's *compliance* rules are proven from the statute (§4.1), but its *registration* status is contested between two Telnyx pages and a body of secondary reporting (§4.3), and **no authoritative Canadian price sufficient for activation was found in the published material reviewed** (§4.3a). One Canadian figure was located — "Local numbers for Canada start at $1 per month" (T33) — but a "start at" floor is not a rate, and the four remaining figures were not found. §7's margin floor cannot be evaluated against evidence that has not been gathered, so §28.1a cannot be satisfied for Canadian meters. Activating a Canadian rate today would mean inventing a price, which this document is forbidden to do and which §7.13 prohibits.
* The two blockers are the same short list of questions (Q1–Q4). This is a days-to-weeks deferral contingent on a support reply, not a roadmap change.

**What "US only" concretely means at launch:** managed numbers are US local long codes; the outbound destination allowlist is US-only (§7.12); Canadian recipients are refused with a clear, task-oriented message naming Canada as coming soon, never a silent failure. Canada's local long-code option ships the moment Q1–Q4 are answered and Canadian rates clear §7's margin floor.

**Voice remains out of scope** for every number type, per E2 §10.5.

---

## 6. Provider-cost inventory

All figures USD, accessed 2026-09-09, pay-as-you-go tier, no volume discount assumed. Volume discounts exist but begin far above any credible launch volume (T1, T6) and are therefore excluded from every calculation.

### 6.1 Cost classification

| Class | Meaning | Items |
|---|---|---|
| **Telnyx base price** | Telnyx's own published rate | SMS/MMS base rates; number rental; messaging capability |
| **Carrier / pass-through surcharge** | Set by the destination carrier, varies per message | US carrier fees per part |
| **Compliance fee** | Registry/carrier fee, explicitly passed through at cost | Brand registration, campaign review, campaign monthly |
| **Recurring fixed** | Predictable monthly | Number rental, messaging capability, campaign monthly |
| **Variable usage** | Per message, depends on volume, encoding and destination carrier | SMS/MMS |
| **Unknown / quote-only** | Not published; must be obtained before any rate activates | All Canadian rates; short code lease |

### 6.2 Variable usage — United States, local (10DLC) sender

**Base rates depend on sender type** (T1), corrected in Round 1. The launch sender type is the local long code, so the local column governs every calculation in this document. The others are shown once, to make the dependency explicit and to prevent a later toll-free or short-code decision from silently reusing local numbers.

| Telnyx base, per message part | Local (10DLC) | Toll-free | Short code |
|---|---|---|---|
| SMS outbound | **$0.0040** | $0.0055 | $0.0070 |
| SMS inbound | **$0.0040** | $0.0055 | $0.0070 |
| MMS outbound | **$0.0150** | $0.0160 | $0.0180 |
| MMS inbound | **$0.0050** | $0.0160 | $0.0090 |

**Total cost for the launch sender type**, base plus carrier surcharge:

| Item | Telnyx base | Carrier surcharge range | Total range | Class |
|---|---|---|---|---|
| SMS outbound, per message part | $0.0040 | $0.0035 (AT&T) – $0.0050 (U.S. Cellular) | **$0.0075 – $0.0090** | Base + pass-through |
| SMS inbound, per message part | $0.0040 | $0.0000 (Verizon, U.S. Cellular, Dish) – $0.0035 (AT&T) | **$0.0040 – $0.0075** | Base + pass-through |
| MMS outbound, per message part | $0.0150 | $0.0070 (Verizon) – $0.0100 (T-Mobile, U.S. Cellular, Dish) | **$0.0220 – $0.0250** | Base + pass-through |
| MMS inbound, per message part | $0.0050 | $0.0000 (Verizon, U.S. Cellular, Dish) – $0.0100 (T-Mobile) | **$0.0050 – $0.0150** | Base + pass-through |

Sources: T1, T2. The relevant worst cases are the upper bounds, and every one is below one cent per part — a direct consequence of confining the destination scope to the US (T4).

**No per-megabyte MMS surcharge applies.** The $0.001-per-MB-over-5MB charge in the pass-through table is a **T-Mobile RCS Rich Media** charge, and RCS is out of scope (T35). MMS payloads are instead bounded by attachment size — under 1 MB, and as low as 300 KB on Tier 3 carriers (T34) — which is a delivery constraint, not a cost variable.

### 6.3 Recurring fixed — per Business per month

| Item | Cost | Class |
|---|---|---|
| Local number rental | $1.00 | Recurring fixed (T6) |
| SMS/MMS capability on that number | $0.10 | Recurring fixed (T6) |
| 10DLC campaign monthly — Low Volume Mixed | $1.50 | Compliance fee, recurring (T8) |
| 10DLC campaign monthly — Standard | $10.00 | Compliance fee, recurring (T8) |
| 10DLC campaign monthly — Charity | $3.00 | Compliance fee, recurring (T8) |
| 10DLC campaign monthly — Sole Proprietor | $2.00 | Compliance fee, recurring (T8, T9) |
| **Minimum realistic recurring total** | **$2.60** (number + capability + Low Volume Mixed) | |
| **Standard-volume recurring total** | **$11.10** | |

### 6.4 One-time — per Business at onboarding

| Item | Cost | Class | Note |
|---|---|---|---|
| Number purchase/setup | $0.00 | — | No one-time fee for US local (T6) |
| 10DLC brand registration | $4.50 standard / $4.00 sole proprietor | Compliance fee, one-time | **Conflict between T8 and T9**; question Q6 |
| 10DLC campaign review | $15.00 per submission | Compliance fee, per submission | Charged per review, not per approval (T8) |
| Campaign fee prepayment | 3 × the monthly campaign fee | Compliance fee | "billed for three months initially" (T8) |
| **Typical Low Volume Mixed onboarding** | $4.50 + $15.00 + $4.50 = **$24.00** | | |
| **Typical Sole Proprietor onboarding** | $4.00 + $15.00 + $6.00 = **$25.00** | | |
| **Typical Standard Volume onboarding** | $4.50 + $15.00 + $30.00 = **$49.50** | | |

This alone vindicates parent contract §10.6: the $5 top-up minimum is a floor, not an estimate. Real onboarding costs the platform $24–$50 before a single message is sent.

### 6.5 Destination differences

Within the US the total per-part cost never exceeds $0.0090 outbound (§6.2). **Outside the US it can reach $0.20 per part** (T4, Digicel). The entire safety of a single flat published SMS price rests on the destination being confined to the US. §7.12 makes that confinement a rate-card rule, not merely a product preference.

### 6.6 Taxes and other provider costs

| Item | Finding | Class |
|---|---|---|
| US taxes, USF, TRS | Applied to the platform account, calculated daily and deducted from the balance "on the following calendar day". "there is no way to provide an accurate estimate in advance" | **Uncertain** (T28) |
| Canadian GST/HST | Not addressed on the taxes page | Unknown (T28) |
| Webhook delivery | No separate charge documented | — |
| Number lookup | Not required by the launch design; not priced here | — |
| Currency and precision | USD throughout. Telnyx states 4-decimal billing (T27), but the documented `message.finalized` example carries a five-decimal component ($0.00205). Treat provider precision as **at least** 5 decimals | T26, T27 |

**Consequence.** Provider cost is not final at send time. The per-message cost arrives with `message.finalized` (T26); taxes arrive a day later against the account as a whole and are never attributable to one message (T28). Any rate-card rule that promised exact per-message tax attribution would be unimplementable. §7.9 handles this.

### 6.7 What was not found, and was not invented

**Corrected in Round 1 (C2).** The distinction below is between *not located in the material reviewed* and *confirmed not to exist*. Only the first is claimed.

| Item | Status | Note |
|---|---|---|
| Canada-destination SMS base rate | **Not found** | No destination selector on the pricing page (T3) |
| Canada-destination MMS base rate | **Not found** | Same |
| Canadian outbound carrier surcharges | **Not found** | Canadian carriers appear only in the inbound table, showing no fee (T3) |
| Canadian local number monthly rental | **Partially found** — "start at $1 per month" (T33) | A floor, not a firm rate |
| Whether the $0.10 messaging capability charge applies to Canadian numbers | **Not found** | |
| Canadian toll-free verification applicability | **Not found** | T21 frames verification as a US carrier requirement |
| Short code monthly lease | **Not published** — the page says "Higher (monthly lease + per-message)" with no figure (T23) | Quote-only by the provider's own framing |

**Nothing here is asserted to be absent.** Telnyx publishes `GET /v2/public/pricing` as the authoritative machine-readable per-destination source (T5) and directs destination enquiries to sales (T3); both very probably carry the missing figures. **This lane did not call that endpoint and did not contact sales**, because the task forbids calling the Telnyx API. Doing so is owner decision D10 and is the recommended first action once provider contact is authorized.

---

## 7. Retail rate-card recommendation

**Everything in §7 is a recommendation awaiting owner approval. No rate here is activated, and §21.2 of the parent contract continues to forbid activating any telecom rate until §28.1a is approved.**

### 7.1 The structural insight that shapes the whole policy

`reserve()` reads an immutable `retail_rate_micro` from the active rate row and multiplies it by a quantity; `commit()` re-multiplies that **same snapshotted rate** by a final quantity (E7, E9). The runtime never computes a markup and cannot vary a unit price at settlement.

Therefore a retail policy expressed as "cost plus X%" applied at settlement is **not implementable without changing `commit()`**, and would also give the customer a price that varies with which carrier the recipient happens to use. The correct shape is:

> **A markup formula is a governance rule used by a human to derive a published unit price. The published unit price is what is stored, reserved and settled. The runtime performs no markup arithmetic.**

This is what makes the policy both financially safe and understandable, and it is why Model 3 below wins.

### 7.2 The three candidate models

**Model 1 — Simple percentage markup.** `retail = provider_actual × 1.60`, evaluated at settlement.

* Rejected. It needs the actual provider cost before charging, but that cost only exists at `message.finalized` (T26), after the reservation. It requires `commit()` to vary the unit rate, which it cannot (E9). And it makes the customer's price depend on the recipient's carrier, which is unexplainable at a support desk.

**Model 2 — Fixed per-segment markup.** `retail = provider_actual + $0.0070`.

* Rejected for the same two mechanical reasons, plus a commercial one: on a $0.0040 inbound message it yields a 175% markup, and on a hypothetical $0.20 international part it yields 3.5%. The margin percentage swings wildly with something the customer never sees.

**Model 3 — Hybrid published unit price with a minimum. RECOMMENDED.** A governance formula sets a floor; the owner publishes a clean price at or above that floor; the published price is stored immutably and used for both reservation and settlement.

* Implementable today against the existing ledger with no change to `commit()`.
* The customer sees one stable, quotable price per unit.
* Carrier-mix variance and provider price drift are absorbed by the platform and visible only in admin reporting, exactly matching cost-control invariant C-9 (E2 §20).

### 7.3 The governance formula

For each unit, with all values in micro-units (1 micro = $0.000001):

```
minimum_publishable = max(
    worst_case_provider_cost × 1.60,
    worst_case_provider_cost + 5000,
    5000
)
published_price = minimum_publishable, rounded UP to a clean increment
                  (500 micros for per-message units; 500,000 micros for monthly and registration units)
```

* `worst_case_provider_cost` is the **upper** bound of §6's range, never the midpoint. Margin is guaranteed against the most expensive carrier, not the average one.
* The `+ 5000` term is an absolute floor of half a cent, so a cheap unit still earns real money.
* The `5000` minimum is the **minimum per-message retail price**: half a cent. **No unit may ever be priced below it, and no price may ever be zero.**
* Rounding is **upward, always**, at every step. Nothing in this policy ever rounds toward the customer.

### 7.4 The proposed launch rate card

| Meter | Unit | Worst-case provider cost | Formula floor | **Published retail** | Retail micros |
|---|---|---|---|---|---|
| `telecom.sms.us` | per SMS message part, **inbound or outbound** — `unit_label` = `message part` | $0.0090 | $0.0145 | **$0.0150** | 15,000 |
| `telecom.mms.us` | per MMS message part, **inbound or outbound** — `unit_label` = `message part` | $0.0250 | $0.0400 | **$0.0450** | 45,000 |
| `telecom.number.monthly.us` | per number per month | $1.10 | $2.00 | **$2.50** | 2,500,000 |
| `telecom.registration.brand.us` | one-time per Business | $4.50 | $7.50 | **$9.00** | 9,000,000 |
| `telecom.registration.campaign_review.us` | per submission | $15.00 | $24.00 | **$25.00** | 25,000,000 |
| `telecom.registration.campaign_monthly.us.low_volume` | per month | $1.50 | $2.50 | **$3.50** | 3,500,000 |
| `telecom.registration.campaign_monthly.us.standard` | per month | $10.00 | $16.00 | **$16.00** | 16,000,000 |
| `telecom.registration.campaign_monthly.us.sole_proprietor` | per month | $2.00 | $3.50 | **$4.50** | 4,500,000 |

**Every Canadian meter is deliberately absent.** Canadian provider costs are unknown (§6.7), so no Canadian floor can be computed and no Canadian rate may be published. This is §7.13 applied to itself.

**Why one price for inbound and outbound.** Inbound costs less than outbound in every case (§6.2), so a single price is conservative on both. More importantly it produces a card a customer can hold in their head: *one and a half cents per text part, four and a half cents per picture message, sent or received.* Understandability was an explicit requirement, and the margin cost of the simplification is nil.

**Competitive sanity check.** $0.0150 per part sits comfortably below the $0.02–$0.05 per message common in small-business SMS SaaS, while clearing the platform's worst-case cost by 66.7%.

### 7.5 Precision, rounding and the zero-price prohibition

| Rule | Value | Basis |
|---|---|---|
| Internal monetary precision | Signed 64-bit integer **micro-units**, 1 = $0.000001 | E3 |
| Provider cost precision observed | 4 decimals stated, 5 decimals seen in practice | T26, T27 |
| Is internal precision sufficient? | **Yes** — micro-units are 10× finer than the finest provider figure observed | E3, T26 |
| Customer display — unit prices | 4 decimal places, e.g. `$0.0150` | Recommendation |
| Customer display — balances, estimates, totals | 2 decimal places, rounded **half-up for display only** | Recommendation |
| Charge-path rounding | **None.** Every published unit price is a whole number of micros and every telecom quantity is a whole integer, so `retail_rate_micro × quantity` is exact and no rounding ever engages | E7, E9, §7.6 |
| Rounding direction where it ever applies | **Ceiling, toward the platform.** Never floor | E2 §28.1a |
| Minimum per-message retail price | 5,000 micros ($0.005) | §7.3 |
| Zero-price prohibition | **A retail price of zero is invalid and must be rejected at activation.** Sub-cent pricing is represented natively in micros and is never floored to a cent | E2 §28.1a, E3 |

### 7.6 The integer-quantity invariant — how ceiling rounding is satisfied today

The parent contract requires ceiling rounding. The code has exactly one `RoundingRule` case, `RoundHalfUp`, and `setActiveRate()` hardcodes it (E5, E6). That looks like a conflict. It is not, provided one invariant holds:

> **Every telecom unit rate is a whole number of micros, and every telecom quantity is a whole integer** — message parts, messages, numbers, months, submissions are all naturally integral.

Under that invariant `retail_rate_micro × quantity` is exactly an integer, `bcRoundHalfUp` returns it unchanged, and half-up and ceiling are indistinguishable. The requirement is satisfied by construction rather than by a new enum case.

**This invariant is load-bearing and must be stated in the implementation contract.** If a fractional telecom quantity is ever introduced — a prorated part-month of number rental is the obvious candidate — half-up would round a `.5` micro toward the customer, and a `Ceiling` case must be added to `RoundingRule` **before** that happens. §11 records this.

### 7.7 The billing unit, and segmentation

**Corrected in Round 1 (C4).** The original pass called an MMS a "part" by analogy to SMS without verifying it. Verification found the term **is** the provider's own, for both channels — but with an asymmetry that must be stated rather than smoothed over.

> **The billing unit is the *message part*, for both SMS and MMS.** Telnyx's pricing page uses the identical wording — "per message part" — for SMS outbound, SMS inbound, MMS outbound and MMS inbound (T1), and states that "Prices are applied per message part."

**The asymmetry.** The *definition* of a message part is written in SMS terms: "A message part is either an entire SMS message or a component of one, depending on message characters and encoding. Longer messages may be split into multiple parts, and you are billed per-part" (T32). No published rule explains what would make an MMS more than one part. So the unit label is proven for MMS; the segmentation rule behind it is not. That gap is question Q8, and until it closes MMS is **estimated at one part and settled on the provider's own count**, never on a local assumption.

| Rule | Value | Basis |
|---|---|---|
| **Billing unit, both channels** | The **message part**. Never the message, and never the character | T1, T32 |
| SMS part in GSM-7 | 160 characters single, 153 per part when concatenated | T25 |
| SMS part in Unicode (UCS-2) | 70 characters single, 67 per part when concatenated | T25 |
| Encoding trigger | One non-GSM-7 character switches the **whole** message to UCS-2 | T25 |
| MMS part rule | **Undefined by the provider.** MMS uses UTF-8 and is not subject to SMS segment limits | T25, T34 |
| MMS payload bound | Attachment under 1 MB, ideally 100 KB below that; Tier 2 carriers 600 KB, Tier 3 300 KB. This is a **delivery** constraint, not a pricing one | T34 |
| Authoritative part count | The `parts` field on `message.finalized`, never a local estimate, for **both** channels | T26 |
| Estimate vs settlement | The reservation uses a locally computed estimate; `commit()` settles on the provider's `parts` value | E9, T26 |
| Smart encoding | Available and **recommended on by default**, since it reduces both customer cost and provider cost with no downside | T25 |

**One term, everywhere.** The rate card, the reservation quantity, the ledger `unit_label`, the customer-facing display copy and the tests must all say **message part**. Not "segment" in one place and "part" in another, and not "message" for MMS. `unit_label` on the rate row is the single place this string is defined, and the display copy must render it rather than restating it. Suggested customer wording: *"1.5¢ per message part — a text counts as more than one part if it is long or uses emoji."*

**Unicode disclosure is a product requirement, not a pricing one.** The same 160-character message costs 1 part in GSM-7 and 3 parts in UCS-2 — a 3× cost swing caused by one emoji. The composer must show the live part count and warn on the encoding switch before sending. Margin is unaffected (§8.6); the customer's bill is not.

### 7.8 Destination and carrier surcharges

Carrier surcharges are **absorbed into the published price, not passed through as a separate line.** Within the US every surcharge is under one cent and is already covered by the worst-case basis in §7.3. A visible surcharge line would expose the recipient's carrier to the sender, be unpredictable at estimate time, and be unexplainable. This absorption is only safe because §7.12 confines destinations to the US.

### 7.9 Taxes

**Taxes are not charged to the customer per message at launch.** T28 states plainly that taxes are computed daily against the account and cannot be estimated in advance, so per-message attribution is not merely hard, it is unsupported by the provider.

Treatment: taxes are a platform cost absorbed by the margin, tracked as a monthly platform-level line in admin reporting, and monitored as a margin input. If observed taxes ever consume more than 10% of gross telecom margin in a month, that is a rate-card revision trigger (§7.17), not an emergency surcharge. If a jurisdiction later obliges AI Business OS to collect tax on its own retail sale, that is a **separate** obligation from Telnyx's tax on the platform's purchase, and is a counsel question (§13.2).

### 7.10 Compliance and registration fees

Charged as their own itemised meters at the published retail prices in §7.4, never folded into the per-message rate. Rationale: they are one-time or monthly, they are visible to the customer as distinct events with distinct causes, and parent contract §10.6 requires an itemised upfront estimate with one line per item. Telnyx passes them through at cost (T8); AI Business OS marks them up like every other unit, because the platform performs the registration work.

### 7.11 Monthly number rental

One meter, `telecom.number.monthly.us`, at $2.50 per number per month, covering both the $1.00 rental and the $0.10 messaging capability (T6). Charged on the Business's own renewal anniversary. **Whether Telnyx bills the platform on a calendar-month or anniversary basis is unresolved** — question Q12 — and the two must be reconciled before the renewal sweep is built, or the platform will carry an unfunded partial month.

### 7.12 Destination allowlist — a rate-card rule, not a preference

> **A managed message may only be sent to a destination for which an active retail rate exists for that destination's country.** At launch that is the United States alone. Any other destination is refused before any provider call, with a clear message.

This is what makes the flat published price safe. Without it, one message to a $0.20-per-part destination (T4) loses more margin than 13 US messages earn. It is also the correct default under cost-control invariant C-1 (E2 §20).

Implementation note: US and Canada share calling code +1, so the destination country **cannot** be derived from the calling code. It must be resolved from the number's region via `libphonenumber`, which the repository already depends on (E12). E12 also corrects the parent contract's §28.4 rationale, which describes the normalizer as carrying "+1 normalization" — it does not; it is region-agnostic by design.

### 7.13 Insufficient provider-cost evidence

> **No retail rate may be activated for a meter whose worst-case provider cost is not evidenced by a primary source dated within 90 days.**

Canada is the live instance: no Canadian cost is published (§6.7), so no Canadian meter may be created, activated, reserved against, or shown in an estimate. The customer-facing consequence is §5.4's US-only launch, not an invented Canadian price.

### 7.14 Effective dates and immutable versioning

| Rule | Behaviour | Basis |
|---|---|---|
| Rate immutability | `business_usage_rates` rows have no `updated_at` and are never updated. A change means a **new version** | E4 |
| Versioning | `setActiveRate()` allocates `latest_version + 1` per meter and writes an activation audit row | E6 |
| Effective date | The activation row's `activated_at`. There is no scheduled future activation, so a rate change takes effect at the moment of activation | E6 |
| Historical attribution | Every reservation and ledger row snapshots `rate_id`, `rate_version`, `retail_rate_micro`, `provider_cost_micro` and `rounding_rule` | E7 |
| Rewriting history | Never. A superseded rate row is retained forever and remains the authority for every operation reserved under it | E4, E7 |

### 7.15 Reservation-time attribution and settlement

**The reservation-time rate governs, always.** `commit()` re-multiplies the reservation's own snapshotted `retail_rate_micro` (E9). A mid-flight rate change cannot retroactively reprice an operation already reserved. This is already implemented; the rate card depends on it and must not weaken it.

**When actual provider cost differs from the assumption:**

| Situation | Behaviour |
|---|---|
| Actual part count differs from the estimate | `commit()` is called with the provider's `parts` value. Under-estimate charges an overage; over-estimate releases the difference. The **unit price never changes** | E9, T26 |
| Actual provider cost per part differs (different carrier than assumed) | **The customer's charge does not change.** The variance lands entirely on platform margin and is visible only in admin reporting | E9, E2 §20 C-9 |
| Actual provider cost exceeds the retail price for that unit | The message is still delivered and charged at the published retail price. The loss is absorbed and **must raise a margin alert** (§7.18) |

### 7.16 Refunds and reversals

| Case | Treatment | Basis |
|---|---|---|
| Unused wallet balance | Refundable under the existing RFC-005 rules, tracked by `refundable_paid_available_micro` | E7, E11 |
| Committed telecom usage charge | **Non-refundable.** The provider cost is already sunk and Telnyx does not refund delivered messages | T8, T15 |
| Registration and campaign review fees | **Non-refundable, including on rejection.** The $15 attaches to the review, not the outcome (T8). This must be disclosed **before** the customer pays it | T8 |
| Platform error | Reversed via `UsageChargeReversal` or `CorrectionReversal`, audited, never by editing a ledger row | E11 |
| Provider refund or chargeback | Existing `applyProviderRefund()`, `applyDisputeWithdrawal()`, `reinstateDisputedFunds()` paths, unchanged | E2 §12.2 |

### 7.17 Provider price changes

A published Telnyx price change triggers: re-derive the §7.3 floor from the new worst case; if the current published price still clears it, **no action** and no customer-facing change; if it does not, the owner approves a new rate version via `setActiveRate()`, which takes effect for reservations made after activation only. In-flight reservations are untouched (§7.15). Customers are notified before any increase takes effect.

### 7.18 Error, timeout and unknown-outcome behaviour

| Situation | Behaviour |
|---|---|
| Provider call fails cleanly before acceptance | `release()` the reservation in full. No charge | E2 §12.3 |
| Provider call times out with an unknown outcome | The reservation stays **Pending**. Never commit and never release on a guess. Resolution comes from the provider's own `message.finalized` or from a reconciliation sweep | E1 §9, E2 §21.2 |
| Reservation TTL expires before resolution | `expireStaleReservations()` releases it. **This is a real hazard for long-running compliance work — see §11.4** | E8 |
| Finalized cost ≥ 65% of the published retail price | Deliver and charge normally, but raise a **margin alert** for owner review | §7.19 |
| Finalized cost ≥ the published retail price | Deliver and charge normally, raise a **critical margin alert**, and treat repetition as a §7.17 revision trigger | §7.19 |

Under no circumstance is provider success inferred from a local row (E2 §21.2).

### 7.19 Margin floor

> **No rate may be activated where `published_retail < worst_case_provider_cost × 1.60`.** This is checked by a human at approval time and asserted by an automated guard before activation.

Runtime monitoring: a finalized cost at or above 65% of retail is an alert; at or above 100% it is critical. These are alerts, not blocks — blocking a delivery because a carrier is expensive would fail the customer for a platform-side problem.

### 7.20 Promotional credits and BYO

* **Promotional credit** is granted only by explicit, audited owner action, is bounded and expiring, uses the existing `PromotionalCredit` ledger type, and is consumed before paid balance. It is never granted automatically by plan, trial, Business creation or Agency Business count (E2 §12.5, §20 C-4/C-6).
* **BYO transport takes no retail transport rate at all.** No telecom meter is reserved, no transport charge is settled, and none of §7 applies. Paid non-transport operations on a BYO Business are charged normally (E2 §11.5).

### 7.21 The five charge classes, separated (Correction Round 1)

**Corrected in Round 1 (C7).** These five were described across §6 and §7 but never set out as one normative list. Each is a distinct meter family with its own unit, its own cadence and its own markup treatment. **No two may be merged into one ledger line, and none may be presented to the customer as another.**

| # | Class | What it is | Unit | Cadence | Markup? | Meters |
|---|---|---|---|---|---|---|
| **1** | **Pass-through compliance charges** | Registry and carrier fees Telnyx passes on at cost — "Telnyx does not currently charge a markup on 10DLC fees" (T8) | One brand registration; one campaign review **per submission**; one campaign month | One-time, per-submission, and monthly | **Yes** — the platform performs the registration labour, carries resubmission risk and monitors dormancy (§8.13) | `…registration.brand.us`, `…registration.campaign_review.us`, `…registration.campaign_monthly.us.*` |
| **2** | **Recurring provider costs** | The standing cost of holding a provisioned number | One number-month, covering rental plus the messaging capability charge | Monthly, on the Business's renewal anniversary | **Yes** | `telecom.number.monthly.us` |
| **3** | **Telecom usage rates** | Per-message transport | The **message part** (§7.7), inbound or outbound | Per operation | **Yes** | `telecom.sms.us`, `telecom.mms.us` |
| **4** | **Taxes and surcharges** | Two different things that must not be conflated. **(a) Carrier surcharges** are per-part, destination-dependent, and **absorbed into class 3's published price** (§7.8) — never a separate customer line. **(b) Taxes, USF and TRS** are levied on the platform's own purchase, computed daily against the account, and "there is no way to provide an accurate estimate in advance" (T28) | (a) per part; (b) not attributable to any message | (a) per operation; (b) daily, account-wide | **Neither is marked up.** (a) is inside class 3's price; (b) is absorbed by margin (§7.9) | None — (b) has no customer-facing meter at launch |
| **5** | **Platform markup** | The margin between classes 1–3's published retail and their provider cost | Not a chargeable unit | n/a | It **is** the markup | Never its own meter or ledger line |

**Rules that follow from the separation:**

* Class 5 is **never** a line item. It is the difference between two numbers the customer and the admin see respectively, and it must not appear as a "service fee" or "platform fee" anywhere.
* Class 4(b) is **never** a customer line at launch, because the provider states it cannot be estimated in advance. Inventing an estimated tax line would be a fabricated charge.
* Class 4(a) is **never** a separate line either, because it would expose the recipient's carrier to the sender and vary unpredictably at estimate time.
* Classes 1, 2 and 3 each get their **own** meter, their own `unit_label` and their own line in the parent contract §10.6 itemised estimate. A customer must be able to see what they paid for registration, what they pay monthly, and what they pay per message, as three separate figures.
* **Provider cost is administrative only** for every class, per cost-control invariant C-9 (E2 §20). None of the provider-cost figures in §6 is ever shown to a customer.

---

## 8. Margin and failure analysis

All scenarios use §7.4's published retail and §6.2's provider ranges. Where a provider figure is a range, both bounds are carried; nothing uncertain is presented as exact. Monthly figures exclude taxes, which are real, unestimable in advance (T28), and absorbed by margin (§7.9).

### 8.1 Per-unit margin

| Unit | Retail | Provider cost range | Gross margin range | Margin as % of retail | Effective markup on worst case |
|---|---|---|---|---|---|
| SMS message part, outbound | $0.0150 | $0.0075 – $0.0090 | $0.0060 – $0.0075 | 40.0% – 50.0% | 66.7% |
| SMS message part, inbound | $0.0150 | $0.0040 – $0.0075 | $0.0075 – $0.0110 | 50.0% – 73.3% | 100.0% |
| MMS message part, outbound | $0.0450 | $0.0220 – $0.0250 | $0.0200 – $0.0230 | 44.4% – 51.1% | 80.0% |
| MMS message part, inbound | $0.0450 | $0.0050 – $0.0150 | $0.0300 – $0.0400 | 66.7% – 88.9% | 200.0% |
| Number month | $2.50 | $1.10 | $1.40 | 56.0% | 127.3% |
| Brand registration | $9.00 | $4.00 – $4.50 | $4.50 – $5.00 | 50.0% – 55.6% | 100.0% |
| Campaign review | $25.00 | $15.00 | $10.00 | 40.0% | 66.7% |
| Campaign month, low volume | $3.50 | $1.50 | $2.00 | 57.1% | 133.3% |
| Campaign month, standard | $16.00 | $10.00 | $6.00 | 37.5% | 60.0% |
| Campaign month, sole proprietor | $4.50 | $2.00 | $2.50 | 55.6% | 125.0% |

Every line clears the §7.19 floor of 1.60×. The tightest is standard-volume campaign monthly at exactly 60.0% markup.

### 8.2 Scenario — low-volume Business (sole proprietor)

200 outbound SMS parts, 50 inbound, no MMS.

| Line | Quantity | Retail | Provider cost (range) |
|---|---|---|---|
| SMS outbound | 200 | $3.00 | $1.50 – $1.80 |
| SMS inbound | 50 | $0.75 | $0.20 – $0.38 |
| Number month | 1 | $2.50 | $1.10 |
| Campaign month (SP) | 1 | $4.50 | $2.00 |
| **Recurring total** | | **$10.75** | **$4.80 – $5.28** |
| **Gross margin** | | | **$5.47 – $5.95 (50.9% – 55.3%)** |

Onboarding, charged once: brand $9.00, campaign review $25.00, three prepaid campaign months $13.50, first number month $2.50 — **$50.00 retail** against $26.10 – $26.60 provider cost (brand $4.00 or $4.50 per the Q6 conflict, review $15.00, three campaign months $6.00, number $1.10), a margin of $23.40 – $23.90 (46.8% – 47.8%).

**The upfront reservation is therefore about $50, ten times the $5 top-up minimum.** Parent contract §10.6 is not merely correct; the gap is an order of magnitude, and the onboarding UI must lead with the real number.

### 8.3 Scenario — normal local Business

2,000 outbound SMS parts, 400 inbound, 50 outbound MMS, Low Volume Mixed campaign.

| Line | Quantity | Retail | Provider cost (range) |
|---|---|---|---|
| SMS outbound | 2,000 | $30.00 | $15.00 – $18.00 |
| SMS inbound | 400 | $6.00 | $1.60 – $3.00 |
| MMS outbound | 50 | $2.25 | $1.10 – $1.25 |
| Number month | 1 | $2.50 | $1.10 |
| Campaign month | 1 | $3.50 | $1.50 |
| **Total** | | **$44.25** | **$20.30 – $24.85** |
| **Gross margin** | | | **$19.40 – $23.95 (43.8% – 54.1%)** |

### 8.4 Scenario — high-volume Business (SMS-heavy)

50,000 outbound SMS parts, 5,000 inbound, 500 outbound MMS, Standard Volume campaign.

| Line | Quantity | Retail | Provider cost (range) |
|---|---|---|---|
| SMS outbound | 50,000 | $750.00 | $375.00 – $450.00 |
| SMS inbound | 5,000 | $75.00 | $20.00 – $37.50 |
| MMS outbound | 500 | $22.50 | $11.00 – $12.50 |
| Number month | 1 | $2.50 | $1.10 |
| Campaign month | 1 | $16.00 | $10.00 |
| **Total** | | **$866.00** | **$417.10 – $511.10** |
| **Gross margin** | | | **$354.90 – $448.90 (41.0% – 51.8%)** |

**Throughput reality check.** 50,000 parts a month is roughly 1,667 a day. T-Mobile's brand-level daily cap starts at 2,000 for a low-tier brand (T11), so this Business alone could saturate an unvetted brand's T-Mobile allowance. Brand vetting is an operational prerequisite for any high-volume customer, not an optimisation.

### 8.5 Scenario — MMS-heavy Business

500 outbound SMS parts, 2,000 outbound MMS, Low Volume Mixed.

| Line | Quantity | Retail | Provider cost (range) |
|---|---|---|---|
| SMS outbound | 500 | $7.50 | $3.75 – $4.50 |
| MMS outbound | 2,000 | $90.00 | $44.00 – $50.00 |
| Number month | 1 | $2.50 | $1.10 |
| Campaign month | 1 | $3.50 | $1.50 |
| **Total** | | **$103.50** | **$50.35 – $57.10** |
| **Gross margin** | | | **$46.40 – $53.15 (44.8% – 51.4%)** |

### 8.6 Scenario — Unicode-heavy

1,000 outbound messages, each 160 characters.

| Encoding | Parts billed | Retail | Provider cost (range) | Margin % |
|---|---|---|---|---|
| GSM-7 | 1,000 | $15.00 | $7.50 – $9.00 | 40.0% – 50.0% |
| UCS-2 (one emoji anywhere) | 3,000 | $45.00 | $22.50 – $27.00 | 40.0% – 50.0% |

**Margin percentage is unchanged; the customer's bill triples.** Per-part pricing is encoding-neutral by construction, so this is purely a disclosure problem. It is also the strongest argument for enabling smart encoding by default (T25) and for a live part counter in the composer.

### 8.7 Scenario — Canadian traffic

**Not computable on the evidence gathered.** Of the five figures a Canadian scenario needs, one was found as a lower bound only — "Local numbers for Canada start at $1 per month" (T33) — and four were **not found in the material reviewed**: the Canada-destination SMS base rate, the MMS base rate, the outbound carrier surcharges, and whether the $0.10 capability charge applies (§4.3a, §6.7).

**Not found is not confirmed absent.** These figures very probably exist behind `GET /v2/public/pricing` (T5) or a sales enquiry, neither of which this lane was authorized to use. Presenting a Canadian margin from a "start at" floor plus four gaps would be inventing a provider price, which §7.13 and the task both forbid. This is the concrete reason Canada is deferred (§5.4), and the concrete thing Q2, Q3 and owner decision D10 unblock.

### 8.8 Scenario — carrier-surcharge spike

| Case | Retail | Provider cost | Margin |
|---|---|---|---|
| US worst case, U.S. Cellular outbound SMS | $0.0150 | $0.0090 | +$0.0060 (40.0%) |
| Hypothetical non-US destination at the highest published surcharge | $0.0150 | $0.2040 | **−$0.1890 per part** |

A single out-of-scope part destroys the margin on more than 31 in-scope parts. §7.12's destination allowlist is what prevents this, and it is why the allowlist is a rate-card rule rather than a product setting. **Within the US, no published carrier fee can produce a negative margin at $0.0150** — the worst US surcharge is $0.005 against a $0.011 gross margin over base.

### 8.9 Scenario — provider price increase after reservation

A Business reserves 1,000 outbound parts at $0.0150 ($15.00 retail) against an assumed $0.0080 cost. Telnyx raises the base rate 25% to $0.0050 before settlement, taking cost to $0.0090.

| | Before | After |
|---|---|---|
| Customer charge | $15.00 | **$15.00 — unchanged** |
| Provider cost | $8.00 | $9.00 |
| Gross margin | $7.00 (46.7%) | $6.00 (40.0%) |

The customer is insulated (§7.15); the platform absorbs $1.00 and still clears the floor. If a future increase pushed cost above $0.009375 the floor would break and §7.17 would require a new rate version.

### 8.10 Scenario — compliance rejection and resubmission

| Outcome | Retail charged | Provider cost | Margin |
|---|---|---|---|
| Approved first time | $25.00 | $15.00 | +$10.00 |
| One rejection, resubmission charged to customer | $50.00 | $30.00 | +$20.00 |
| One rejection, resubmission absorbed by platform | $25.00 | $30.00 | **−$5.00** |
| Two rejections, both absorbed | $25.00 | $45.00 | **−$20.00** |

**Absorbing resubmissions is the single most likely route to negative onboarding margin**, and rejection is not rare — T14 lists many specific rejection causes, most of them customer-supplied content problems. Two mitigations, both recommended: charge each submission at the published rate with the cost disclosed before the first submission; and validate everything §7 and T14 make checkable **before** submitting, so the platform never pays $15 to be told the privacy policy was a pop-up. The charge-or-absorb choice is owner decision D5.

### 8.11 Scenario — refund

A Business has funded $84.00 in total, was charged $34.00 in registration fees at onboarding and $10.75 of recurring usage since, and now asks for a refund.

| Component | Amount | Refundable? |
|---|---|---|
| Unused balance ($84.00 − $34.00 − $10.75) | $39.25 | Yes, under existing RFC-005 rules |
| Committed usage charges | $10.75 | No — provider cost is sunk |
| Registration fees (brand $9.00 + campaign review $25.00) | $34.00 | No — non-refundable, disclosed upfront |

### 8.12 Scenario — negative-margin edge cases

| Case | Occurs when | Mitigation |
|---|---|---|
| Out-of-scope destination | A message reaches a non-US destination | §7.12 allowlist blocks it before any provider call |
| Absorbed resubmission | Platform pays a review fee it does not recover | §8.10; owner decision D5 |
| Dormant campaign fine | A campaign sits inactive 15 days and T-Mobile fines $250/month | Dormancy monitoring and the `DORMANT` webhook (T17); an alert per identity |
| Provider price increase past the floor | Cost rises above retail ÷ 1.60 | §7.17 rate revision; §7.19 alerting |
| Tax drift | Unestimable taxes consume margin | §7.9's 10%-of-margin revision trigger |
| **Platform balance abolishment** | The shared Telnyx balance stays negative for one month, after which "the numbers in your account will be deleted from the account" (T30) — see §11.8 for the exact wording and timeline | Platform balance monitoring is not optional (E1 §10). Severe, but recoverable during a two-week hold |

### 8.13 Pass through exactly, or mark up?

| Cost | Treatment | Why |
|---|---|---|
| SMS/MMS base and carrier surcharge | **Marked up**, absorbed into one published price | The platform carries carrier-mix risk, throughput management and delivery handling |
| Number rental and messaging capability | **Marked up** | The platform carries lifecycle, renewal, grace and porting work (E2 §13) |
| Brand, campaign review, campaign monthly | **Marked up** | Telnyx passes them at cost (T8); the platform performs the registration labour, the resubmission risk and the dormancy monitoring |
| Taxes, USF, TRS | **Absorbed, not passed through** at launch | Unattributable to a message and unestimable in advance (T28) |
| T-Mobile dormancy fines | **Absorbed** | Caused by platform monitoring failure, not by the customer |
| Out-of-scope destination cost | **Never incurred** | §7.12 |

---

## 9. Compliance onboarding state machine

Mapped onto parent contract §7.7's activation states. Every row obeys four invariants: **no provider success is ever inferred from a local row**; **no funds are reserved before the customer has seen an itemised estimate**; **no provider cost is incurred that is not already covered by an open reservation or an existing commit** (§11.4); and **sending is blocked until the carrier can actually deliver**.

The "Funds reserved" column follows §11.4's corrected sequence: reservations are **short and repeated**, taken immediately before each irreversible provider cost, never held across the multi-day carrier review.

| State | §7.7 | Customer sees | Platform may | Sending | Funds reserved | Fees charged | Notification | Retry / resubmission | Audit evidence |
|---|---|---|---|---|---|---|---|---|---|
| `not_started` | `available` | "Set up your business phone" with the full itemised cost preview | Show the estimate. Nothing else | Blocked | No | No | None | n/a | Entitlement check only |
| `funding_required` | `available` | The complete itemised upfront total, and the shortfall if any. Auto-recharge stays off unless the customer turns it on (E15) | Compare the total against available balance. **No provider contact** | Blocked | No — this is a **balance sufficiency gate**, not a reservation | No | Prompt to fund at least the shortfall, $5 manual minimum | Free retry | Estimate snapshot, shortfall, funding attempt ids |
| `business_information_required` | `available` | A form for legal name, entity type, EIN or registry ID, address, website, contact (T12) | Validate and store. No provider call | Blocked | No | No | Reminder after 7 days idle | Free re-edit | Field-level change log |
| `consent_evidence_required` | `available` | Opt-in method, CTA text, privacy policy URL, terms URL, sample messages, opt-in/opt-out/HELP copy (T13, T14) | Validate against every checkable T14 rule, including that policy links resolve and are not pop-ups | Blocked | No | No | Reminder after 7 days idle | Free re-edit | Submitted evidence retained immutably |
| `submitted` | `activating` | "Submitted for review", with an itemised receipt for what was just charged | Two short reserve → call → commit cycles: brand, then campaign. **Reserve immediately before each, always** (E2 §10.3, §11.4) | Blocked | **Yes, briefly** — one short reservation per cycle, each committed within seconds | Brand registration, campaign review and three prepaid campaign months, **committed on provider acceptance of the submission** because Telnyx charges on submission regardless of outcome (T8) | Confirmation with itemised receipt and an explicit non-refundable notice | n/a | Reservation ids, provider request/response ids, commit ledger ids |
| `under_review` | `activating` | "Under carrier review", with an honest range and no invented completion date | Poll or await provider callbacks only | Blocked | **None open, deliberately** — registration is already committed, and nothing is owed until the number is bought, so no reservation can expire here (§11.4) | Already charged | Notify on any state change | n/a | Every provider status transition, timestamped |
| `action_required` | `activating` | The exact rejection reason and precisely what to change | Accept corrections and resubmit | Blocked | No new reservation until resubmission | **A further review fee applies per submission** (T8) — disclosed before the customer confirms | Immediate, with the reason | Explicit, customer-confirmed, never automatic | Rejection reason and correction diff |
| `approved` | `activating` | "Approved — assigning your number" | **Re-check available balance first** (§11.4), then one short reserve → buy → commit cycle | **Still blocked** | Yes, briefly, for the number only | Number's first period, committed on successful purchase | Notify. If the balance is now short, ask for a top-up well inside the 15-day dormancy window (T17) | Provider-driven | Brand and campaign identifiers, balance re-check result |
| `number_assignment_pending` | `activating` | "Finalising with carriers — usually 24 to 72 hours" (T18) | Wait for propagation | **Still blocked** — this is where premature sends silently fail (§3.2) | Already reserved | Already charged | Notify on completion | Bounded automatic retry, then `action_required` | Assignment id, propagation start |
| `ready` | `active` | "Your business phone is live", with the number and current balance | Send, receive, meter, settle | **Allowed** | Per operation | Per operation at published rates | Low-balance alerts | n/a | Every message with `parts`, `encoding` and finalized cost |
| `suspended` | `suspended` | The specific reason: funds, compliance, dormancy, or admin | Retain the number; keep inbound handling and STOP/HELP alive (E2 §13.3) | **New paid outbound blocked**; legally required inbound preserved | No new reservations | Recurring number and campaign fees continue — **disclosed** | Immediate, with the remedy | Automatic on remedy for funds; manual for compliance | Reason, actor, timestamp, transition source |
| `rejected` | `available` | Final rejection with the reason and the options | Release the number if one was taken; retain the evidence | Blocked | Released | Registration fees **not refunded** (§7.16) | Immediate | A new attempt is a new submission with a new fee | Full rejection record retained |
| `expired_renewal_required` | `suspended` | "Renewal needed to keep your number", with the grace-period end date | Retain the number through the grace period; never silently release (E2 §13.2) | New paid outbound blocked | No | Renewal charged on success | Advance warning **before** the renewal date, then escalating | Automatic on funding | Grace start and end, notifications sent, release decision and actor |

**Additional required rules:**

* **Dormancy.** A `ready` identity with no activity approaching 15 days must raise its own alert distinct from any account-level alarm, because a dormant campaign auto-suspends and risks a $250/month fine (T17). Reactivation follows Telnyx's documented double-assignment.
* **STOP and HELP are never blocked.** In every state where a number exists, STOP is honoured within 24 hours (T15) and HELP is answered (T14). Neither depends on wallet balance.
* **No state is entered on local evidence alone.** `approved`, `ready` and `suspended` are entered only on a verified provider signal (E2 §21.2).

---

## 10. Required Telnyx questions

Only questions official material could not resolve. None are invented; each traces to a specific gap identified above.

### 10.1 Launch blockers

These block the Canadian half of the proposed §28.4 scope. **None blocks a US-only launch.**

| # | Question | Blocks |
|---|---|---|
| **Q1** | Is A2P registration required for a Telnyx Canadian local long code to reach Canadian subscribers, and is it required for a US 10DLC-registered number to reach Canadian subscribers? Your International SMS Compliance Guide lists Canada's only pre-registration requirement as short-code approval, but your 10DLC brand form accommodates Canadian corporate registry identifiers, and several downstream platforms state that Canadian long codes bought on or after 26 March 2025 require registration | Canada launch; §3.4; §4.3; §4.4 |
| **Q2** | What are the current Telnyx base rates for SMS and MMS, outbound and inbound, to Canadian destinations, and what Canadian carrier surcharges apply? Your public messaging pricing page lists Canadian carriers only in the inbound table with no fee, publishes no Canada-destination base rate, and offers no destination selector. Is `GET /v2/public/pricing` the correct authoritative source for these, and does it require authentication? | Canada rate card; §4.3a; §6.7; §8.7 |
| **Q3** | Your Canada numbers page says "Local numbers for Canada start at $1 per month" (T33). What is the actual monthly rental for a Canadian local number in a given rate centre, and does the $0.10 monthly SMS/MMS capability charge apply to Canadian numbers? | Canada rate card; §4.3a; §6.7 |
| **Q4** | Does toll-free verification apply to, and is it honoured by, Canadian carriers — or is it a US-carrier requirement only? | Canada fallback path; §4.2 |
| **Q5** | For a Canadian brand, should we submit the Provincial or Federal Corporation/Registry ID as your brand-creation article instructs, or the first nine digits of the CRA Business Number as The Campaign Registry's guidance states? These directly conflict | Canada onboarding correctness; §4.3 |

### 10.2 Implementation clarifications

| # | Question | Informs |
|---|---|---|
| **Q6** | Is the 10DLC brand registration fee $4.50 as your fees article states, or $4.00 as your sole-proprietor developer documentation states? Does it differ by entity type? | §6.4; §7.4 |
| **Q7** | What record-retention obligation attaches to 10DLC consent evidence as a **requirement**, as distinct from the "recommended 4+ years" in your compliance guide? | §3.1; §13.2 |
| **Q8** | Can a single MMS ever be billed as more than one message part, and if so what determines the count? Your encoding documentation says MMS is not subject to SMS segment limits, but pricing is quoted per message part | §7.7; MMS estimation |
| **Q9** | Do the account-wide 50 MPS SMS and 15 MPS MMS ceilings apply per account, per messaging profile, or per API key? We operate one account with one messaging profile per end customer | §3.1; §8.4; capacity planning |
| **Q10** | Two cardinality figures need confirming as current. (a) Is "a maximum of five Campaigns per Brand" still the limit? (b) Your FAQ gives the T-Mobile number-per-campaign limit as 49 "as of September 2023" — is 49 still correct in 2026? | §3.5; capacity planning |
| **Q11** | Is the `cost` value on `message.finalized` final and immutable, or can it be adjusted by later reconciliation? Can the provider price for an in-flight message change between send and finalization? | §7.15; §7.18; ledger settlement |

### 10.3 Operational questions

| # | Question | Informs |
|---|---|---|
| **Q12** | Are phone-number monthly recurring charges billed on a calendar-month boundary or on each number's own purchase anniversary, and are partial months prorated? | §7.11; renewal sweep; the §7.6 integer invariant |
| **Q13** | Is there a documented way to avoid a duplicate $15 campaign review fee when resubmitting after a rejection — for example a pre-submission review by your team? | §8.10; owner decision D5 |
| **Q14** | Are taxes ever attributable to an individual message or number, or only to the account's daily aggregate? | §7.9 |
| **Q15** | What notification is sent, and to which address, before the one-month negative-balance abolishment process deletes an account's numbers? | §8.12; balance monitoring |
| **Q16** | **Partly answered by your own documentation** — a messaging profile carries `daily_spend_limit`, disabled by default, resetting at midnight UTC (T31). Two gaps remain: is there any per-profile *throughput* control, as distinct from a spend cap? And does the daily spend limit count retail spend or provider cost? | §3.1; §8.4; per-Business limits |
| **Q17** | Does a 10DLC brand or campaign registered through Telnyx impose any constraint on which messaging profile its assigned numbers may belong to, or are profiles fully orthogonal to brands and campaigns? Your messaging-profile documentation states no relationship | §3.5; per-Business isolation |
| **Q18** | When a Business needs several use cases, is a single Mixed-use-case campaign or several separate campaigns the preferred structure for an ISV, and does a Mixed campaign carry any throughput or approval penalty relative to single-use-case campaigns? | §3.5; onboarding design; §8 cost modelling |

### 10.4 Future Managed Accounts questions

Carried forward unchanged from the architecture decision's §18 items 1–5. They gate only the future migration and are restated here so no list is lost, not reopened.

### 10.5 Support-ticket draft

> **Subject:** ISV platform — Canada A2P registration scope, Canadian pricing, and 10DLC clarifications
>
> Hello,
>
> We operate a SaaS platform that will provision one messaging profile and one dedicated long-code number per end-customer business from a single Telnyx account, registering a separate 10DLC brand and campaign per end customer, per your ISVs & 10DLC guidance. We are finalising our launch country scope and our retail pricing, and your published documentation leaves five points unresolved. We have not yet created an account resource, purchased a number, or submitted a registration.
>
> 1. **Canada A2P registration.** Your International SMS Compliance Guide lists Canada's only pre-registration requirement as short-code approval and marks long code as supported. Your 10DLC quickstart scopes 10DLC to US long-code numbers. However, your brand-creation article gives specific guidance for Canadian entities, and several downstream platforms state that Canadian long codes purchased on or after 26 March 2025 require A2P registration or persona verification before reaching Canadian subscribers. Which is correct today, for (a) a Canadian long code sending to Canadian subscribers, (b) a Canadian long code sending to US subscribers, and (c) a US 10DLC-registered number sending to Canadian subscribers?
> 2. **Canadian pricing.** Your public messaging pricing page publishes no Canada-destination base rate, offers no destination selector, and lists Canadian carriers only in the inbound table with no carrier fee. What are the current outbound and inbound SMS and MMS base rates for Canadian destinations, and what Canadian carrier surcharges apply? Is `GET /v2/public/pricing` the authoritative source for these, and does it require authentication?
> 3. **Canadian numbers.** Your Canada numbers page says local numbers "start at $1 per month". What is the actual monthly rental, and does the $0.10 monthly SMS/MMS capability charge apply to Canadian numbers?
> 4. **Canadian brand identifiers.** Your brand-creation article tells Canadian companies to supply a Provincial or Federal Corporation/Registry ID and to avoid the CRA Business Number. The Campaign Registry's guidance is to supply the first nine digits of the CRA Business Number. Which should we submit?
> 5. **Cardinality and structure.** (a) Is "a maximum of five Campaigns per Brand" still current? (b) Your FAQ gives the T-Mobile limit as 49 numbers per campaign "as of September 2023" — is that still correct? (c) For an ISV whose end customer needs several use cases, do you recommend one Mixed-use-case campaign or several separate campaigns, and does a Mixed campaign carry any throughput or approval penalty? (d) Do brands or campaigns constrain which messaging profile their numbers may belong to, or are profiles fully orthogonal?
> 6. **Fee, limit and billing clarifications.** (a) Is the brand registration fee $4.50 per your fees article or $4.00 per your sole-proprietor developer documentation? (b) Do the account-wide 50 MPS SMS and 15 MPS MMS ceilings apply per account, per messaging profile, or per API key, and is there any per-profile throughput control to complement `daily_spend_limit`? (c) Can a single MMS ever be billed as more than one message part, and if so what determines the count? (d) Is the `cost` value on `message.finalized` final and immutable, or can it be adjusted by later reconciliation? (e) Are number monthly charges billed on a calendar boundary or a purchase anniversary, and are partial months prorated?
>
> Thank you.

---

## 11. Repository reconciliation — implementation consequences

**This section describes what a future implementation contract must change. It changes nothing itself: no repository document other than this new file is edited by this lane.**

### 11.1 Documents and code inspected

| Subject | Location | Status |
|---|---|---|
| Merged messaging architecture decision | `docs/automation/TELNYX-MANAGED-MESSAGING-ARCHITECTURE-DECISION.md` | Consistent with this document; §11 of that document is extended, not contradicted |
| Parent customer-experience contract | `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` | Two rows need amendment (§11.7) |
| RFC-005 rate and ledger behaviour | `app/Library/Usage/UsageWalletManager.php`, `app/Models/BusinessUsageRate.php` | Sufficient for this rate card with the caveats below |
| Currency and micro-unit implementation | `docs/automation/RFC-005-M3-CONTRACT.md:290`, `app/Library/Usage/UsageBillingCheckoutManager.php:2663` | Sufficient; micros are finer than provider precision |
| Rate activation and reservation-time attribution | `UsageWalletManager::setActiveRate()`, `::reserve()`, `::commit()` | Already correct; must not be weakened |
| Wallet and payer behaviour | Parent contract §12; `UsageWalletManager` | Unchanged by this document |
| Country and phone normalization | `app/Library/AgencyProspecting/AgencyProspectPhoneNormalizer.php` | Reusable; one contract statement about it is inaccurate (§11.7) |
| Current telecom enums and meters | `app/Enums/Usage/**`, `app/Console/Commands/ActivateConversationsUsageRate.php` | **No telecom meter exists.** All must be created |
| Proposed Slice 4 boundary | Parent contract §22.1 | Adequate, with one gap (§11.6) |

### 11.2 Meter identity and currency

`usage_meters.meter_key` carries a single-column unique index in addition to the composite `(meter_key, currency_id)` (E10). **One meter key therefore binds to exactly one currency**, and `reserve()` throws if the wallet's currency differs (E7).

Consequences the implementation contract must state:

* Telecom meters are **platform-wide**, not per-Business: `business_id` must be null (E10 confirms this is supported). A per-Business meter would mean a separate rate row per Business, which is neither intended nor maintainable.
* Meter keys must carry the currency when more than one is ever supported — `telecom.sms.us.usd`, not `telecom.sms.us`. Adding a second currency later without this is a rename, and rate rows are immutable.
* The §7.4 key names are illustrative. The implementation contract must fix them once, before the first activation, because `business_usage_rates.meter_key` is immutable (E4).

### 11.3 Rounding

`RoundingRule` has one case and `setActiveRate()` hardcodes it (E5, E6). Per §7.6 this is acceptable **only** under the integer-quantity invariant. The implementation contract must:

1. State the invariant explicitly as a contract rule.
2. Add an activation-time guard rejecting any telecom rate whose `retail_rate_micro` is not a positive integer.
3. Record that introducing any fractional telecom quantity — prorated number rental is the likely first — **requires adding a `Ceiling` case to `RoundingRule` first**, because half-up on a `.5` micro rounds toward the customer.

### 11.4 The reservation TTL conflict — the most consequential finding

`RESERVATION_TTL_MINUTES` is **30 minutes** (E8), and `expireStaleReservations()` releases anything still pending past it. But 10DLC campaign review is a manual TCR process and carrier propagation alone takes 24–72 hours (T11, T18); toll-free verification takes 1–2 weeks (T21).

**A single reservation covering the whole upfront total cannot survive compliance approval.** It will be auto-released mid-flight, and the platform will have paid Telnyx with the customer's funds already returned. Parent contract §10.6's "reserve the complete amount, provision, then settle" sequence is written as though provisioning were synchronous. It is not.

**Round 1 correction (C6).** `expires_at` is written once at `reserve()` and is **never updated anywhere in the codebase** — there is no extension mechanism, so the 30-minute ceiling is hard, not a tunable. Verified across the repository.

**Required resolution — a funding gate, then reserve-commit cycles at each provider commitment boundary.** The sequence below satisfies every element of the locked policy simultaneously.

**Stage 0 — estimate and fund, before any provider contact.**

1. Show the **complete** itemised upfront estimate: brand registration, campaign review, the three prepaid campaign months, the number's first period, and the initial usable balance. One line per item at its retail rate, no provider cost shown (E2 §10.6, §20 C-9). **No tax line appears**, because Telnyx states taxes cannot be estimated in advance (T28); §7.9 records that the platform absorbs them.
2. **Required available funding = the greater of (a) $5, or (b) that complete displayed upfront total** — the locked rule, unchanged (E2 §10.6).
3. Compare against **available** balance, meaning balance minus existing reservations. Sufficient means no top-up is requested. Short means the customer funds at least the shortfall, subject to the $5 manual minimum. **Auto-recharge stays off by default** — verified in both the migration default and wallet initialisation (E15).
4. **Nothing has touched the provider yet.** Abandoning here leaves no reservation, no charge and no provider resource.

**Stages 1 to 3 — one short reserve → provider call → commit cycle per irreversible cost.**

| Stage | Provider commitment boundary | Reserve | Provider call | Commit | Within TTL? |
|---|---|---|---|---|---|
| **1** | Brand registration fee is incurred on submission | Immediately before | Create brand | On provider acceptance | Yes — seconds |
| **2** | Campaign review fee plus three prepaid months are incurred **on submission, regardless of outcome** (T8) | Immediately before | Submit campaign | On provider acceptance of the submission, not of the review | Yes — seconds |
| **—** | **Multi-day carrier review runs here with no reservation open** | — | — | — | **The TTL is never in play** |
| **3** | Number purchase and its first period | Immediately before | Buy number, assign to profile, link campaign | On successful purchase | Yes — seconds |

**The invariant that makes this safe:**

> **The platform never incurs a provider cost that is not already covered by an open reservation or an existing commit.** Every irreversible provider cost is preceded, in the same second, by a reservation that covers it.

This is the "never pay a provider fee first and hope the customer funds it later" rule, stated so it can be tested.

**Why no reservation is held across the review.** Stage 2 commits at submission because that is when Telnyx's charge becomes real. After that the platform is owed nothing until stage 3, so there is nothing to hold. The reservation therefore cannot expire during the review, because none is open — which satisfies the requirement without lengthening the TTL. Lengthening it to days was considered and rejected: `RESERVATION_TTL_MINUTES` is a single global constant, so raising it would strand funds behind **every** feature's abandoned reservations platform-wide, not just telecom's.

**The one residual exposure, stated rather than hidden.** Between stage 2 and stage 3 the customer's remaining balance is not reserved, so another metered feature could consume it, and at approval time the number purchase could find insufficient funds. The platform is never out of pocket — stage 3 simply does not run — but the customer would have paid for a campaign that has no number, and a campaign with no active number auto-suspends after 15 days (T17). Mitigations, in order of cost:

1. **Re-check at approval and ask for a top-up** before stage 3. No new code beyond the check. **Recommended for launch.**
2. Alert the customer as soon as the approval arrives and the balance is short, well inside the 15-day dormancy window.
3. A distinct non-expiring onboarding hold, which would be a genuine new ledger concept and is **not** recommended for launch.

This is owner decision D11.

### 11.5 Settlement semantics

`commit()` recomputes from the reservation's snapshotted unit rate and a final quantity (E9). The implementation contract must state:

* `finalQuantity` for a message is the provider's `parts` value from `message.finalized` (T26), never a local estimate.
* Provider **cost** variance never changes the customer's charge and must not be modelled as one.
* `provider_cost_micro` on the rate row is the platform's cost **assumption** at activation, not the observed cost. Actual observed cost belongs in messaging-side attribution records, and admin margin reporting must compare the two rather than assuming the rate row is accurate.

### 11.6 Slice 4 path boundary gap

Parent contract §22.1 grants Slice 4 `app/Library/Telephony/**`, `app/Enums/Telephony/**`, controllers, jobs, notifications, commands, views, migrations and `tests/Feature/Usage/**`. It does **not** grant `app/Library/Usage/UsageWalletManager.php`, which belongs to Slice 5.

Creating telecom meters and activating their rates goes through `setActiveRate()` and `activateMetering()` (E6). If activation is performed by an operator command in `app/Console/Commands/**` — which Slice 4 does own — no boundary change is needed. If any change to `UsageWalletManager` itself proves necessary, the allowlist must be amended first. **Recommendation: a dedicated operator console command modelled on `ActivateConversationsUsageRate` (E14), which already demonstrates the correct admin-authorisation, currency-validation and idempotent-provisioning shape.**

### 11.7 Contract statements needing amendment

Recorded here for a future contract-editing lane. **Not edited by this lane** (task §12).

1. **§28.4's rationale is factually wrong about the normalizer.** It justifies US/Canada scope as matching "the existing `+1` normalization in `app/Library/AgencyProspecting/AgencyProspectPhoneNormalizer.php`". That class deliberately assumes **no** default region and hardcodes no calling code (E12). The scope decision stands; the reason given for it does not. The corrected rationale is §5's: US local long-code is the only number type whose compliance and cost are both fully evidenced.
2. **§28.4's recorded answer needs narrowing.** It records "US and Canada, local long-code only". §5.4 recommends **US only at launch**, with Canada following on Q1–Q4.
3. **§20 invariant C-8 needs a precise reading.** "A dormant Business produces zero external cost over any period" is false once a number is provisioned: rental, messaging capability and the campaign monthly all accrue regardless of activity, and dormancy can additionally attract a $250/month T-Mobile fine (T17). C-8 is true only for a Business in §7.7's `available` state, which has never activated the capability. The contract should say so, or C-8 will be read as forbidding something the design requires.
4. **§10.6's sequence needs the §11.4 split.** As written it assumes synchronous provisioning.
5. **§28.1a should record §7 as its answer**, including the integer-quantity invariant (§7.6), the destination allowlist (§7.12), the margin floor (§7.19) and the five-class charge separation (§7.21).
6. **§10.6's funding sequence should record §11.4's corrected shape** — a balance sufficiency gate for the complete upfront total, then short reserve-commit cycles at each provider commitment boundary, with the invariant that the platform never incurs an unfunded provider cost.
7. **Nothing in the schema or the onboarding flow may cap a Business at one campaign** (§3.5). The hard invariant is one brand per Business; campaigns are a bounded collection under it.

### 11.8 Shared-account balance failure — exact wording and blast radius (Correction Round 1)

**Corrected in Round 1 (C3).** The original pass wrote that "every Business's numbers are deleted" and that restoration was impossible by adding funds. Both overstated the source. Here is what the article actually says, in its own terminology, and what follows.

**The provider's terminology and timeline, quoted (T30):**

| Phase | Duration | The article's exact words |
|---|---|---|
| Trigger | Negative balance sustained **1 month** | "If your account is left with a negative balance for a period of 1 month, an abolishing process will take place." |
| **Deleted** | Immediately on abolishment | "In this process the numbers in your account will be deleted from the account." |
| **Hold** | 2 weeks | "After the numbers are deleted from the account they are set to a 'hold' status for the next two weeks." "While the numbers are in this 'hold' status you can still buy them again"; "only you will be able to search for and purchase the numbers." |
| **Aging** | 2 weeks | "If you do not buy back your numbers when they are in the 'hold' status, the status will change to 'Aging'…" "While the numbers are in an 'Aging' status no one (including you) can buy them." |
| **Released** | After Aging | "After the numbers have been left in 'Aging' for two weeks they will be released so that they are generally available." |

**The four terms are not interchangeable, and the document uses them precisely from here on.** *Deleted* is what happens to the numbers at abolishment. *Hold* is a two-week exclusive repurchase window. *Aging* is a two-week window in which nobody can buy them. *Released* is the final return to general availability. Nothing in the article says numbers are *suspended* or *disconnected*, and this document does not use those words for this failure.

**Restoration, classified honestly:**

| Phase | Restoration | Status |
|---|---|---|
| Hold (first 2 weeks) | **Possible, by repurchasing** — and only the original account can, so the platform has exclusive first refusal | **Known** |
| Aging (next 2 weeks) | The article directs the reader to `numbering@telnyx.com` or `support@telnyx.com` but **does not state whether such a request succeeds** | **Unknown** |
| After release | Numbers are generally available; anyone may buy them | **Known — effectively lost** |

**One constraint that is stated, and was previously over-generalised:** "If your account was abolished, you will not be able to get your numbers back by simply adding balance to your account." That rules out *topping up alone* as a remedy. It does **not** say restoration is impossible — the same article describes repurchase during hold as the remedy.

**The blast radius, without exaggeration.** The launch architecture puts every Business on one Telnyx account with one balance (§1), so this failure is **account-wide by construction**: it is not scoped to the Business whose usage caused the shortfall. The article's phrase is "the numbers in your account", carving out no exception, so reading it as reaching every provisioned number is a reasonable **inference** — and it is labelled as an inference, not a quotation.

**What makes it manageable rather than catastrophic:**

* The trigger requires a negative balance sustained for a **month**, not an instant. That is a long runway for the balance monitoring the architecture decision already requires (E1 §10).
* Deletion is followed by a **two-week window in which only the platform can repurchase** the numbers.
* AI Business OS reserves against the customer's wallet before every provider call, so provider spend is bounded by funds already collected. The platform balance should not drift negative at all if monitoring keeps it ahead of committed spend.

**What remains genuinely severe.** Numbers repurchased after deletion are the same numbers only if repurchased within the hold window; a Business whose number changed would have to re-tell its customers. And the 10DLC campaign's number assignment would need re-establishing, with 24 to 72 hours of carrier propagation (T18) and a dormancy risk if the gap exceeds 15 days (T17). **The correct mitigation is preventive, not curative:** alert on the platform balance long before it approaches zero, and treat "platform balance sufficient" as its own precondition, distinct from the customer wallet check (E1 §10). Question Q15 asks what notification precedes abolishment.

### 11.9 What does not change

RFC-005's wallet, ledger, reservation, refund, dispute and promotional-credit primitives are sufficient as they stand. This rate card adds meters and rates; it does not need a new ledger, a new entry type, or a change to reservation or settlement semantics beyond the TTL sequencing in §11.4.

---

## 12. Owner decisions required

| # | Decision | Recommendation | Consequence if deferred |
|---|---|---|---|
| **D1** | Launch country scope | **United States only**, Canada as a fast-follow gated on Q1–Q4 (§5.4) | §28.4 stays open and Slice 4 stays blocked |
| **D2** | Launch number type | **US local long-code only** (§5.3) | Same |
| **D3** | Approve the §7.4 rate card | Approve as proposed | §28.1a stays open; no telecom rate may activate; T-FUND, T-CAP and T-COST-9 stay blocked |
| **D4** | Approve the §7.3 governance formula and the §7.19 margin floor | Approve: 1.60× worst-case cost, $0.005 absolute floor and minimum | The card becomes a set of numbers with no rule for changing them |
| **D5** | Who pays a campaign resubmission fee | **Charge the customer per submission at the published rate, disclosed before the first submission**, and validate everything checkable before submitting (§8.10) | Absorbing it is the most likely route to negative onboarding margin |
| **D6** | Tax treatment at launch | **Absorb; do not charge per message** (§7.9) | Per-message tax attribution is unsupported by the provider (T28) |
| **D7** | Destination allowlist as a rate-card rule | Approve (§7.12) | A single out-of-scope message can lose 31 messages' margin (§8.8) |
| **D8** | Smart encoding default | **On** (§7.7) | Customers pay up to 3× for invisible characters (§8.6) |
| **D9** | Authorize a Telnyx support enquiry | Approve sending §10.5's draft | Q1–Q4 stay open and Canada cannot be scoped or priced |
| **D10** | Authorize reading the public pricing endpoint | Approve a read-only fetch of `GET /v2/public/pricing` before any activation | The Canadian figures §4.3a lists as not found stay ungathered; §7.13 keeps Canada blocked |
| **D11** | Whether to protect the post-approval balance between campaign submission and number purchase | **Re-check the balance at approval and ask for a top-up if short** (§11.4). Do not build a new non-expiring hold for launch | A customer could pay for a campaign, run the balance down, and end up with a campaign that has no number and goes dormant in 15 days |
| **D12** | Whether a Business may hold more than one campaign at launch | **Allow up to the provider's limit, but default the onboarding flow to one campaign**, offering a second only when a genuinely distinct use case is declared (§3.5). Disclose the extra number rental and campaign monthly before the customer commits | Either a needless cap that blocks a legitimate second use case, or an unbounded flow that multiplies recurring cost silently |
| **D13** | Whether to enable the provider-side per-Business daily spend cap | **Enable `daily_spend_limit` on every Business's messaging profile**, set above that Business's wallet-side cap, as defence in depth (T31) | The only spend control is wallet-side; a bug there has no provider-side backstop |

Decisions D1–D8 and D11–D13 are documentary and can be recorded immediately. D9 and D10 involve contacting or reading from the provider and are therefore outside what this lane was authorized to do.

---

## 13. Legal review, validation and gate status

### 13.1 Separation summary

| Category | Where |
|---|---|
| Proven current facts | §2.2 and §2.3 rows marked Proven; §3.1, §3.5, §4.1, §4.5, §6.2–§6.4, §11.8 |
| Conditional facts | R6, R7, R8, R9; §3.4; §4.2–§4.4; §6.6 |
| Recommendations | §5.4, §7 entire, §8.13, §9, §11 |
| Not found, and explicitly **not** claimed absent | §4.3a; §6.7; §8.7 |
| Inferences, labelled as such | T30's "all numbers" reading (§11.8); the platform-responsibility split (§3.3) |
| Owner decisions required | §12 |
| Telnyx questions | §10 |
| Implementation consequences | §11 |
| Legal-review items | §13.2 |

### 13.2 Legal-review items

Counsel must see, before US launch traffic:

1. Whether TCPA quiet hours (R4) apply to A2P text messages, and whether a given customer's messages are "telephone solicitations".
2. The current codified meaning of "prior express consent" after the Eleventh Circuit vacatur (R6).
3. Whether the platform's default consent-capture flow produces prior express written consent for marketing.
4. Whether AI Business OS bears TCPA liability as the sender, the customer does, or both, and how the terms of service should allocate it.
5. Whether the 47 CFR 64.1200(a)(10) "revoke-all" obligation, now effective 31 January 2027 (R5), requires design work before that date.
6. State-law text-messaging statutes, which this document did not survey.
7. For Canada, before any Canadian launch: whether the platform or the customer is the "person who sends" under CASL (R1), and how sender identification and the 60-day contact-validity requirement are satisfied by a platform-generated message.
8. Consent-evidence retention, given that Telnyx publishes a recommendation rather than a requirement (T19, Q7).
9. Whether AI Business OS must itself collect tax on its retail telecom sale, separately from Telnyx's tax on the platform's purchase (§7.9).

### 13.3 Validation record

| Check | Result |
|---|---|
| Every external citation verified by opening the cited page | Yes for every row marked Proven. Rows R6, R8 and R9 are explicitly marked Conditional because their pages were not opened; each is carried into §10 or §13.2 rather than relied on |
| Every repository citation verified | Yes — all read in this worktree at base `6c820c801da08ecfd6165d1d3a52ae6336606f0c` |
| All prices timestamped | Yes — 2026-09-09 throughout, stated in §2 and in the header |
| Currencies and units explicit | Yes — USD throughout; micro-units defined at §7.5 as 1 = $0.000001 |
| No secret-shaped values | Yes — no key, token, credential or account identifier appears |
| No rate described as activated | Yes — §7 is labelled a recommendation in its opening line and again in §12 |
| No live API or provider operation occurred | Yes — no Telnyx API call was made. The public pricing endpoint was deliberately **not** called; it is recommended to the owner as D10 |
| Documentation-only change | Yes — exactly one new file |
| **Round 1: every claim re-verified against the exact wording it rests on** | Yes. T1, T3, T10, T11, T30 were re-opened and re-quoted; T31–T35 were added from newly opened pages; E15 and E16 were verified in the repository |
| **Round 1: inference separated from quotation** | Yes. T30's "all numbers" reading and R6/R8/R9's status are labelled inferences or Conditional, not quotations |
| **Round 1: all arithmetic recomputed** | Yes. Every §7.3 formula floor, all ten §8.1 per-unit margins and all seven §8.2–§8.6 scenarios were recomputed from the micro-unit inputs and match the published figures exactly |
| **Round 1: billing-unit terminology unified** | Yes. Rate card, `unit_label`, reservation quantity, display copy and tests all specify **message part** (§7.7) |
| **Round 1: stale-phrase scan** | Yes. Every surviving occurrence of a superseded phrase sits inside a Correction Round 1 record that quotes the old wording deliberately |

### 13.4 Price re-verification rule

Every price in §6 and §7 carries the access date 2026-09-09. **No rate may be activated on evidence older than 90 days** (§7.13). Before any activation, re-verify §6.2's base rates and carrier fees, §6.3's number and campaign fees, and §6.4's registration fees, against the live pages or the public pricing endpoint.

### 13.5 Gate status

**§28.4 — recommended, not yet recorded.**

* Launch countries: **United States only**, narrowed from the anticipated US and Canada on the evidence in §4.3, §4.3a, §5.4 and §6.7. **Round 1 re-tested this and preserved it** — the corrections narrowed the Canada *claim* from "no pricing exists" to "no sufficient pricing was found", which changes the wording but not the gate: §7.13 still bars activating a rate whose worst-case cost has not been evidenced.
* Number type: **local long-code only**. Toll-free, short code and alphanumeric sender ID are excluded, alphanumeric by primary evidence (T19) rather than judgement.
* Compliance scope: A2P 10DLC with **exactly one brand per Business** and **one to five campaigns under that brand**, each campaign owning its own numbers, per §3.5.
* Canada: deferred, not cancelled. Gated on Q1–Q4 **and** on gathering the four Canadian cost figures §4.3a lists as not found, which requires owner decision D10.
* Awaiting owner decisions D1, D2, D9 and D12.

**§28.1a — recommended, not yet recorded.**

* The complete policy is §7 and covers every element §28.1a enumerates.
* **No rate is activated.** Parent contract §21.2's prohibition remains fully in force.
* Awaiting owner decisions D3–D8, D11 and D13, plus D10 for Canadian pricing.

**Slice 4 remains blocked.** Its prerequisites are 3 and 5 complete plus §28.4 and §28.1 recorded (parent contract §21.1). This document supplies the research and the recommendation for two of those; recording them is the owner's act, not this document's.

**Nothing here authorizes any production provider activity.** No Telnyx API call, account, number, brand, campaign, messaging profile or rate was created, activated, queried or modified in producing this document.

---

`TELNYX US/CANADA COMPLIANCE AND RETAIL RATE-CARD DECISION — CORRECTION ROUND 1 READY FOR HUMAN/CHATGPT REVIEW`
