# 09 · Production deployment — exact procedure for the existing Forge site `getmotiongrove.com`

**Status: NOT deployed. The Lumina Aura production account does not exist until you run steps 5–8 and step 9 shows all PASS.**
Everything below was written against this repository's own scripts (`deploy/forge/production/*`, `docs/product/FORGE-PRODUCTION.md`) and the commands were exercised on a disposable copy; none has run against production.

Nothing here touches DNS, LC Phone routing, GHL (read-only export only), Stripe, or sends any message.

Conventions: `SITE=/home/forge/getmotiongrove.com`, user `forge`, PHP `php8.3`, production branch `main`.

---
## What this release changes (review before merging)
| | |
|---|---|
| **Migrations (additive `create table`/`add column`, nothing dropped or altered)** | `external_import_records`, `imported_timeline_items`, `contact_tasks`, `messaging_port_in_orders`, `messaging_port_in_events`, and nullable `websites.custom_redirects`. Rollback = Forge rollback of the code (tables stay, unused). |
| **New behaviour visible to existing customers** | Opportunities board gains a **Tasks** link and page; the Conversations timeline can show imported history/tasks (only for Businesses that have them); website builder can render a `video` section and honour owner redirects. None of these change an existing account's data or pages. |
| **Engine** | New opt-in workflow options (send-window wait, business facts, link merge fields, "continue on failure"). **No existing workflow uses them**; existing workflows behave exactly as before. |
| **Public endpoint** | `POST /webhooks/telnyx-porting`: inert unless `TELNYX_WEBHOOK_PUBLIC_KEY` is set; signature-verified; idempotent. |
| **Shared-state check** | `main` ↔ this branch merge cleanly (no conflicts). Videos are stored under `public/images/websites/…`, which `release-build.sh` already persists across releases. |

---
## Step 1 — Merge (you)
GitHub → PR `agent/lumina-aura-import-site-automations` → `main` → review → merge. Note the merge commit SHA. **Do not push anything else to `main` until step 4 finishes** (Forge deploys the branch head).

## Step 2 — Backup (SSH as `forge`)
```bash
ssh forge@<your production server>
umask 077; mkdir -p ~/backups
eval "$(php8.3 -r '$e=parse_ini_file("/home/forge/getmotiongrove.com/.env",false,INI_SCANNER_RAW); foreach(["DB_HOST","DB_PORT","DB_DATABASE","DB_USERNAME","DB_PASSWORD"] as $k){echo "export $k=".escapeshellarg(trim($e[$k]??"","\"\x27")).";\n";}')"
echo "Target database: $DB_DATABASE"      # must be your PRODUCTION database
export BACKUP_REF="$HOME/backups/motiongrove-pre-lumina-$(date +%Y%m%d-%H%M%S).sql.gz"
MYSQL_PWD="$DB_PASSWORD" mysqldump -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" --single-transaction --quick --routines --triggers "$DB_DATABASE" | gzip > "$BACKUP_REF"
gzip -t "$BACKUP_REF" && ls -l "$BACKUP_REF" && zcat "$BACKUP_REF" | grep -c '^CREATE TABLE'
```
It must print a non-trivial size and a table count in the hundreds. Copy the file off the server (e.g. `scp`) if you keep off-box backups. `BACKUP_REF` is reused in step 8; keep this SSH session open (or re-run the `eval` + `export BACKUP_REF=<file>` later).

## Step 3 — Deploy (Forge)
1. Optional pre-check, from any checkout of `main`: `bash deploy/forge/production/preflight.sh <saved copy of the production .env> /home/forge/getmotiongrove.com main` (read-only).
2. Forge → site `getmotiongrove.com` → **Deploy Now** (Quick Deploy stays OFF).
3. The log must show, in order: `preflight: all production guards passed.` → composer install → caches → migrations → `Release <id> built; ready to activate.` → activation. A `Refusing to deploy to production:` / `REFUSED:` line means nothing changed and the old release is still live — fix the named cause, do not force.
4. From your own machine: `bash deploy/forge/production/post-deploy-check.sh https://getmotiongrove.com closed` (use `open` instead of `closed` only if registration is currently open).
5. On the server, confirm the six migrations ran and nothing is pending:
   ```bash
   cd /home/forge/getmotiongrove.com/current
   php8.3 artisan migrate:status | grep -E "2026_11_1[45]_"
   php8.3 artisan migrate:status | grep -c Pending      # expect 0
   ```
6. Log in to `https://getmotiongrove.com` as yourself and open any existing customer account: it must load exactly as before. If anything is wrong: Forge → **Deployments → Rollback**.

## Step 4 — Stage the data privately (SSH, still `forge`)
Personal data (51 people, 733 messages) goes to a **private directory outside the web root, never into git**.
```bash
cd /home/forge/getmotiongrove.com/current
umask 077; mkdir -p ~/private/lumina
# 4a. The customer's images + videos: public URLs, every file kept only if its SHA-256 matches the manifest (expect "Verified 21 of 21").
php8.3 artisan lumina:fetch-assets --out="$HOME/private/lumina/assets"
# 4b. The GHL snapshot, read-only. Paste your GHL Private Integration token at the hidden prompt: it lives only in this shell, never in Forge or a file.
read -rs -p "GHL Private Integration token: " GHL_PRIVATE_INTEGRATION_TOKEN; echo; export GHL_PRIVATE_INTEGRATION_TOKEN
php8.3 artisan ghl:export-snapshot --location=7HLIWryaYyed99beyIB7 --out="$HOME/private/lumina/snapshot"
unset GHL_PRIVATE_INTEGRATION_TOKEN
```
`ghl:export-snapshot` prints one table; expect rows: contacts **51**, opportunities **37**, conversations **45**, messages **733**, workflows 26. (`workflows` shows 26 names only — GHL gives no definitions.) The token needs read scopes for contacts, opportunities, conversations, locations, custom fields, tags, users, calendars, forms/surveys, workflows, media. If a dataset prints `UNVERIFIED`, the token lacks that scope: re-issue it, do not proceed with partial data.

## Step 5 — Dry-run (writes nothing)
Replace `<YOUR_PRODUCTION_LOGIN_EMAIL>` with the email you sign in to getmotiongrove.com with (the account that will own Lumina Aura). It must already exist; the tool never creates users.
```bash
cd /home/forge/getmotiongrove.com/current
export OWNER_EMAIL='<YOUR_PRODUCTION_LOGIN_EMAIL>'
LUMINA_PRODUCTION_PROVISIONING=true php8.3 artisan lumina:production-provision \
  --owner-email="$OWNER_EMAIL" \
  --confirm-database="$DB_DATABASE" \
  --backup-reference="$BACKUP_REF" \
  --confirm-phrase='CREATE LUMINA AURA IN PRODUCTION' \
  --snapshot="$HOME/private/lumina/snapshot"
```
Expected output: `Target: getmotiongrove.com / database <prod> / owner #<id>`, `Lumina Aura does not exist yet…`, then
`PLAN counts : {"contacts":51,"contacts_with_phone":47,"contacts_suppressed":1,"opportunities":37,"conversations":45,"messages_total":733,…}`, `PLAN issues : {"contact_no_phone":4,"currency_missing_assumed_usd":37,"dnd_ambiguous_treated_as_suppressed":1}` and `Dry inspection only — nothing was written.`
Any `Refusing:` line is a failed safety check: read it, fix it, never bypass.

## Step 6 — Apply (the only write)
```bash
LUMINA_PRODUCTION_PROVISIONING=true php8.3 artisan lumina:production-provision \
  --owner-email="$OWNER_EMAIL" \
  --confirm-database="$DB_DATABASE" \
  --backup-reference="$BACKUP_REF" \
  --confirm-phrase='CREATE LUMINA AURA IN PRODUCTION' \
  --snapshot="$HOME/private/lumina/snapshot" \
  --assets="$HOME/private/lumina/assets" \
  --apply
```
Add `--no-plan` if you do NOT want the complimentary Growth plan assigned (without any plan the Business has no feature entitlements, so most modules stay hidden until you assign one). The plan creates no Stripe object and no charge.

What it does / guarantees (all enforced in code and tested): creates **one** new Workspace/Business/Location owned by your existing account; **never creates, edits or deletes a user**, never touches your login/profile; inserts only (idempotent — re-running adds nothing); installs the Photo Booth pack with all 11 workflows as **drafts**; imports contacts/opportunities/conversations/733 records as read-only history; builds and publishes the website to the **platform preview path only**; fakes queue/mail/notifications and blocks all outbound HTTP for its own process; refuses to finish if the safety audit finds any messaging number, sender, mailbox, provider token, domain or published workflow.

Expected last lines: `Safety audit clean…`, `IMPORTED: {"contacts_created":51,…"opportunities_created":37,"conversations_created":45,…}`, and `website public_id <uuid>`, `website pages 9`, `website assets 23`.
**If it fails part-way:** do not delete anything. Re-run the identical command (it resumes by source id). If it still fails, send me the output.

## Step 7 — Clean up the private copies (after step 8 passes)
```bash
rm -rf ~/private/lumina/snapshot ~/private/lumina/assets   # the data now lives in the database
```
Keep the backup file until you are satisfied.

## Step 8 — Verify (read-only)
```bash
cd /home/forge/getmotiongrove.com/current
MYSQL_PWD="$DB_PASSWORD" mysql -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" "$DB_DATABASE" -t < docs/migrations/lumina-aura/verify-lumina.sql
```
**All 31 rows must read PASS** (51 contacts · 37 opportunities (10/7/20) · 45 conversations · 733 records · 866 ledger rows · 11 draft / 0 published workflows · 0 enrollments · 0 messaging operations/numbers · 2 forms · 9 pages · 3 videos · no domain). It also prints the owner login and the preview URL `https://getmotiongrove.com/sites/<public_id>`.

Then in the browser (signed in as yourself):
1. Account selector → **Lumina Aura Events** (Workspace of the same name).
2. **Contacts** → 51 people (4 email-only show a non-dialable "np-…" number — expected).
3. **Opportunities** → Inquiries and Bookings boards; 37 deals; **Tasks** link opens the call-task inbox (empty until a workflow runs).
4. **Conversations** → 45 threads; open one: bubbles for SMS, "Imported from GoHighLevel · Email" for email, call/note/activity rows, "Originally: failed" on undelivered ones; no retry/send buttons on imported items; nothing marked unread.
5. **Automations** → 11 workflows, all **Draft**, each opens in the builder.
6. **Forms** → "Check Availability" and "Event questionnaire".
7. Preview site (URL from step 8): home, 360 Video Booth (3 videos play), Backdrops, Gallery, FAQ, Check availability, 3 legal pages; resize to phone width. `…/Check-availability`, `…/brochure`, `…/thank-you` redirect (301).
8. Form test: submit the Check Availability form with a clearly fake contact (name `ZZ TEST – delete me`, phone `(303) 555-0100`, email `zz-test@example.invalid`, tick **neither** consent box). It must create one contact and one opportunity in Inquiries → New Lead; nothing is sent. Remove it afterwards from the Contacts screen (delete that one contact) — it is the only record you should ever delete here.
9. Open any other customer account and confirm it is unchanged and shows no Lumina data.

---
## Secrets & configuration
**Nothing new must be added to Forge for the CRM + website launch.** The production `.env` must already satisfy the existing preflight (the guarded variables in `docs/product/production-getmotiongrove.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://getmotiongrove.com`, `APP_KEY`, `DB_*` (non-test database, non-root user), `QUEUE_CONNECTION=database`, `CACHE_DRIVER`, `SESSION_SECURE_COOKIE=true`, `ACCOUNT_CAN_REGISTER`, a real `MAIL_*`/`MAIL_FROM_ADDRESS`, `STRIPE_MODE` with matching keys, `GOOGLE_ADS_DRIVER`/`META_ADS_DRIVER` not `fake`). The scheduler and queue worker (FORGE-PRODUCTION §2.8–2.9) must already run, because the workflow engine and the website publisher rely on them.

| Needed when | Secret | Where | Needed now? |
|---|---|---|---|
| Step 4b only | GHL Private Integration token (read scopes) | typed at a hidden prompt in your SSH shell, never saved | **Yes, transiently** |
| Customer outbound email | `MAIL_*` already required by preflight | Forge env (exists) | No new value |
| Port-in webhook + portability check | `TELNYX_API_KEY` (read) and `TELNYX_WEBHOOK_PUBLIC_KEY` | Forge env | **Optional**, only for the phone port |
| Sending SMS/calls | a Telnyx messaging profile/number for Lumina Aura | Telnyx + app | **Not for launch** (outbound stays disabled) |
| Review link / questionnaire link in messages | saved in the product (SEO → Reviews; publish the "Event questionnaire" form) | app UI | Optional, before activating workflows |
| Billing | Stripe live keys | Forge env (exists) | Not touched by this work |

## Not authorised / not done by any step above
Publishing any workflow; sending any message; connecting a mailbox or number; changing DNS for `luminaauraevents.com`; submitting or authorising the LC Phone port; releasing LC Phone; any deletion except the one test contact in step 8.8.
