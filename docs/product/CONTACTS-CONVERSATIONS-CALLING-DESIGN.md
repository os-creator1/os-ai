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

## Honestly unsupported (no safe backend exists today)

* **Call recording** and **call transfer** buttons are rendered *disabled* with the reason in their title. There is no
  recording/consent flow and the call bridge has no transfer operation.
* **Calling-number choice.** `CustomerCallPlacer` places every call from the Business's single primary, voice-assigned
  local number. The popup shows that number ("Calling from …"); no choice is invented. Selecting among several numbers
  needs a placer change plus a per-number authorization rule.
* **Hold** uses the Telnyx WebRTC `hold()`; whether the other party hears music depends on the Telnyx bridge, so the
  popup only says "The call is on hold".
* **The popup does not survive a full page navigation.** The app is server-rendered; WebRTC dies with the page, and
  `pagehide` hangs the call up on purpose so no call keeps running unseen. Persisting a call across pages needs a
  single-page shell or a dedicated always-open calling window.
* **Email archive / mark-unread / delete** and the **rich-text toolbar** of the Email design have no backend (the sender
  takes plain text and the ledger has no archive/unread state), so they are not rendered.
* **Imported-from-GoHighLevel email status** (design example) has no marker in the email ledger; imported history
  appears through the existing imported-history timeline source only.
