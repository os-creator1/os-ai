# BrightFrame Photo Booths — staging sales-demo account

A fully populated, **fictional** demo tenant for `staging.getmotiongrove.com`,
built by one guarded, opt-in, idempotent Artisan command:

```
php artisan demo:provision-brightframe
```

Everything is made up: phones are `(704) 555-01xx`, emails are `@example.com` /
`.example`, the business, people and venues do not exist. It uses the existing
domain managers (workspace, entitlement, CRM, calendar, forms, automations,
website, documents, SEO) rather than raw seeding wherever one exists.

## Safety model

`APP_ENV` is **not** consulted (staging may run `APP_ENV=production`). Instead
`DemoProvisioningGuard` requires all of:

1. `DEMO_PROVISIONING_ENABLED=true` set on that one command line (a real process
   environment variable; a value in `.env` does not count)
2. `APP_URL` host is `staging.getmotiongrove.com`
   (override list: `DEMO_PROVISIONING_ALLOWED_HOSTS`, only if `--inspect` shows a different, verified staging host)
3. `--confirm-database=<name>` equals the connected database exactly
4. the database name is not production-shaped (`prod`, `live`, `main`, `backup`, ...)
5. optional `DEMO_PROVISIONING_FORBIDDEN_DB_HOSTS`

The command also fakes the queue, mail and notifications for its own process and
blocks every outbound HTTP call. It only ever writes inside the demo Workspace /
Business (plus three additive global reference rows noted below). It refuses to
adopt an existing user that does not already own the demo Workspace.

After provisioning, `DemoSafetyAudit` re-proves from the database that the demo
tenant has **no** messaging number/identity, sender, sending server, email
account, stored Google/Meta token, Stripe account able to take charges, custom
domain, notifying booking type, or due rank-check target. The command fails loudly
if any of that is false.

Why nothing can reach a person or a provider:

| Area | How it is held shut |
| --- | --- |
| SMS / email automations | All six workflows are saved as drafts (full triggers and steps visible in the builder), never published, so nothing can enrol or send. A workflow published by an earlier run is paused. The tenant also has no sending path. The safety audit fails if any is published. |
| Booking reminders | Booking types have email/SMS notifications and reminders off; appointments are written as rows (no events). |
| Documents | Built through `DocumentManager`; "sent" only under the faked mail/queue, then the reminder and balance-request sweeps are switched off per document. |
| Stripe | A clearly named simulated connection (`acct_SIMULATED_DEMO`), status `restricted`, charges disabled. Paid history is inserted rows (`pi_SIMULATED_DEMO_*`). |
| Google Business Profile / Google Ads / Meta Ads | Connections are `active` for display but store **no token**, so every sync, refresh, mutation and OAuth call refuses. Names carry `[Demo]` / `SIMULATED`. |
| SEO rank tracking | History is inserted. Targets show "tracking" but `next_check_at` is 5 years out, so the scheduler never calls the paid rank provider. **Do not click "Check now".** |
| Website | Platform path only (`/sites/<public_id>`), no custom domain, no Forge domain provisioning, no AI generation. Images are generated locally (illustrations, not photos). |

Entitlements: a complimentary **Growth** plan, assigned through
`EntitlementManager::assignFirstPlan` against the first platform administrator,
with the reason "Sales demo account". No subscription, card or Stripe object.

Additive global reference rows (created only if missing, never edited): the
`photo_booth_modern` website template (identical to `WebsiteTemplateSeeder`) and
one `seo_rank_locations` row (`9990001`, Charlotte).

## What it creates

Home (derived from the data below), Contacts (24 + custom fields + tags),
CRM / Opportunities (1 pipeline, 18 deals, won/lost), Conversations (10 threads,
message log mirrored so Home/Analytics agree), Calendar (3 booking types, weekly
hours, 16 appointments past and future), Services (5) and Packages (3),
Website (12 pages, logo, hero, 12 gallery images, 3 blog posts, published),
SEO (6 keywords with 9 weeks of simulated rank history, a real audit of the
published site, 11 citations, review link + requests), Google Business Profile,
Google Ads (3 campaigns, keywords, search terms, 60 days of metrics), Meta Ads
(2 campaigns, 60 days), one ad goal, Forms (lead form, planning questionnaire,
post-event survey; **Surveys are Forms** in this product, there is no separate
module), Automations (6 draft workflows, full configuration, none live), Payments & Contracts
(7 documents: draft, sent, signed, paid).

## Running it on staging

The command must exist on the server: deploy this branch to staging first
(merge via the normal ChatGPT-opened PR, or point the staging Forge site at
`demo/brightframe-showcase` temporarily). Then, from your machine:

1. **Look first** (read-only; prints host and database, changes nothing):

   ```
   ssh forge@<SERVER> 'cd /home/forge/motiongrove-staging.on-forge.com/current && php artisan demo:provision-brightframe --inspect'
   ```

   Check `app_host` is `staging.getmotiongrove.com` and note `db_name`.

2. **Provision**, typing the password at a hidden prompt (it never appears in
   shell history, process list, logs or Git; use 14+ characters):

   ```
   ssh -t forge@<SERVER> 'cd /home/forge/motiongrove-staging.on-forge.com/current && read -rsp "Demo password: " DEMO_PW && echo && DEMO_PROVISIONING_ENABLED=true DEMO_PW="$DEMO_PW" php artisan demo:provision-brightframe --confirm-database=<DB_NAME_FROM_STEP_1> --password-env=DEMO_PW'
   ```

   Omit `--password-env=DEMO_PW` (and the read) to let the command generate a
   24-character password and print it once. Login: `demo@brightframe-photobooths.example`
   (change with `--login-email`). Sign-in may require the staging reCAPTCHA.

3. Website preview: `https://staging.getmotiongrove.com/sites/<website_public_id>`
   (the command prints the id).

Re-running is safe and additive. Re-run it **after every deploy**: generated
website images live under `public/images/websites/` and can vanish with a new
release; the command restores them and republishes only if something changed.
To change the password later add `--rotate-password` (with a new `--password-env`).

## Not covered

No Surveys module exists (Forms covers it). The demo has no domain, so organic
rank "matching" shows the product's own "connect a domain" note. Nothing here
removes data; deleting the demo is a separate, deliberate task.

## Tests

```
vendor/bin/phpunit tests/Feature/Demo
```

Run against a disposable `ultimatesms_testing*` database (MySQL 8). 12 tests:
guard refusals (opt-in, host, database confirm, `APP_ENV=production` accepted),
inspect is read-only, full population, idempotent re-run, password rotation,
no touching other businesses / no adopting a foreign user, no sending path, and a
real login that opens every module page.
