# MotionGrove Legal Center — release checklist (internal)

**Internal only. Not a public page and not part of any customer agreement.**
Status of the whole package: **draft, not approved, not published.** Nothing below makes any document legally approved.

## 1. How a document is released

Source text: `resources/legal/<slug>.md`. Release record: `config/legal.php`. Facts: `LEGAL_*` environment variables.
A document is public only when **all** hold (`App\Library\Legal\LegalRegistry::isPublished`):

1. `status` = `approved` with `approved_by`, `approved_at` and `effective_date` set in `config/legal.php`;
2. no `[VERIFY: ...]` placeholder remains (fill the `LEGAL_*` facts and resolve every in-text marker);
3. `php artisan legal:status --seal` has recorded the sha256 of exactly that text for that version in `resources/legal/hashes.json`.

Until then the new URLs return 404 in production, `/terms-of-use` and `/privacy-policy` keep serving the existing operative pages unchanged, the footer keeps its existing configured links, and no acceptance checkbox is shown or recorded. On staging only, `LEGAL_PREVIEW_DRAFTS=true` renders the drafts with a "Draft — pending legal approval" banner and `noindex`.

Changing an approved document means a new `version` in `config/legal.php`, copying the superseded text to `resources/legal/archive/<slug>/<old-version>.md`, editing, and sealing again. Existing customers are **not** forced to re-accept; acceptance rows keep the exact version and hash each person accepted.

`php artisan legal:status` lists every document and exactly what blocks it.

## 2. Facts that must be supplied (never invented here)

| Fact | Where | Why it blocks |
| --- | --- | --- |
| Exact registered legal name/spelling (the supplied brief says **Jazmin Media MB**), company code, registered address | `LEGAL_REGISTERED_ENTITY`, `LEGAL_REGISTERED_ADDRESS` | Not present anywhere in the repository; a web search found no entry for "Jazmin Media MB" (only an unrelated "JAZMINA, UAB"). Obtain from the Lithuanian Register of Legal Entities (Juridinių asmenų registras). |
| Legal notices, billing, privacy, security, compliance, support, agency-support emails | `LEGAL_EMAIL`, `LEGAL_BILLING_EMAIL`, `LEGAL_PRIVACY_EMAIL`, `LEGAL_SECURITY_EMAIL`, `LEGAL_COMPLIANCE_EMAIL`, `LEGAL_SUPPORT_EMAIL`, `LEGAL_AGENCY_SUPPORT_EMAIL` | Mailboxes must exist and be monitored. |
| Hosting, email, AI, communications and payment providers: legal names, roles, countries, contracts | DPA Annex C, Privacy Policy §§11–12 | Not determinable from the repository. |
| International-transfer mechanism (adequacy/SCCs/UK/Swiss) | DPA §9, Annex D | Needs the real vendor map and executed SCCs. |
| Technical and organizational measures actually in production | DPA Annex B, Security disclosure | Must be verified by the engineering owner, with evidence. |
| Retention, export, deletion and backup periods | Privacy Policy §13, DPA §10 | Not defined in the repository. |
| AI providers, data sent, retention, training/opt-out status | Privacy Policy §9 | Not verified. |
| Cookie/tracker inventory and consent behavior | Cookie Policy §8–9 | Must be taken from production behavior; no consent-management mechanism was verified. |
| Effective date, approver, version | `config/legal.php` | Release decision. |

## 3. Legal review points

- Greater of six months' fees or USD 100 liability cap, consequential-loss exclusion, indemnities, no-personal-guarantee clause: preserved as a **proposal** for counsel (Terms §§19–22). Not described anywhere as enforceable.
- Lithuanian law / Vilnius forum for international B2B customers; consumer fallback.
- No-refund default, automatic renewal and cancellation wording against US state automatic-renewal laws, the 28-day cadence (13 charges per 364 days), trial and discount disclosures.
- TCPA/CAN-SPAM/CASL/GDPR-ePrivacy/A2P wording. **Acceptable Use §1.3 was added beyond the supplied draft** so that TCPA and the other regimes are named; counsel should confirm it.
- eIDAS/E-SIGN statements; DSA hosting duties; Article 28 DPA content.
- Whether Jazmin Media MB's registered form (MB) and the brand-of-entity wording match the register.

## 4. Verified from the repository (this implementation)

- **Public pricing in the supplied policy** (Core $97, Growth $297, Agency $497 per 28 days) matches the 28-day billing cycle the code uses (`BillingCycle::EVERY_28_DAYS`; checkout shows `every 28 days` from the plan catalog). Private offers: the $197 price and any trial are **not** written in any legal document.
- **Stripe lanes** (from the gateways' own documentation and parameters):
  - Lane A, MotionGrove subscriptions: first-party direct charges on the platform's own Stripe account via hosted Checkout; no Connect account; no card data handled by the app.
  - Lane B, a Business's own payments (`StripeApiConnectGateway`): connected account created with `controller[fees][payer]=account`, `controller[losses][payments]=stripe`, `controller[requirement_collection]=stripe`, full Stripe dashboard; direct charges; no `application_fee_amount`, `on_behalf_of` or `transfer_data`. Existing **standard** accounts can also be connected by OAuth.
  - Lane C, Agency client subscriptions (`StripeApiAgencyGateway`): same `controller` settings and no platform cut.
  - Lane D, usage funding: separate gateway and `usage-billing` webhook with a wallet ledger and chargeback notification.
  - Consequence for the drafts: under the `controller` settings above, Stripe (not MotionGrove) bears negative-balance liability on lanes B and C **for accounts the platform creates**. The Payments Addendum therefore correctly avoids claiming that customers owe MotionGrove negative balances by default and preserves provider rights. Confirm the account-level settings in the live Stripe dashboard before release; the code cannot prove them.
- **Refunds:** there is **no** MotionGrove-side discretionary refund action for platform subscriptions in the code (`PlatformStripeGateway` has no refund method; the immediate-termination path states it issues no proration refund). Existing refund code (`RefundFinalizer`, `BusinessDocumentRefund`) is a Business refunding **its own** customer through its own Stripe account. No refund path was added, so no authorization mechanism was needed; if one is built later it must be gated by a backend permission, audited, and must not bypass Stripe state.

## 5. Technical risks that wording cannot fix

- Consent, STOP/suppression, calling hours, caller-ID authority and recording-consent enforcement were **not** verified in the messaging/calling code; the policy text must not be read as evidence they are enforced.
- Cookie consent gating and the real tracker inventory were not audited.
- E-signature evidence (signer authentication, audit trail integrity, retention) was not audited; the addendum deliberately disclaims qualified signatures and identity proofing.
- The Agency white-label signup page (`agency-site/signup`) does not yet show the Agency's own merchant identity, refund terms or the required MotionGrove/Stripe disclosures; it needs configured Agency legal fields (the Agency's own documents must never be replaced by MotionGrove's). Agency sites currently keep their own configured links only.
- Acceptance evidence stores IP and user agent: retention for these rows must be set in the Privacy Policy retention schedule. Rows deliberately have no foreign keys so evidence survives account deletion; confirm that is acceptable under the retention rules.
- Signup recording is best-effort after the account exists (a failure is reported, not blocking). If that is not acceptable, move acceptance into the same transaction as account creation.
- Fact values (contact emails/entity details) are not part of a document's hash; changing them does not change the version. Counsel should decide whether that is acceptable.

## Appendix — pre-publication and operational checklist (from the supplied draft package)

> **Internal only. Not a public legal page or part of a customer agreement unless explicitly incorporated.** Legal text alone cannot prevent litigation or displace mandatory law, Stripe payment obligations, duties of an MB manager, telecommunications rules or GDPR obligations. The actions below are necessary to make the documents accurate and to reduce real exposure.

## A. Identity and contracting party

- [ ] Obtain exact legal name from Lithuanian Register of Legal Entities (verify whether officially **Jazmin Media MB**, **MB Jazmin Media**, or another styling), entity code, VAT code where applicable, registered address and company email.
- [ ] Confirm business name/trademark/public brand uses **MotionGrove** and all actual sellers/invoices identify the registered MB as required.
- [ ] Ensure Stripe seller identity, invoices, subscription receipts, footer, acceptance text, terms and bank relationships describe the **same legal entity**.
- [ ] Confirm executive/CEO signs only in a representative capacity and gives **no unintentional personal guarantee**. Obtain advice on Lithuanian MB member/manager duties and insolvency triggers.

## B. Legal editorial approval

- [ ] Counsel reviews B2B formation and applicability to US and EU customers; consumer-law fallback.
- [ ] Counsel reviews proposed **greater of six months' fees or $100** liability cap, consequential-loss exclusion, indemnities, personal guarantee disclaimer and enforceable mandatory carve-outs. Adjust if legally necessary; do not claim immunity.
- [ ] Counsel confirms Lithuanian law/Vilnius forum clauses, cross-border enforceability and dispute notice provisions.
- [ ] Counsel reviews no-refund default and any legally required corrections/refunds, US state automatic-renewal requirements, trial conversion, discount/sales-call representations and 28-day cadence.
- [ ] Counsel reviews EU GDPR/ePrivacy, US TCPA/CAN-SPAM, Canadian CASL/A2P carrier rules as applicable, EU DSA hosting duties and EU/US e-signature law.
- [ ] Effective dates, version IDs and links are approved before first publication.

## C. Stripe, chargebacks and financial controls

- [ ] Inventory payment **Lane A** (MotionGrove SaaS), **Lane B** (Business Connect), **Lane C** (Agency client subscriptions) and **Lane D** (usage billing), with merchant of record, processor account, charge type, region, connected-account type, loss-liability settings and debit/reserve authority.
- [ ] Determine **which party Stripe can charge** for negative balances in each flow; don't rely solely on `direct charge` labels.
- [ ] Implement/verify signed webhooks, event idempotency, refunds/disputes reconciliation and authoritative subscription status.
- [ ] Make proper checkout disclosures of actual **28-day** recurrence, total charge, tax, trial conversion, discounts ending, renewal and cancellation controls.
- [ ] Restrict **discretionary** refunds to authorized Company staff, retain evidence, and provide mandatory refunds as legally required.
- [ ] Verify actual cancellation completion status, confirmations and no renewals after effective cancellation.
- [ ] Review reserves/merchant risk caps and relevant Tech E&O/Cyber insurance exclusions; policy language does not automatically cover processor deficits.

## D. Privacy, DPA and security accuracy

- [ ] Verify data controller vs processor roles for Company, Agency and Managed Client relationships; complete DPA Annex A.
- [ ] Complete Annex B from **actual** production architecture: TLS, stored secrets, access control, tenant isolation, backups/restores, incident handling, logging, deletion, patching and verification evidence.
- [ ] Complete Annex C with accurate **legal names**, purposes, roles, locations and contracts of hosting, email, AI, Stripe, Telnyx and other real providers; distinguish independent controllers.
- [ ] Complete Annex D with appropriate transfer mechanisms and unmodified applicable Standard Contractual Clauses/other lawful mechanisms.
- [ ] Document actual deletion/export/retention periods for CRM data, documents, messages, payment records, backups, security logs and AI prompts.
- [ ] Verify AI provider data training, processing and retention conditions rather than promising unsupported zero retention.
- [ ] Verify real cookie/tracker inventory and consent gating; provide accessible reject/manage/withdrawal where legally required.
- [ ] Verify data rights request and breach notification procedures; do not promise non-operational processes.

## E. Communications and content compliance

- [ ] Identify whether SMS, calling and email controls actually enforce consent, suppression, STOP, sender registration, authorized caller ID, calling destination limits and frequency/time restrictions where appropriate.
- [ ] Review US TCPA/FCC, CAN-SPAM, Canada CASL and relevant local rules for target markets; customer-facing disclaimers alone are insufficient.
- [ ] Clearly disclose **no emergency calling** absent verified provider support.
- [ ] Determine whether call recording/transcription exists and what permissions/retention rules apply.
- [ ] Add lawful abuse and hosted-content reporting/response processes where needed.
- [ ] Reconcile Agency white-label signup legal links with the **Agency's merchant and contracting identity**, not blindly Company's refund policy.

## F. Acceptance evidence and legal pages

- [ ] Treat `/terms-of-service` as the **single Terms & Conditions page**, keeping legacy `/terms-of-use` links valid/redirected safely.
- [ ] Publish primary legal pages: `/terms-of-service`, `/privacy-policy`, `/refund-policy`, `/acceptable-use`, `/data-processing-addendum`, `/cookie-policy`.
- [ ] Publish appropriately incorporated supporting pages `/payments-and-esignatures`, `/agency-terms`, `/security`.
- [ ] Do not deploy drafts containing `[VERIFY:...]` or claims of legal approval before factual review.
- [ ] Ensure registration/checkout has lawful, conspicuous terms links and affirmative acceptance with version/hash, timestamp, actor, organization and applicable offer context recorded.
- [ ] Preserve historic accepted document versions; new material versions require appropriate notice/consent based on applicable law.
- [ ] Public pages should have consistent design, working tables of contents, accessible print flow and meaningful metadata; staging must remain appropriately excluded from search results.

## G. Risk and insurance (not contract wording)

- [ ] Confirm the MB's actual corporate formalities, bank/accounting separation and manager responsibilities; brand re-registration alone does not create a new company.
- [ ] Evaluate Technology E&O / professional indemnity and Cyber insurance with US claims, privacy incidents, AI/automation, telecommunications and regulatory defense; check exclusions, deductibles and claim-notice rules.
- [ ] Maintain appropriate operating reserve for disputes and provider costs; liability caps and indemnity rights do not guarantee reimbursement.
- [ ] Commission targeted independent security review of Stripe Connect, tenant authorization, document/signature, communications and agency flows before scaling.

## Key authoritative materials for counsel/reviewer (research references, not incorporated promises)

1. EU General Data Protection Regulation, particularly Articles 28, 32, 33, 44–49 and 82: https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng/
2. EU eIDAS (Regulation (EU) 910/2014, as amended), especially Article 25: https://eur-lex.europa.eu/eli/reg/2014/910/2024-05-20/eng
3. European Commission electronic-signature guidance: https://ec.europa.eu/digital-building-blocks/sites/spaces/DIGITAL/pages/880312429/eSignature%2BFAQ
4. Stripe Connect reserve and negative-balance explanation: https://support.stripe.com/questions/reserves-for-connect-platforms-and-connected-accounts?locale=en-GB
5. Stripe direct charges / negative-balance guidance: https://support.stripe.com/questions/what-should-i-know-about-using-direct-charges?locale=en-GB
6. FTC CAN-SPAM compliance guidance (including B2B): https://www.ftc.gov/business-guidance/resources/can-spam-act-compliance-guide-business
7. Lithuanian Civil Code, Article 2.50 (legal-entity/member liability): https://www.infolex.lt/teise/DocumentSinglePart.aspx?AktoId=20799&StrNr=2.50
8. FCC TCPA consent-revocation rules (confirm operative requirements and dates): https://docs.fcc.gov/public/attachments/FCC-24-24A1.pdf

**End of consolidated draft package.**
