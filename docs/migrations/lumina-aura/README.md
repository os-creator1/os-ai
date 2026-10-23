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

## Status (second pass)

Built, tested and run on an isolated staging database: **GHL import writer** (51 contacts / 37 opportunities / 45 conversations / 733 messages, dry-run, idempotent, event-free), **Lumina Aura Business/Location/staff + Business Pack**, **preview website with a real quote form**, **automation engine additions** (send windows, stop conditions, call tasks, review/questionnaire links) with 8 end-to-end runtime tests, and **port-in support** (tracking, signed webhook, safe assignment, read-only portability check). See 08-staging-status.md for exact counts and 07-owner-actions.md for what still needs a person or a credential.

| File | What it is |
|---|---|
| [01-ghl-inventory.md](01-ghl-inventory.md) | What was exported from GHL, counts, what the API refused |
| [02-automation-reconstruction.md](02-automation-reconstruction.md) | 26 GHL workflows, mapping, engine capabilities added |
| [03-website-rebuild.md](03-website-rebuild.md) | Site audit, content map, preview status, flags |
| [04-phone-port-runbook.md](04-phone-port-runbook.md) | +1 720-502-8306 port: findings, documents, drafts, cutover, tracker, code |
| [05-dns-plan.md](05-dns-plan.md) | Public DNS findings and the cutover/rollback plan |
| [06-validation.md](06-validation.md) | Tests run, with exact results |
| [07-owner-actions.md](07-owner-actions.md) | Every blocker with the exact person who must act |
| [08-staging-status.md](08-staging-status.md) | Staging counts and how to reproduce |
| `website-manifest.json`, `source/` | Asset provenance (no binaries) and the public legal text |

## Code map

| Path | Purpose |
|---|---|
| `app/Library/Migration/Ghl/*` | Read-only client, exporter, planner, **import writer** |
| `app/Library/Migration/Lumina/*` | Provisioner and website builder |
| `app/Console/Commands/{GhlExportSnapshot,GhlPlanImport,LuminaAuraProvision,LuminaAuraBuildWebsite,TelnyxPortabilityCheck,SeedPhotoBoothBusinessPackV3}Command.php` | Entry points |
| `app/Library/NicheBlueprint/PhotoBoothBusinessPackV3.php` + adapter extension | The reusable pack |
| `app/Library/Automation/Workflow/Runtime/BusinessSendWindow.php`, `Conditions/Subjects/BusinessFactSubject.php`, `app/Library/Tasks/*`, `app/Library/Business/BusinessLinks.php` | Engine additions |
| `app/Library/Timeline/Sources/{ImportedHistory,ContactTasks}Source.php` | Conversations display |
| `app/Library/Messaging/PortIn/*`, `TelnyxPortingWebhookController` | Port-in support |
| migrations `2026_11_14_10000{1..4}_*` | import ledger, imported timeline items, contact tasks, port-in tables |
