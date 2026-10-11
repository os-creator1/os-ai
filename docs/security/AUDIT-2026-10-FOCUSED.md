# Focused security audit - first pass (static review only)

Branch: security-audit-2026-10. No code was changed, no tests were run (vendor/ and a database are not present in the cloud
environment), no production data or Forge configuration was touched. Every finding below is from reading the code.

## Confirmed by code reading (not exercised at runtime)

### F1 HIGH - Unrestricted file upload into web root (sender ID document)
- `POST /senderid` -> `app/Http/Controllers/Customer/SenderIDController.php:226-245`, `app/Library/Tool.php:748-759`,
  `app/Http/Requests/SenderID/CustomSenderID.php:25-37`.
- `document` is validated (`mimes:jpeg,png,jpg,gif,pdf,doc,docx`) ONLY when `config('app.trai_dlt')` is true AND the plan is DLT
  (`TRAI_DLT` defaults to false). Otherwise the file is saved to `public/senderid_docs/senderid_<time()>.<client extension>`.
- Impact: any customer with an active subscription can store `.php/.phtml/.html/.svg` under the app origin. HTML/SVG gives stored XSS
  on the app origin (admin sessions reviewing sender IDs). PHP execution depends on the web server: Forge's default nginx
  `location ~ \.php$` executes it => RCE. `deploy/forge/verify-uploads.sh` confirms `senderid_docs` is a live public dir.
- Fix: validate `document` unconditionally (`required|file|mimes:...|max:`), generate a random server-side name with a fixed
  extension derived from the validated MIME, store on a private disk and serve through an authorised controller; deny PHP execution
  under `public/{senderid_docs,mms,voice,images}` in nginx.
- Deployment check: confirm nginx blocks script execution in those directories; list existing files in `senderid_docs`.

### F2 HIGH - Cross-tenant read/import of another customer's uploaded CSV (IDOR on `csv_data_file_id`)
- `app/Http/Controllers/Customer/ContactsController.php:1341` (`CsvData::find($request->input('csv_data_file_id'))`) and
  `app/Repositories/Eloquent/EloquentCampaignRepository.php:2322` (`sendUsingFile`).
- `csv_data` rows carry `user_id` but it is never compared with `Auth::id()`; ids are auto-increment integers.
- Impact: an authenticated customer can enumerate ids, import another tenant's uploaded contact list into their own group (then
  export it), send a campaign to another tenant's list, and delete the victim's pending upload (`$data->delete()`).
- Fix: `CsvData::where('user_id', Auth::id())->find(...)` (and business scope) at both sites; add a cross-tenant regression test.

### F3 MEDIUM - Sub-account can switch into its parent account
- `GET /account/{user}/impersonate` (`routes/auth.php:198`) -> `EloquentAccountRepository::impersonate()` lines 2305-2341.
- Check is `authUser->parent_id === $customer->id`, i.e. the *child* may log in as the *parent*. Session permissions stay the
  sub-account's, so Gate-checked actions stay limited, but every ownership check using `Auth::id()` now resolves to the parent
  (contacts, campaigns, billing views, API token page, etc.). It is documented as the legacy "Login as Parent" feature
  (`ParentAccountSwitch`), so this may be intended; it contradicts the idea of a restricted team member.
- Fix: restrict to owner/admin-approved sub-accounts or remove; at minimum add `customer.sub_only`-style guard review and test.

### F4 MEDIUM (latent, conditional) - Legacy payment "success" callbacks grant plans without gateway verification
- `Route::any('subscriptions/{plan}/success')` -> `PaymentController::successfulSubscriptionPayment` (line 9223+): the
  `2checkout`/`payu`/`coinpayments` branch calls `updateSubscriptionData()` with NO verification; `instamojo` trusts request
  `payment_status=Completed` and compares `payment_request_id` to a session value (null == null passes when unset);
  `payumoney` hashes with an empty salt (attacker can compute it). Same pattern likely in sender-ID/number/keyword/top-up success routes.
- Gated by `PaymentMethods.status = true`; the seeder ships all of these inactive and V1 signup uses Stripe only. Becomes a
  free-plan bug the moment an admin enables any of them.
- Fix: remove/410 these routes and controllers for non-Stripe methods, or verify server-side with the gateway before crediting.

### F5 LOW/INFO - Seed data contains vendor identity
- `database/seeders/PaymentMethodsSeeder.php:385-398`: the only ACTIVE seeded payment method is "Offline Payment" showing a
  placeholder bank account and `codeglen@gmail.com`. Verify the live staging/production row was edited so customers are not told to pay the vendor.
- `database/seeders/SupportAdministratorsSeeder.php` creates `is_admin` users with password `12345678`. It is only referenced by the
  orphan `uSupportPluginSeeder` (not in `DatabaseSeeder`). Delete both seeders.
- `.env.example` commits an `APP_KEY` and `APP_DEBUG=true`; ensure no environment ever copied it (deployment check). `error_log` and `public/rtl.zip` are committed (harmless content seen).

## Suspected / needs verification
- S1 Social login (`LoginController::handleProviderCallback`, `EloquentAccountRepository::findOrCreateSocial`): links to an existing
  account by e-mail alone and sets `email_verified_at`; does not check `services.*.active` or that the provider asserts a verified
  e-mail (Facebook). Only exploitable if provider credentials are set in the environment. Verify `.env` has none, or add a verified-e-mail check and block admins.
- S2 Legacy DLR/inbound webhooks (`routes/public.php`, `DLRController`): most families are 410 via `LegacyWebhookRouteRegistry`;
  the remaining supported ones (textlocal, plivo, infobip, etc.) were not individually checked for signature verification.
  Code comment at DLRController ~1445 states they are not signed. Impact: forged delivery status, not data access.
- S3 `ValidProduct` / `RedirectIfNotValid` redirects to route `verify.license`, which does not exist (route removed): a user
  whose `app_config('license')` is null gets a 500. Availability only; do NOT re-enable licensing, just confirm the config key is populated on staging.

## Checked and found sound (sample)
- No hardcoded admin or master password in app code; `UserSeeder` no longer creates an admin.
- No installer routes; `/` aborts 503 until `platform:install`. No eval/exec of user input found.
- Admin area: `can:access backend` is a different permission string from customer `access_backend`; sensitive groups add
  `EnsureUserIsAdministrator` (`is_admin` + not in View-As). Admin impersonation requires `edit customer`, admin targets need user id 1.
- Stripe webhooks use `Webhook::constructEvent`. Twilio webhooks verify signatures. Business/document line items and contact
  groups sampled are tenant-scoped (`resolveOwnedContactGroup`, DocumentManager version filters).
- Legacy HTTP API (`/api/http`) authenticates by `api_token` via `User::findByApiToken` (null-safe).

## Coverage gaps (not reviewed)
Website/public site renderer and custom-domain handling; form/document public token endpoints; Business\* and Workspace\*
controllers beyond a grep-sample; agency client invitation claim; Sanctum API (`routes/api.php`) per-controller scoping; admin
settings writers (`PlatformSettingsEnvWriter`, .env writes); Pusher auth channels; SSRF in sending-server/webhook URL fields;
mass-assignment on models; queue/`JobServiceProvider` unserialize; dependency CVEs (`composer audit`); Forge nginx/PHP config.
