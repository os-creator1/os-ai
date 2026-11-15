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

## Evidence

`docs/design-acceptance/` holds the authenticated-browser screenshots and the reference-vs-production comparisons. See
`tests/e2e/acceptance/README.md` for the scripts that produced them and what each proves.

## Honestly unsupported (no safe backend exists today)

* **Call recording** and **call transfer** buttons are rendered *disabled* with the reason in their title.
* **Hold** uses the Telnyx WebRTC `hold()`; whether the other party hears music depends on the Telnyx bridge, so the
  popup only says "The call is on hold".
* **A live Telnyx call has not been exercised**: the browser architecture is proven against the real app with the Telnyx
  SDK and calls API answered by the test; a supervised call with a real Telnyx connection is still required.
* **Email archive / mark-unread / delete** and the **rich-text toolbar** of the Email design have no backend (the sender
  takes plain text and the ledger has no archive/unread state), so they are not rendered.
* **Conversation rename** (SMS header) has no backend and is not rendered.
* **Imported-from-GoHighLevel email status** (design example) has no marker in the email ledger; imported history
  appears through the existing imported-history timeline source only.
* **Contact profile** has no supplied mockup; it adopts the Contacts design language.
