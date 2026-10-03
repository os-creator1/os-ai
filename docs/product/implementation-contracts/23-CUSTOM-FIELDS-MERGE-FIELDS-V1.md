# Contract 23 — Business Custom Fields and the canonical Merge Fields (V1)

Status: implemented on `agent/custom-fields-merge-fields-v1`.
Authority: V1 Master Product Blueprint §5 (Custom fields are Business-wide
definitions; values live on the owning entity), §10, §16 (Form and
questionnaire definitions are Business-wide), §25 (Settings → Custom fields).

This contract defines ONE Business-wide custom-field system and ONE merge
vocabulary and engine that every personalised-text surface uses. It is not an
Automations feature.

---------------------------------------------------------------------------

## 1. What existed, what was reused, what is new

| Concern | Before | Now |
|---|---|---|
| Contact identity (first/last name, email, company) | Legacy per-group tag-keyed fields (`contact_group_fields` + `contacts_custom_field`). | **Unchanged and still the only source.** Built-in `{{contact.first_name}}` etc. read it. Nothing is copied into the new tables. |
| Business-wide field definitions | None. | `custom_field_definitions`. |
| Typed field values | Strings keyed by a legacy group field. | `custom_field_values` (typed columns). |
| Merge syntax | `{TAG}` (uppercase group tags) in two copies; lowercase builder chips with **no resolver**; doc-only `{{first_name}}`. | One canonical `{{group.key}}` engine (`App\Library\Merge`). Legacy forms handled only by a compatibility adapter. |
| Settings page | None. | Settings → Custom fields. |
| Form → Contact field | None (answers stayed on the submission). | Explicit `custom_field_uid` mapping per question. |
| If/Else custom-field condition | `contact.custom_field:{id}` (legacy group field id). | `contact.field:{key}`; legacy still evaluated for already-published workflows. |

Reused as-is: `Tag` (definition entity pattern), `LocationAccessGuard`,
`ResolvesBusinessTenancy`, the Forms version/submission pipeline,
`ContactDirectory`, the workflow engine's runtime/compiler/executors, the
`customer.settings._module-header` partial, the design-system components.

## 2. Definition ownership

* A definition belongs to **one Business** (`business_id`, composite-FK target
  `UNIQUE(id, business_id)`), never to a Location, group or Workspace.
* `entity` is `contact` in V1. The column exists so Opportunity / Business /
  Appointment scopes can reuse the table later; nothing else is built for them.
* Values belong to the **Contact** (`custom_field_values.contact_id`). A value
  row carries the Business id and is bound to its definition by the composite
  foreign key `(definition_id, business_id)`; the writer service additionally
  proves the Contact's own Business.
* Business A can never read, write, map or reference Business B's definitions:
  every lookup is `business_id`-scoped and a foreign uid/key is "not found".

## 3. Stable keys

* The key is generated **once**, at creation, from the label
  (`Event Date` → `event_date`; non-ASCII transliterated; a leading digit gets a
  `field_` prefix; max 40 chars) and is **immutable**.
* Collision inside a Business → `event_date_2`, `_3`, … (the unique index
  `UNIQUE(business_id, entity, key)` is the arbiter; a concurrent create retries).
* A key may never shadow a built-in contact merge field (`first_name`,
  `last_name`, `full_name`, `email`, `phone`, `company`).
* Editing the label (or option labels) never changes the key, the type, the
  token, or anything that references the field. Type is chosen at creation and
  never changed (it would reinterpret stored values).
* The merge token is `{{contact.<key>}}`; the resolver maps
  Business + entity + key → definition. A definition id/uid is never in a token.
* Active labels are unique per Business (case-insensitive); an archived label
  frees the name but its key stays taken. Limit: 100 fields per Business.

## 4. Supported types

`text`, `long_text`, `number`, `currency`, `date`, `datetime`, `boolean`
(Yes/No), `select` (Dropdown), `multi_select`, `email`, `phone`.

| Type | Storage column | Canonical value | Notes |
|---|---|---|---|
| text / long_text | `value_text` | string (≤255 / ≤5000) | |
| email / phone | `value_text` | validated string | phone: 5–15 digits |
| number / currency | `value_number` DECIMAL(20,4) | plain decimal string | currency shows `<Business currency> 1,500.50` in merge text |
| date | `value_date` | `Y-m-d` | merge text `14 Jun 2027` |
| datetime | `value_datetime` | `Y-m-d H:i:s` Business wall-clock, no zone conversion | merge text `14 Jun 2027, 6:30 PM` |
| boolean | `value_bool` | bool | merge text `Yes` / `No` |
| select / multi_select | `value_text` / `value_json` | option **id(s)** | merge text shows option labels |

Select options are `[{id, label}]` with stable ids (`opt_xxxxxxxx`). Editing
renames by id; adding creates ids; removing an option makes stored references
render blank (never the raw id). Max 50 options.

Values are validated by one codec (`CustomFieldValueCodec`); nothing is stored
as unvalidated JSON.

## 5. Value writes (one service)

`CustomFieldValueService` is the only writer.

* `set()` — set or **replace**. A blank value is refused here.
* `clear()` — remove the value.
* `applyAnswer()` — Form / Questionnaire answers. **A blank answer is a no-op**
  and never removes an existing value; an unusable (invalid for the type)
  answer is skipped and the old value stays. Clearing from a Form is not a
  supported intent. The answer always remains on the write-once submission.
* `saveForContact()` — the Contact-details form: all-or-nothing; a blank clears;
  a uid that is not an active field of this Business fails closed and nothing is
  written.
* Every verb re-proves Business + entity of definition and Contact.
* An archived definition refuses new writes (`set`, `applyAnswer`).

## 6. Settings → Custom fields

`Settings → Business setup → Custom fields`
(`customer.workspaces.businesses.custom-fields.*`, under
`…/businesses/{businessUid}/settings/custom-fields`).

* Table: Name, Type, Merge field (with Copy), Status, actions.
* Add field (name, type, options for dropdown types); Edit (name, option labels —
  the merge field is shown as fixed); Archive / Restore; Move up/down.
* Permissions reuse `view_contact` (see) and `update_contact` (change); a missing
  permission answers 401, a foreign uid 404. Gated by the CRM entitlement like Tags.

## 7. Contact details

The Contact profile (`people/{contactUid}`) shows a **Custom fields** card with
the Business's active fields as typed controls (date picker, `datetime-local`,
number, Yes/No/Not-set select, dropdown, multi-select checkboxes, email, phone,
text/long text). Archived fields that still hold a value are shown read-only,
labelled archived; archived empty fields are hidden. Saving is
`POST …/people/{contactUid}/custom-fields` → `saveForContact()`.

**Location ACL.** A Contact with no Location is governed by Business access
alone. Otherwise the actor must pass `LocationAccessGuard::userCanAccessLocation`
for the Contact's Location: the custom-fields section is **absent** (values not
rendered) and the save answers **404** for an ungranted Location.

## 8. Forms and Questionnaires → Contact fields

A Questionnaire is a multi-page Form (one definition model), so one mechanism
serves both.

* Each question may carry `custom_field_uid` (the form editor's **Save answer
  to** select). The mapping is stored in the immutable form version; it is **not**
  derived from labels.
* At save time the mapping must name an **active** contact field of the **same
  Business** (foreign/unknown uid → refused with the same words), the answer
  type must be compatible with the field type, two questions may not map to the
  same field, and a Form dropdown's options must each be one of the custom
  dropdown's choices. An archived field can't be **newly** mapped; a version that
  already mapped it keeps the mapping (re-saving the form is allowed) but writes
  stop (§5).
* Compatibility: text → text/long_text/number/currency/email/phone/select;
  textarea → long_text/text; email → email/text; phone → phone/text;
  select → select/multi_select/text; checkbox → boolean; date → date.
* At submission, **after** the Contact is resolved: `Created` and `Matched`
  Contacts have their mapped fields applied (`applyAnswer`), a **matched** Contact
  included — the mapping is the author's explicit instruction. `Ambiguous` or no
  phone → no Contact → **no custom-field write**. Only the mapped field of the
  mapped question is touched: never identity (the existing "matched Contacts are
  never modified" rule still holds for identity), tags, other custom fields or CRM
  data.
* An unchecked checkbox is not an answer (indistinguishable from "not seen"): it
  never overwrites; checked sets Yes.
* Page-to-page questionnaire steps write nothing; the final step applies the
  mapping in the same transaction as the submission, before the
  `FormSubmissionRecorded` event, so automations triggered by the form see the
  values.
* **Date sources.** `{{contact.event_date}}` (or whatever field the form
  mapped) is the canonical reusable date. Other modules (proposal due dates,
  booking, automations) must read the field, never scan form answer text.
* Website lead-form seam: the same `custom_field_uid` mapping on the Form field
  is the seam a Website lead-form configuration should use ("Event date → save to
  `{{contact.event_date}}`"). The Website wizard itself was not modified.

## 9. The canonical merge registry and resolver

One vocabulary: `{{group.key}}`. One engine: `App\Library\Merge\MergeFieldResolver`
over `MergeFieldRegistry`. No aliases (`{{lead_name}}`, `{{client_name}}`, …).

| Group | Tokens | Source |
|---|---|---|
| contact | `first_name`, `last_name`, `full_name`, `email`, `phone`, `company` | built-in; existing identity storage (phone from `contacts.phone`, `+` + digits) |
| contact (custom) | `{{contact.<key>}}` | the Business's Custom Fields |
| business | `name`, `email`, `phone`, `website` | `businesses` |
| location | `name`, `address` | the Contact's own Location (`location.phone` is not offered: Locations have no phone of their own in V1) |
| opportunity | `name`, `value`, `stage` | explicit context only |
| appointment | `start_date`, `start_time`, `timezone` | explicit context only |

`MergeContext` = Business, Contact, Location, Opportunity|null, Appointment|null.
Nothing is looked up on the resolver's behalf: an Opportunity/Appointment token
resolves only when the caller supplied that record, never from a "latest" row.
Appointment time is shown in the Business timezone, converted exactly as the
Calendar does.

### Missing / unknown behaviour (exact)

* Known token, no value (unset field, absent context, no Location) → **blank**,
  recorded in `MergeResult::missing`.
* Unknown token (typo, another Business's key, retired vocabulary, malformed
  `{{ not a token }}`) → **blank**, recorded in `MergeResult::unknown`.
* Anything throwing while reading → blank. Rendering never throws and the raw
  `{{…}}` never reaches a customer.
* `MergeFieldResolver::unknownTokens()` and the picker's inline notice let an
  editor show "Unknown merge field" before saving.
* An automation step whose **entire** rendered message is blank (all-subject or
  all-body missing) is **skipped** (`rendered_content_empty`), not sent.

### Archived custom fields

Still resolve for existing text; are not offered by the picker for new use
(`MergeFieldRegistry::catalog` hides them unless an editor already references
them); stored values are never deleted.

### Security

The Contact, Location, Opportunity and Appointment in a context must belong to
the context's Business; a mismatching record is treated as **absent**. Custom
keys resolve only inside the context Business. The resolver does not perform the
Location ACL itself: callers authorise the Contact (the Contact UI above does;
automations run as the system for a Contact the engine already enrolled).

### Compatibility adapter

`ContactMergeFields` remains but owns no substitution logic beyond the legacy
syntaxes, then delegates `{{…}}` to the resolver:

* `{TAG}` uppercase group-field tags — resolved as before; an unknown `{TAG}` is
  left as written (legacy behaviour).
* `{first_name}`, `{last_name}`, `{company}`, `{business_name}` — the lowercase
  chips the V2 builder used to insert. They never resolved before; they now map
  to the canonical tokens so existing workflows heal.
* `SendMessageAction`'s private duplicate renderer was removed; it calls the
  adapter. (`Tool::renderSMS` — legacy campaign / welcome-SMS — is a different,
  untouched subsystem.)

## 10. The Insert-field picker

One Blade component (`<x-merge-field-picker>`) and one script
(`public/js/merge-fields/insert-field.js`). Grouped Contact / Custom fields /
Business / Location / Opportunity / Appointment; a click inserts the canonical
token at the cursor of the last-used field next to the picker (or its default
target). The caller supplies `MergeFieldRegistry::picker()`; **only groups the
editor can resolve are offered.**

Wired into: Automations Send SMS, Send email (subject or body), Internal
notification, and the Proposal / Contract draft body. In the Automations builder
the Opportunity group appears only for Opportunity triggers and the Appointment
group only for Appointment triggers (re-evaluated when a step is opened, from the
workflow's current trigger).

## 11. Automations

* Send SMS, Send email (subject + body), Internal notification resolve through
  the canonical engine with an `AutomationMergeContextFactory` context: the
  enrolled Contact, its own Location, and — only for Opportunity / Appointment
  triggers — the trigger's Opportunity / Appointment read back from the
  enrollment's persisted occurrence key (same fact the trigger fired with).
* **Test workflow** shows what the customer would read: for Send SMS, Send email
  and Notify-your-team steps the simulator appends a preview rendered by the same
  engine for the chosen test contact (`Subject: “Hi Pat” · Message: “…”`), and
  names any unknown merge field ("will be left blank"). Nothing is sent. Test runs
  have no trigger event, so Opportunity / Appointment tokens preview blank.
* Conditions: `contact.field:{key}` with type-aware operators:

  | Field type | Operators |
  |---|---|
  | text, email, phone | is / is not / contains / does not contain / is empty / is not empty |
  | number, currency | is / is not / greater than / less than / is empty / is not empty |
  | date, datetime | before / after / on / is empty / is not empty |
  | boolean | is Yes / is No |
  | dropdown | is / is not (operand = option id) / is empty / is not empty |
  | multi-select | includes / does not include (option id) / is empty / is not empty |

  The compiler refuses an unknown key, another Business's key, an operator that
  doesn't fit the type and an ill-fitting operand. There is no expression
  language. An unset field reads as empty (a Yes/No field that is unset is
  neither Yes nor No).
* **Backward compatibility.** `contact.custom_field:{id}` (legacy group field)
  is still evaluated and compiled so already-published versions keep working; the
  Builder no longer offers it for NEW conditions and only keeps showing a legacy
  field a condition already uses. Identity conditions (`contact.first_name`, …)
  are unchanged.
* Archived field in a condition: still evaluates; the workflow can still be saved.

## 12. Proposals / Contracts

The draft body editor offers the same picker. Tokens in the document title and
body are resolved **at send**, by the one engine, against the document's Business,
Location, Contact and linked Opportunity, and the **result is what is frozen and
hashed** — a signed document never re-resolves against later Contact edits. A
title that resolves to nothing falls back to the document kind. No
document-specific syntax exists.

## 13. Not in V1 (deliberate)

* Opportunity / Business / Appointment custom fields (the `entity` column is the seam).
* An "Update contact field" automation step writing Business custom fields
  (it still writes legacy group fields).
* Merge in manual Business-Email sends, booking confirmations and legacy
  campaign / welcome SMS (no template layer exists there yet; they can adopt
  `MergeFieldResolver` with an explicit `MergeContext`).
* Migrating legacy group custom fields into Business-wide definitions.
* `{{location.phone}}` (no Location phone column).
