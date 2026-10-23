# 07 · Remaining blockers and exact owner actions

Everything technically possible with the data and access available has been built and run on an isolated staging database. What remains needs a credential or a fact only a person holds.

| # | Blocker | Who | Action |
|---|---|---|---|
| 1 | **Telnyx portability check not run** — `TELNYX_API_KEY` is not set in this environment (`php artisan telnyx:portability-check +17205028306` reports exactly that). Separate from every other deliverable. | MotionGrove operator | Add secret `TELNYX_API_KEY` (and `TELNYX_WEBHOOK_PUBLIC_KEY` for the porting webhook) to the environment. Never paste in chat. |
| 2 | GHL workflow definitions / *New Inquiry Received* screenshots unavailable; no authenticated GHL browser session exists here | MotionGrove operator / owner | Attach the screenshots, or run a supervised read-only session in the GHL UI; the reconstruction is marked "inferred" until then. |
| 3 | Pricing conflict (`/brochure`), FAQ contradictions, public phone (+1 720-502-8306 vs +1 720-917-8741), logo variant, testimonials, guest-photo releases, legal-page blanks | Lumina Aura owner | Answer the "flagged" table in 03. The preview shows "Request a quote" until prices are confirmed. |
| 4 | **Business hours** for Lumina Aura (none exist in GHL) | Lumina Aura owner | Enter weekly hours (Business → Location). Until then business-hours gating is inert; quiet hours (21:00–08:00 Denver) still apply to every pack message. |
| 5 | Review link and questionnaire form link | Lumina Aura owner | Save the Google review link (SEO → Reviews) and publish the "Event questionnaire" form as a direct link. Pack messages never promise a link that isn't set up. |
| 6 | Connect a mailbox / messaging sender on the real account | Owner + operator | Not done on staging by design (no outbound). Pack emails log as failed-and-continue until a mailbox exists. |
| 7 | **Consent**: GHL holds no recorded consent; only 1 contact has DND | Owner | Contacts import as *subscribed-by-default but consent unrecorded*. Decide whether to require re-opt-in before any bulk outreach. The DND contact is imported as unsubscribed. |
| 8 | 4 contacts have no phone (email-only) | Owner / product | MotionGrove contacts are phone-keyed. They are imported with a clearly non-dialable key (`np-…`), full email history and deals; decide whether to add real phones or leave. |
| 9 | Staff accounts | Operator | Two other GHL admins were created as **least-privilege staff** with random unusable passwords; no invitation was sent. Confirm who should have access and send real invitations. |
| 10 | HighLevel Support case, LOA signature, carrier account data, A2P status, charges, port submission | Owner / operator | Unchanged — see 04. Nothing was submitted. |
| 11 | DNS access / cutover approval | Owner / operator | Unchanged — see 05. |
| 12 | Task inbox UI, old-URL redirect feature, video section, port-fee billing decision | Product | Not built (each needs its own contract). |
| 13 | Production provisioning of this Business | Operator | Not done. `lumina:provision` refuses any host/database that is not explicitly opted in and confirmed. |
