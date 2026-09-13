# CRM Opportunities / Pipelines — Foundation

Status: implemented on `agent/crm-opportunities-pipelines` (base `a6c5f5f`).

A Business-scoped sales pipeline module: a pipeline selector, a kanban board of
deals linked to Contacts, drag-to-move between stages, search and filters,
adding deals, won/lost, per-deal history, and customisation of each pipeline's
stages after it was created from a template.

## 1. Naming — a distinct domain

The repository already has an **Opportunity** concept: the AI COO / Business
Advisor recommendation engine (`App\Models\Opportunity`, `opportunities` and
`opportunity_*` tables, `App\Events\Opportunity\*`, routes
`customer.opportunities.*`, nav label "Advisor"). **None of it is reused, altered
or referenced.**

| Concern | CRM sales domain |
| --- | --- |
| Tables | `crm_pipelines`, `crm_pipeline_stages`, `crm_opportunities`, `crm_opportunity_history` |
| Models | `App\Models\CrmPipeline`, `CrmPipelineStage`, `CrmOpportunity`, `CrmOpportunityHistory` |
| Enums | `App\Enums\Crm\*` |
| Services | `App\Library\Crm\*` |
| Events | `App\Events\Crm\*` |
| Routes | `customer.workspaces.businesses.crm.*` under `/workspaces/{w}/businesses/{b}/opportunities` |

## 2. Model

- **Pipeline** — owned by one Business; ordered; archived, never deleted.
  Template provenance: `template_key`, `template_version`, `template_pipeline_key`.
- **Stage** — `name` (the customer's label) and `semantic_key` (what the product
  means; unique per pipeline). Archived, never deleted.
- **Opportunity** — one contact, one pipeline, one stage; `status`
  (`open`/`won`/`lost`), `contact_status` (`no_contact`/`in_contact`) with
  `contact_status_source`, optional `value_minor` + `currency_code` (captured at
  creation), `source` (`manual` today), `stage_entered_at`, `won_at`/`lost_at`,
  `lost_reason`.
- **History** — append-only: created, stage_changed (stage names copied at the
  time), won, lost, reopened, contact_status_changed; actor.

### Canonical first stage

Every standard pipeline starts with **New inquiry** (`semantic_key =
new_inquiry`, `App\Enums\Crm\CrmStageSemanticKey::NewInquiry`). It can be
renamed, but it always stays first and can never be archived. New deals start
there unless another stage is chosen. Automations, templates and forms must
address it by key, never by name.

### Contact status is not a stage

"No contact / In contact" is a property of the deal, not a column, so
`New inquiry + No contact` and `New inquiry + In contact` are both representable.
It is set by hand today; `contact_status_source` exists so the later automatic
update from canonical inbound conversation activity can respect a manual
correction.

### A Contact is not a Lead

Nothing creates a deal when a contact is created. Deleting a contact keeps its
deals and history (`contact_id` becomes null) and is never blocked by a deal.

## 3. Pipeline customisation (`CrmPipelineService`)

Add, rename, reorder (up/down; full reorder in the service) and archive stages;
rename pipelines; create further pipelines from the standard stages.
Archiving is the safe removal: open deals in the stage must be moved to another
active stage of the same pipeline first (each move is a recorded, announced stage
change); won/lost deals keep the archived stage. A reopened deal whose stage was
archived returns to New inquiry.

## 4. Business Template seam (`App\Library\Crm\Templates`)

- `BusinessTemplate` (key, **version**, pipelines) → `PipelineBlueprint` →
  `StageBlueprint`. Blueprints validate on construction (first stage is
  `new_inquiry`, keys well formed and unique).
- `BusinessTemplateRegistry` (singleton) ships **one** template, `generic`
  v1: "Sales pipeline" — New inquiry, Qualified, Proposal sent, Negotiating.
- `BusinessTemplateApplier::apply()` **copies** configuration into rows the
  Business owns and records provenance. Idempotent per pipeline blueprint; the
  Business row is locked while applying. Changing or re-versioning a template
  affects only later applications, never Businesses that already received it.

Later components join the same seam with the same copy-and-record rule: linked
automation recipes, Forms, website/form settings. No niche templates are
hard-coded yet. A Business gets its first pipeline only when someone presses
**Set up pipeline** (nothing is created by opening the page); applying a template
on Business creation is a later step that calls the same applier.

## 5. Automation events

`App\Events\Crm\CrmOpportunityCreated` (`opportunity_created`),
`CrmOpportunityStageChanged` (`opportunity_stage_changed`),
`CrmOpportunityWon` (`opportunity_won`), `CrmOpportunityLost`
(`opportunity_lost`).

Same shape Automations V2 already consumes (compare
`App\Events\Conversation\InboundMessageReceived`): `ShouldDispatchAfterCommit`,
ids only, explicit `businessId` and `contactId`, stage semantic keys, and a
deterministic `occurrenceKey()` = `crm_opportunity_history:{id}`.

**No V2 trigger type is registered yet.** `WorkflowTriggerType` is V2's closed
contract vocabulary. Wiring one of these is a `TriggerSource` that listens to the
event and calls `EnrollmentService` — exactly how V2-F wired `message_received` —
with no change to the CRM domain.

## 6. HTTP, tenancy, permissions

- Tenancy: `ResolvesBusinessTenancy::resolveEntitledBusinessTenancy()` gated on
  `PlatformFeature::Crm`; every pipeline, stage, deal and contact is then looked
  up inside that Business. Anything else answers 404. The services re-check
  Business ownership and refuse cross-Business attachment.
- Permissions: `view_contact` to see the board and deals; `update_contact` to
  change anything (application convention: a missing permission answers 401).
- View-as: every route carries `businessUid`, so it is classified BusinessScoped.
- Board: GET filters (`pipeline`, `q`, `status`, `contact_status`) update the board
  region in place via `window.AsyncRegion`; drag and drop posts JSON to the move
  endpoint; the deal page's move form is the keyboard/no-JavaScript path.
  Fixed query count (window function caps cards at 50 per column).

## 7. Future flow this preserves

Form submitted → Contact created/updated → lifecycle Lead → source = that form
→ CRM Opportunity created at `new_inquiry` (`source` records the form) →
New-inquiry follow-up automation (trigger on `opportunity_created` /
`opportunity_stage_changed` into `new_inquiry`) → inbound reply sets
`contact_status = in_contact` with source `conversation` and stops no-reply
follow-up → the deal progresses through the Business's own pipeline.

## 8. Not in this slice

Forms; automatic contact-status updates; V2 trigger registration; niche
templates; applying templates on Business creation; the Opportunities
navigation entry (navigation is owned by the concurrent navigation-cleanup lane
and is added once that merges).
