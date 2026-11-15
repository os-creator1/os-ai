# Contacts, Conversations and Calling — approved design implementation

Route: explicitly human-authorized manual lane (see `CLAUDE.md`, route 3). Branch
`feature/contacts-conversations-calling-exact-design`. No pull request is opened here.

## What exists

| Surface | Where |
|---|---|
| Contacts page (table, tabs, search, group filter, pagination) | `resources/views/customer/people/index.blade.php`, `public/css/crm/mgx-contacts.css` |
| New-contact modal + country picker | `people/_new-contact-modal.blade.php`, `public/js/crm/mgx-contact-modal.js`, `ContactDirectoryController@store` / `@newContactOptions`, `Library\Contacts\CountryDirectory` |
| Built-in canonical `EMAIL` identity | `Library\Contacts\ContactEmailIdentity`, `ContactGroups::createDefaultFields()`, `ContactDirectoryController@updateEmail` |
| Contact profile Send Email / Connect Email / Add Email | `people/show.blade.php`, `ContactDirectoryController::emailActions()` |
| Conversations → Email composer from a contact | `ChatBoxController@emailCompose` (`conversations/email-compose`), deep link `?channel=email&compose=<contact uid>` |
| Conversations design (list, thread, email cards, composer, contact panel) | `public/css/crm/mgx-conversations.css`, `ChatBox/partials/*` |
| Call popup: keypad (DTMF), hold, note, wrap-up | `public/js/crm/mg-softphone.js`, `people/_softphone.blade.php`, `VoiceCallController@wrapup` |

## Rules this work keeps

* The email of a contact is the value of the group's field tagged `EMAIL`. Nothing else is authoritative: a custom
  field that is merely *called* "Email" is ignored by the resolver, the sender and Automations.
  An existing group without the field gets it on demand (`ensureField`, idempotent, never touches existing data).
  Several different stored addresses are reported as ambiguous and never guessed.
* Sending uses the existing `BusinessEmailSender` through the existing `businesses.email.send` route (tenancy,
  permissions, idempotency token and Location attribution unchanged). The composer says exactly why Email is
  unavailable (no address / ambiguous / mailbox not connected) with the one fixing action, and never falls back to SMS.
* "Accepted" is shown as accepted by the provider, never as delivered.
* Phone numbers are validated with libphonenumber against the **selected country**; stored form is unchanged
  (digits with country code, no `+`).

## Call continuity across navigation (implemented)

`App\Http\Middleware\SoftphoneFrame` answers an eligible user's top-level page request (Business-scoped page, browser
calling available, `Sec-Fetch-Dest: document`) with `customer/softphone-shell.blade.php`. The shell owns the softphone, the
Telnyx client and the call; the real page runs in a full-viewport same-origin frame. All in-app navigation happens inside
the frame, so the call, its timer and the floating popup are never unloaded. Pages report their address/title to the shell
(`public/js/crm/mg-frame-bridge.js`) so the address bar and tab title stay truthful and Back/Forward work. A framed response
that leaves the app (OAuth redirect, login after session expiry) breaks out to the top level. Requests that are not
top-level navigations, users who cannot call, and browsers that send no fetch metadata are served exactly as before.

Limits that remain true: reloading or closing the **shell tab** ends the call (the audio really is destroyed; `pagehide`
still hangs the call up on purpose); a redirect that leaves business-scoped pages (e.g. to `/dashboard`) is shown at the top
level without a shell, so a live call would end there; a second browser tab is its own shell.

## Outbound calling-number selection (implemented)

`CustomerCallPlacer::eligibleNumbers()` lists the Business's numbers that are active, local, provider-backed and confirmed on
the configured Voice Connection (reached only through the Business's own messaging identity). `GET .../calls/numbers`
returns opaque, business-bound tokens (same tenancy, CRM entitlement and calling permission as placing a call).
`POST .../calls` accepts `from`; an unknown, foreign or no-longer-eligible token is refused (`number_not_available`, 422) and
nothing is dialled. With one eligible number the popup dials at once; with several it shows a "Call from" step first.
Without `from` the primary number is used, as before.

## Measured fidelity (authenticated app vs the supplied HTML, same 1440x900 viewport)

Element boxes were measured with `getBoundingClientRect()` in both renderings, not judged by eye:

* **Add Contact modal:** dialog 540x616 at (450,84) in both; every label, input, button and the footer land on the same
  coordinates (checked field by field).
* **Contacts list:** heading, sub-line, tabs (46), search (46), group filter (119x46), header row (42), rows (63), avatars (36),
  name/email text (17/15), pills (24), row buttons (34) and footer (44) have identical sizes. The page sits 6px lower and
  the sidebar is 4px narrower because the application's own shell frames it.
* **Conversations (Email and SMS):** list column order (segmented control, search, tabs), tab/search/row sizes (rows 81/64),
  the header (65), the 300px contact panel running the full height beside it, panel avatar/name positions all match to 1-2px.
  The Email composer card height matches (224).
* **Call popup** (re-measured after the final CSS, width 340 in every state; minimized pill 279x56). Heights, ours / design:
  incoming 167/167, calling 278/276, on call 382/405, keypad 431/421, hold 411/409, note 442/440, ended 390/397.
  On call and ended are shorter because the design's Recording banner and "Recording saved" line are unsupported (no
  recording or consent flow); keypad is 10px taller because of the "Calling from" disclosure kept by request.
* **Audio output chooser** was exercised in Chromium three ways: without `setSinkId` (button disabled with a reason),
  with it (device list, selection calls `setSinkId`, list closes), and with a rejecting `setSinkId` (the message stays
  visible and the previous device stays selected). The call popup's Telnyx SDK and server are simulated in that harness.

## Evidence

`docs/design-acceptance/` holds the authenticated-browser screenshots and the reference-vs-production comparisons. See
`tests/e2e/acceptance/README.md` for the scripts that produced them and what each proves.

## Honestly unsupported (no safe backend exists today)

* **Call recording** and **call transfer** buttons are rendered *disabled* with the reason in their title.
* **Hold** uses the Telnyx WebRTC `hold()`; whether the other party hears music depends on the Telnyx bridge, so the
  popup only says "The call is on hold".
* **A live Telnyx call has not been exercised**: the browser architecture is proven against the real app with the Telnyx
  SDK and calls API answered by the test; a supervised call with a real Telnyx connection is still required.
* **Email attach and template toolbar buttons** of the reference are not rendered (no attachment or email-template backend).
* **Call recording** has no recording or consent flow, so it stays disabled; the popup's **Audio output** chooser is real
  but only appears where the browser supports `setSinkId`.
* **Imported-from-GoHighLevel email status** (design example) has no marker in the email ledger; imported history
  appears through the existing imported-history timeline source only.
* **Contact profile** has no supplied mockup; it adopts the Contacts design language.

## Email thread actions, rich text, rename, last activity

* **Archive / Mark unread / Delete** (Email header) persist on `business_email_messages` (`archived_at`,
  `marked_unread_at`, `deleted_at`), are workspace/location scoped, and never touch the connected mailbox. A thread is
  archived only while its newest message is, so a new reply resurfaces it; opening a thread clears "unread".
* **Rich-text toolbar** (bold / italic / link) is shown only when the provider can send HTML. The browser sends visible
  markup; `SafeEmailMarkup` escapes everything and emits only `strong`, `em`, `a` and `br`, plus a plain-text
  alternative. Google sends multipart/alternative, Microsoft Graph sends HTML, IMAP stays plain text.
* **SMS Rename** renames the linked contact (first/last name); it needs `update_contact`, and unlinked conversations are
  refused. The SMS/Email switcher is a small pill beside the composer.
* **Last Activity** (Contacts list) is the latest of SMS, email, call and chat, labelled Texted / Emailed / Called, from
  four grouped queries (no N+1).
* Verified here with fakes and the real app in a browser; a real provider send was not made (a real reply attempt
  correctly returned the expired-connection error).
