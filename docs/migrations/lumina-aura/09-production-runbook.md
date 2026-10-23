# 09 · Production: exact steps (deploy is manual, from Forge)

**Status: NOT deployed. No production account exists yet.** The authoring session had no Forge, SSH, production-database or deploy credential, and `docs/product/FORGE-PRODUCTION.md` makes production deploys a manual act of a human from Forge on an approved commit of `main`. Per the repository rules the human (not the authoring agent) opens/merges the PR. Nothing below was run against production.

## What changes in production
* **Schema** (additive `create table` only, nothing altered or dropped): `external_import_records`, `imported_timeline_items`, `contact_tasks`, `messaging_port_in_orders`, `messaging_port_in_events` (migrations `2026_11_14_10000{1..4}_*`). Rolling back = drop those five tables; no existing table is touched.
* **Behaviour**: the Conversations timeline gains two read-only sources (imported history, contact tasks); the workflow engine gains opt-in wait mode / conditions / `FailurePolicy::Continue` that no existing workflow uses; two merge fields; a new public webhook `POST /webhooks/telnyx-porting` (inert until `TELNYX_WEBHOOK_PUBLIC_KEY` is set). Existing customers' behaviour is unchanged.

## Sequence
1. Open the PR `agent/lumina-aura-import-site-automations` → `main`; review; merge (human).
2. Take and verify a database backup; note its reference.
3. Forge → `getmotiongrove.com` → run `deploy/forge/production/preflight.sh` by hand, then Deploy (the production scripts refuse staging, a test-shaped DB, `APP_DEBUG`, mixed Stripe modes, etc.). Run `post-deploy-check.sh`.
4. Put the GHL snapshot on the server **outside the web root and outside git** (it holds 51 people's data): either `GHL_PRIVATE_INTEGRATION_TOKEN` + `GHL_LOCATION_ID=7HLIWryaYyed99beyIB7` as server env and `php artisan ghl:export-snapshot --out=/home/forge/private/lumina`, or copy the previously exported directory over SSH. Put the customer's original images in `/home/forge/private/lumina-assets` (the sha256 manifest is `website-manifest.json`).
5. Inspect (writes nothing):
   ```
   LUMINA_PRODUCTION_PROVISIONING=true php artisan lumina:production-provision \
     --owner-email=<your existing production login> --confirm-database=<exact prod db> \
     --backup-reference=<backup id> --confirm-phrase="CREATE LUMINA AURA IN PRODUCTION"
   ```
6. Apply (same command plus `--apply --snapshot=/home/forge/private/lumina --assets=/home/forge/private/lumina-assets`; add `--no-plan` if you do not want the complimentary plan). It is additive, idempotent, creates no user, never edits the owner's login/profile, sends nothing, starts no billing, publishes no workflow.
7. Verify as in `08-staging-status.md` (expected: 51 / 37 / 45 / 733, 11 draft workflows, 0 enrollments, 0 messaging numbers) and through the UI: select Lumina Aura, Contacts, Pipelines, Conversations, Automations, and the preview `/sites/<public_id>`.

## Safety gate (code: `LuminaProductionGuard`, tested)
Refuses unless: `LUMINA_PRODUCTION_PROVISIONING=true` on the command line · `APP_ENV=production` · `APP_URL` host exactly `getmotiongrove.com` (staging refused) · `--confirm-database` equals the connected DB and the name is not test/staging/local/dev/demo/sandbox-shaped · a backup reference · the exact phrase · the owner user already exists.
