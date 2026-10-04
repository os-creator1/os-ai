# Fresh install

One command takes an empty MySQL database to a working Business OS instance.

```
php artisan platform:install
```

It runs, in this order (the order is the part that used to be undocumented):

1. `migrate --force` — schema, plus the seeded Workspace plan catalog (a migration).
2. `db:seed --class=UserSeeder` — creates the `administrator` role the owner command needs.
3. `platform:create-owner` — the first Platform Owner. **Interactive**: the password is
   only ever typed at a concealed prompt, never passed as an argument. Currency seeding
   needs this first user to exist, which is why the owner comes before the full seed.
4. `db:seed --force` — config, countries, languages, currencies, email templates, plans,
   theme presets, website templates, question packs, the Photo Booth questionnaire.
5. `blueprint:seed-photo-booth` — publishes the niche Blueprint (as the owner).
6. `documents:seed-photo-booth-templates` — the four proposal/contract templates.
7. `blueprint:seed-photo-booth-v2` — the Photo Booth niche configuration (CRM, forms,
   automations, website, SEO, citations, calendar, packages); see `NICHE-BLUEPRINT-V2.md`.

Every step is idempotent; re-running `platform:install` is safe and stops with the
failing command named if one step fails.

## Options

- `--owner-email=you@example.com` — pre-fills the owner prompt.
- `--skip-owner` — for provisioning that creates the owner separately. With no
  administrator present it stops after step 2 and says what to run next, because the
  remaining seeders cannot run without a first user.

## After install

- Sign in at `/login` as the Platform Owner; you land on Platform Owner Home.
- Paid features need provider credentials in `.env` (Stripe, messaging, DataForSEO,
  Google). Until they are set the corresponding screens show their readiness state.
- The inherited SMS-gateway admin entries are hidden from the Platform Owner sidebar by
  default; set `LEGACY_MESSAGING_MENU=true` to show them. Their routes stay registered.

There is no web installer: `APP_STAGE=new` answers 503 with the command to run.
