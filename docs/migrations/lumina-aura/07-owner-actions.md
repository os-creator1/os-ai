# 07 · Remaining blockers and exact owner actions

| # | Blocker | Who | Action |
|---|---|---|---|
| 1 | No Telnyx credential in the environment → portability check not run | MotionGrove operator | Add secret `TELNYX_API_KEY` (read scope sufficient for the check; porting-order write scope later) to the execution environment. Never paste in chat. |
| 2 | GHL token is location-scoped; no workflow definitions via API; A2P not exposed; no invoice scope | MotionGrove operator | (a) Provide the 5 *New Inquiry Received* screenshots (and any others) in the session, **or** (b) authorise a browser session to the GHL UI for read-only workflow inspection. Optionally add invoices read scope to the Private Integration. |
| 3 | No staging Business for Lumina Aura; DB writer for the import not built | MotionGrove operator | Approve provisioning of the staging Business/Location (`America/Denver`) on staging, and a staging database. With that, the writer can be built and validated against real constraints. |
| 4 | Pricing ambiguity, phone discrepancy, FAQ contradictions, logo, testimonials | Lumina Aura owner | Answer the "Facts flagged" table in 03. Supply the logo and any reviews they own. |
| 5 | Legal name for LOA (Wallace vs Norman-Wallace) and who holds the carrier account | Lumina Aura owner + HighLevel | See 04 §1/§4. |
| 6 | HighLevel Support case | Lumina Aura owner (or you, with their authority) | Send the draft in 04 §4 (not sent). |
| 7 | A2P status in GHL | Lumina Aura owner | Check GHL → Settings → Trust Center / Phone Numbers and report brand + campaign status. |
| 8 | Port approvals | You | Charges + submission approval (04 §6). |
| 9 | DNS access | Lumina Aura owner | See 05. |
| 10 | Business-hours / quiet-hours conditions, call-task node, review/questionnaire merge links, port-in support | Product (needs contracts) | Not built; see 02 and 04 §7. |
| 11 | Staff access | You | Three GHL admins exist (names in the export). Confirm who gets MotionGrove accounts/roles; invitations are not sent automatically. |
