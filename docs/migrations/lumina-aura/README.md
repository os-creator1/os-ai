# Lumina Aura Events — GoHighLevel → MotionGrove migration

First complete GHL → MotionGrove customer migration. Pilot customer: **Lumina Aura Events**
(Thornton, CO · America/Denver · Denver metro + Colorado Springs · 360° video booth).

Nothing in this directory authorises any live action. **No live DNS change, no port order
submission, no message sent, no workflow activated, no production write** has been made.
Personal data (51 contacts, 733 messages, 37 opportunities) was exported to a git-ignored
location and is **not** in this repository.

| File | What it is |
|---|---|
| [01-ghl-inventory.md](01-ghl-inventory.md) | What was exported from GHL, counts, what the API refused |
| [02-automation-reconstruction.md](02-automation-reconstruction.md) | All 26 GHL workflows, mapping to MotionGrove, improvements, gaps |
| [03-website-rebuild.md](03-website-rebuild.md) | Site audit, content map, assets, missing facts |
| [04-phone-port-runbook.md](04-phone-port-runbook.md) | +1 720-502-8306 port: findings, documents, drafts, cutover, tracker |
| [05-dns-plan.md](05-dns-plan.md) | Public DNS findings and the cutover/rollback plan |
| [06-validation.md](06-validation.md) | Tests run, with exact results |
| [07-owner-actions.md](07-owner-actions.md) | Every blocker with the exact person who must act |
| `source/` | Public legal-page text captured from the live site (for review before reuse) |

## Code added

| Path | Purpose |
|---|---|
| `app/Library/Migration/Ghl/GhlApiClient.php` | Read-only (GET-only) GHL API V2 client; 429/5xx backoff |
| `app/Library/Migration/Ghl/GhlSnapshotExporter.php` | Paginated export; reports denied datasets as UNVERIFIED |
| `app/Library/Migration/Ghl/GhlImportPlanner.php` | Pure dry-run mapper: contacts, consent, pipelines, deals, history |
| `app/Console/Commands/GhlExportSnapshotCommand.php` | `php artisan ghl:export-snapshot` |
| `app/Console/Commands/GhlPlanImportCommand.php` | `php artisan ghl:plan-import <dir>` (dry run) |
| `app/Library/NicheBlueprint/PhotoBoothBusinessPackV3.php` | Business Pack v3 (pipelines, fields, tags, form, 11 draft workflows) |
| `app/Console/Commands/SeedPhotoBoothBusinessPackV3Command.php` | `php artisan blueprint:seed-photo-booth-v3` |
| `AutomationWorkflowComponentAdapter` (extended) | stage/questionnaire/payment/lost triggers; reply, consent and tag guards |
| `config/ghl_migration.php` | `GHL_PRIVATE_INTEGRATION_TOKEN`, `GHL_LOCATION_ID` |

## What is deliberately NOT built yet

* The **database writer** that applies an import plan (contacts → legacy `contacts` +
  custom-field tables, deals via `CrmOpportunityService`, history via
  `ConversationHistoryWriter`-style rows). The planner defines its exact input and the
  invariants (idempotent by GHL id, historical-only, never resent, no automation triggers).
  The MotionGrove contact model is the legacy phone-keyed schema with per-field value
  tables and Business/Location locking; writing to it without a provisioned staging
  Business to validate against would be guesswork. See 07-owner-actions.md.
* Port-in support in the Telnyx adapter (it only purchases numbers today).
* Business-hours / quiet-hours workflow conditions (engine has none).
