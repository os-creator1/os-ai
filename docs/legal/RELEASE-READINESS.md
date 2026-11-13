# MotionGrove Legal Center — release readiness

Status of the nine public legal documents. **None of them is an attorney-reviewed, operative agreement.**
They are published for reading as drafts (`1.0-draft`). No document is offered for affirmative acceptance
(`LEGAL_ACCEPTANCE_ENABLED` defaults to off), and `acceptance_eligible` is false in `config/legal.php` for
every document that still depends on an unverified operational fact.

## Where things live

- Document text (single source): `resources/legal/<slug>.md` — placeholders `{{legal_name}}`, `{{company_number}}`,
  `{{registered_address}}`, `{{country}}`, `{{legal_email}}`, `{{brand_name}}` are filled from `LegalCompany`.
- Registry (titles, versions, SEO, glance panel, eligibility): `config/legal.php`.
- Operator identity: `legal_company_profiles` (append-only revisions), edited at Platform Owner → Settings →
  Legal Company Information.
- Accepted text: `legal_document_snapshots` + `legal_acceptances`.

## Publication gates still open

1. Counsel review (Lithuanian commercial/privacy counsel with US cross-border input) — not done; no page claims it.
2. Official Registrų centras extract confirming Jazmin medija, MB / 306304073 / registered address — pending.
   Registered office is Platform-Owner-editable if it changes.
3. Effective date: not set. Pages show "Set on publication"; set `effective` per document in `config/legal.php`
   on the actual publication date.
4. `legal@getmotiongrove.com`: alias reported configured by the owner; external delivery / reply-from not tested.
5. Documents that stay out of operative acceptance until their facts are verified:
   Privacy Policy, Data Processing Addendum, Cookie Policy, Security & Service Disclosure.

## Unverified items withheld from the public text

The source package marked these `[VERIFY]`. They are **not** published as claims. Sections that were
purely a template were replaced by "This section is not yet published…"; table cells read "To be confirmed".

| Item | Where it was in the source | State on the public page |
|---|---|---|
| DPA Annex B — technical & organisational measures | DPA | Withheld (notice) |
| DPA Annex C — subprocessor/recipient register | DPA | Withheld (notice) |
| DPA Annex D — international transfer mechanisms | DPA | Withheld (notice) |
| DPA subprocessor-change notice mechanism and objection period | DPA §5.2 | Removed from the sentence |
| DPA agency-hierarchy processing roles; processing locations | DPA Annex A | Removed |
| Cookie inventory (exact cookies/analytics, duration, consent) | Cookie §8 | Withheld (notice) |
| Cookie preference control / consent logging / script gating; embedded-service mechanisms | Cookie §7, §9 | Marker removed; text keeps "where legally required" wording only |
| Retention periods per data category and backups | Privacy §13 | "To be confirmed" |
| Actual payment processors / Stripe configuration | Privacy §7 | Reworded neutrally ("third-party payment processors") |
| AI provider names, retention and training terms | Privacy §9 | Marker removed |
| Provider legal names and roles (processor / subprocessor / independent controller) | Privacy §11 | Marker removed |
| Hosting regions and transfer safeguards | Privacy §12 | Marker removed |
| Actual security controls (MFA, logging, encryption, backups, incident process) | Privacy §14, Security §3, §6 | Markers removed; no specific controls asserted |
| Solely-automated decision-making assessment | Privacy §20 | Marker removed |
| Live DPA / subprocessor register links | Privacy §23 | Removed |
| Stripe Connect loss-liability configuration | Payments | Text only describes the contractual allocation; confirm against the Stripe dashboard before release |
| Terms acceptance evidence for existing accounts | — | None fabricated; existing accounts have no acceptance record |

Also removed or neutralised because they were internal drafting directives rather than terms (no new legal
obligations were added): "Do not publish a categorical statement … PCI compliant" (Privacy §7), "Do not publish blanket
‘we never sell or share data’ claims" (Privacy §21), "These measures must reflect the verified production environment"
(Privacy §14), "incident-response workflow … still to verify" (DPA §7.1), the table headings "verify case-by-case" /
"before publication" (Privacy), and "Do not assume backups guarantee…" became "Backups do not guarantee…" (Security §6).
The rest of each document is word-for-word the supplied text apart from the identity placeholders and the dropped
status/effective-date header lines.

PCI DSS, SOC 2, ISO, encryption-at-rest, pen-test, uptime and retention claims: none are made.

## Product/UI discrepancies observed (not changed)

- The legacy admin editors for Terms of Use / Privacy Policy (`AppConfig` `terms_of_use`, `privacy_policy`)
  still exist. On the marketing host `/terms-of-use` now redirects to `/terms-of-service` and `/privacy-policy`
  shows the Legal Center page; every other host keeps the legacy pages. The admin editors therefore no longer
  affect the marketing host.
- The Agency white-label signup form (`resources/views/agency-site/signup.blade.php`) shows the Agency's *own*
  terms/privacy checkbox. It is deliberately not pointed at MotionGrove's documents.
- Billing/checkout copy was not audited line by line against the refund policy; only links were added.
- Links were added to registration, the signup-plan checkout, the Agency plan checkout, the billing
  subscription panel and the white-label settings note. Not added (no single natural surface found / out of scope):
  trial/private-offer checkout pages, document-signing and payment-connect workflow screens, communications setup.

## Deployment notes

- New migration `2026_11_28_100000_create_legal_center_tables.php` (3 tables, seeds revision 1 of the company profile).
- No env vars required. Optional: `LEGAL_ACCEPTANCE_ENABLED=true` once the documents are approved.
