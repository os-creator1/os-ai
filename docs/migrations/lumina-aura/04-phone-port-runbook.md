# 04 · Retaining +1 720-502-8306 (LC Phone → MotionGrove-managed Telnyx)

**Status: prepared, not submitted.** No port order exists. LC Phone is untouched, the number is
not released, A2P registration is untouched, routing is unchanged.

## 1. Verified facts about the number (GHL API, read-only)

* `+17205028306`, local, US; voice + SMS + MMS; `isDefaultNumber: true`; added 2026-03-09.
* `origin: "twilio"`, SID prefix `PN…` → **LC Phone numbers are Twilio numbers owned by HighLevel's Twilio account**, not by Lumina Aura. The "losing carrier" for the port is therefore effectively HighLevel/Twilio, and Lumina Aura is a *sub-account customer*, not the account holder on the carrier record.
* `addressSid: null`, `bundleSid: null` – no regulatory address/bundle attached in the exposed data.
* GHL location profile: Lumina Aura Events, 10050 Quivas Street, Thornton, CO 80260, owner contact **Morgan Wallace** (location profile) — the GHL **user** is "Morgan Norman-Wallace". The LOA must match the carrier record **exactly**; confirm the legal spelling before anyone signs.
* The GHL profile phone is a *different* number (+17209178741) and appears on one website page.

## 2. Eligibility — NOT yet confirmed

The official Telnyx portability check (`POST /v2/portability_checks`) requires a Telnyx API key.
**No Telnyx credential exists in this environment** (`TELNYX_API_KEY` unset), so the check was
not run and I will not assert eligibility. Official docs say each number returns `portable`,
`fast_portable`, `messaging_capable` and, if refused, `not_portable_reason`.
Run it (read-only, free) as soon as the key is available:

```
curl -s https://api.telnyx.com/v2/portability_checks -H "Authorization: Bearer $TELNYX_API_KEY" \
  -H 'Content-Type: application/json' -d '{"phone_numbers":["+17205028306"]}'
```

## 3. Is "LC Phone → Telnyx" the same as GHL's LC Phone → Twilio migration?

No. HighLevel's published guide *Moving Phone Numbers: Migration Guide* covers moving numbers
**between HighLevel sub-accounts / LC Phone and a customer's own Twilio account**, done by
collecting IDs and opening a HighLevel Support ticket with a cutover window. It is not a
carrier-to-carrier port to Telnyx. The in-app *Port-in* flow (Settings → Phone Integration)
only ports numbers **into** LC Phone. I found **no published HighLevel procedure for porting a
number out of LC Phone to a third-party carrier**. Treat the route as: **a HighLevel Support
request for port-out authorization + carrier account details**, then a normal Telnyx port-in.
This is unconfirmed until HighLevel Support answers (below).

Alternative that may be offered by HighLevel: *move the number to a Twilio account owned by
Lumina Aura* (their documented path), then port Twilio → Telnyx with Twilio's own port-out
data. That puts the number through a second paid account; **I have not taken that route and will
not without approval.** If HighLevel refuses port-out entirely, the verified alternatives are
(a) that Twilio hop, (b) keep the number on LC Phone and forward/route to MotionGrove, or
(c) a new Telnyx number with call/SMS forwarding from the old number during transition.

## 4. What HighLevel Support must provide

* Written confirmation that +17205028306 **can be ported out** and the correct process.
* The **losing-carrier account holder name, billing/service address, account number (and PIN/passcode if any)** exactly as on the carrier record (for a LC Phone number: HighLevel/Twilio's).
* Whether a **CSR** can be supplied, and the port-out authorization the carrier needs from the *account owner* (HighLevel's agency/company `0nKuVZCXsff0PUBzzLqJ` owner, not necessarily Lumina Aura).
* Confirmation there is **no port lock** and that they will not auto-release/disable the number when the port begins (Telnyx must receive it while the number is still active).
* Whether the **A2P 10DLC brand/campaign** tied to the number is registered under HighLevel's ISV/TCR account or Lumina Aura's own brand, and what happens to it on port-out.

### Draft support case (ready to send once approved — not sent)

> **Subject:** Port-out request for LC Phone number +1 720-502-8306 (Location 7HLIWryaYyed99beyIB7 — Lumina Aura Events)
>
> Hello HighLevel Support,
> We are moving Lumina Aura Events (Location ID 7HLIWryaYyed99beyIB7, company 0nKuVZCXsff0PUBzzLqJ) to another platform and want to **retain our existing LC Phone number +1 720-502-8306** by porting it to Telnyx. Please do **not** release, disable or change this number.
> 1. Can this number be ported out of LC Phone to Telnyx? What is the required process?
> 2. Please send the losing-carrier details Telnyx will validate: account holder name, service address, account number, PIN/passcode (if any), and whether a CSR is available.
> 3. Who must sign/authorize port-out (location owner or agency owner)?
> 4. Is the number's A2P 10DLC brand/campaign registered under our own brand or HighLevel's? What happens to it after port-out?
> 5. Please confirm there is no port lock and the number stays active until the port completes.
> We will keep LC Phone active until the port is confirmed complete. Requested window: weekday, business hours Mountain, after both parties confirm the FOC date.
> Authorized contact: [owner name/email – to be filled by the owner].

## 5. What Telnyx requires (official docs)

* Port-in is an API/portal order: **portability check → draft order → fulfill → submit** (`draft → in-process → … → ported`; `exception` needs a fix and resubmit). Webhooks: `porting_order.status_changed`, `porting_order.new_comment`.
* **Letter of Authorization – required for all ports**, signed by the person authorised on the **losing-carrier account**. Telnyx provides a template/auto-generate option. Must list the number(s), legal name, service address, current carrier, account number, signing date, signature.
* **Recent invoice / bill** (alternatives accepted) for most ports.
* End-user details: account-holder name, service address, account number, PIN if applicable, authorised person, billing telephone number. Must match the losing carrier's records exactly (punctuation and abbreviations included).
* Optional at order time: `connection_id` (voice), `messaging_profile_id` (SMS), emergency address. Requested FOC date is a request; the losing carrier sets the real date. Once submitted the order cannot be materially changed.

### Port order draft (payload outline — NOT submitted)

```json
{
  "phone_numbers": [{"phone_number": "+17205028306"}],
  "customer_reference": "lumina-aura-ghl-migration",
  "end_user": {
    "admin": {
      "entity_name": "<legal name on the carrier record — from HighLevel>",
      "auth_person_name": "<authorised signer>",
      "account_number": "<from HighLevel>", "pin_passcode": "<if any>",
      "billing_phone_number": "<from HighLevel>"
    },
    "location": {"street_address": "10050 Quivas Street", "locality": "Thornton",
                 "administrative_area": "CO", "postal_code": "80260", "country_code": "US"}
  },
  "documents": {"loa": "<signed LOA document id>", "invoice": "<document id>"},
  "misc": {"type": "full", "remaining_numbers_action": "disconnect"},
  "requested_foc_date": "<agreed date/time, America/Denver>"
}
```
Bracketed values are **unknown** and are not guessed. Field names follow Telnyx's Porting Orders API; verify against the current API reference when creating the draft.

## 6. Who must sign / approve what

| Item | Who | Why |
|---|---|---|
| Letter of Authorization | The **authorised person on the losing-carrier account** (for LC Phone: whoever HighLevel names — likely the Lumina Aura owner as end user *plus* HighLevel's account owner if the carrier record is HighLevel's). I cannot sign for them. | Telnyx rejects a port without it |
| Port-out authorization / account data | HighLevel Support (Lumina Aura owner opens or authorises the case) | Only HighLevel/Twilio hold the carrier record |
| Charges (Telnyx port fee, monthly number rent) | **You (MotionGrove operator)** | Billing approval |
| Submit the port order | **You**, after the items above | Explicit approval required |
| Cutover window | Lumina Aura owner + you | Calls/SMS interrupt briefly |

## 7. MotionGrove readiness (code audit)

* `business_messaging_numbers` ties a number to a `business_messaging_identity` (one Business) with a **unique active/pending phone number** – cross-tenant duplication is impossible, so tenant isolation holds for a ported number.
* `TelnyxProvisioningAdapter` implements **search/purchase, registration, campaign assignment and release only**; there is **no port-in method**, no `ported` provenance, and no inbound hook that adopts a ported number. `PortOutRequestManager` handles MotionGrove customers porting *out*.
* Therefore a ported number cannot be assigned today without new work: (1) a `PortInOrder` record (business, identity, Telnyx order id, status, FOC, checklist); (2) a `porting_order.status_changed` webhook handler (signature-verified, idempotent) that, on completion, creates the `business_messaging_numbers` row as active with `provider_number_reference` = the Telnyx number id and assigns the messaging profile + voice connection; (3) billing rules for a number MotionGrove did not purchase (rent starts at activation; no purchase charge). **Not built** – it needs a contract (billing) decision and a Telnyx key to test.

## 8. A2P 10DLC

* GHL's public API exposes no brand/campaign data → **unknown** whether the number is on a Lumina-owned brand or HighLevel's. Check *GHL → Settings → Phone Numbers / Trust Center → A2P*.
* Telnyx's port docs say nothing about carrying a 10DLC campaign across a port. Plan: register Lumina Aura's own **Brand + Campaign in Telnyx** (Sole Proprietor or Standard depending on EIN; MotionGrove already has `submitRegistration` / `assignMessagingProfileToCampaign`), then link the ported number to the campaign once it lands. **Do not** disconnect the GHL A2P config until Telnyx registration is approved and the port is confirmed.
* Campaign facts to prepare: use case *Mixed / Customer care + marketing*; opt-in = the Check Availability form's two checkboxes (wording is on the live form); STOP/HELP handled by MotionGrove's `InboundOptOutHandler`.

## 9. Destination configuration (to do after port completes)

Messaging profile (SMS webhook → MotionGrove inbound URL, signature-verified), voice connection (TeXML/credential connection → ring the business's forwarding number or MotionGrove softphone), caller-ID = the ported number, number-to-Location assignment via `business_messaging_numbers`. Inbound call routing needs a product decision (ring-through to owner's cell vs. voicemail) — **owner action**.

## 10. Cutover plan

1. T-14 d: HighLevel confirms port-out path + data; Telnyx key available; portability check passes.
2. T-10 d: A2P brand+campaign submitted in Telnyx; messaging profile + connection created (number-less).
3. T-7 d: LOA signed; draft order fulfilled; **approval gate** → submit; FOC date set to a weekday morning.
4. Before FOC: freeze GHL workflow SMS (so nothing is sent mid-port); keep LC Phone active.
5. FOC day: watch `porting_order.status_changed`; on `ported`, attach profile/connection; test inbound call, outbound caller-ID, inbound SMS reply, STOP keyword, outbound SMS to an owner-controlled number.
6. T+3 d: after stable service, cancel/remove the number from LC Phone only with HighLevel's go-ahead.
7. **Rollback**: before FOC the order can be cancelled with Telnyx; after FOC, port back requires a new LOA — so verification in step 5 must pass before decommissioning anything.

## 11. Tracking record

| # | Step | Owner | Status | Date | Evidence |
|---|---|---|---|---|---|
| 1 | Telnyx API key available to the environment | MotionGrove | ☐ | | |
| 2 | Official portability check run | MotionGrove | ☐ | | response saved |
| 3 | HighLevel Support case opened | Lumina owner/MotionGrove | ☐ | | case id |
| 4 | Port-out path + carrier data received | HighLevel | ☐ | | |
| 5 | Legal-name spelling confirmed (Wallace vs Norman-Wallace) | Lumina owner | ☐ | | |
| 6 | A2P status in GHL checked | Lumina owner | ☐ | | |
| 7 | Telnyx brand + campaign submitted | MotionGrove | ☐ | | |
| 8 | LOA signed | Account authority | ☐ | | |
| 9 | Invoice/bill obtained | HighLevel/owner | ☐ | | |
| 10 | Port-in draft created | MotionGrove | ☐ | | order id |
| 11 | **Approval to submit + charges** | You | ☐ | | |
| 12 | Order submitted (`in-process`) | MotionGrove | ☐ | | |
| 13 | FOC date confirmed | Telnyx/carrier | ☐ | | |
| 14 | Number ported; profile + connection attached | MotionGrove | ☐ | | |
| 15 | Post-port call/SMS/STOP tests | MotionGrove + owner | ☐ | | |
| 16 | LC Phone retirement (with HighLevel OK) | Owner | ☐ | | |

Sources: Telnyx [porting getting started](https://developers.telnyx.com/docs/numbers/porting/getting-started.md) · [porting requirements](https://support.telnyx.com/en/articles/6460777-porting-requirements) · HighLevel [Moving Phone Numbers: Migration Guide](https://help.gohighlevel.com/support/solutions/articles/155000006369-moving-phone-numbers-migration-guide) · [How to Submit and Track Port-In Number Requests](https://help.gohighlevel.com/support/solutions/articles/155000008078-how-to-submit-and-track-port-in-number-requests).
