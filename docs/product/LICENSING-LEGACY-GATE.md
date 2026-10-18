# The inherited "Verify Product code" gate: decision record

**Status: retired on the owner's written instruction (branch `agent/retire-legacy-licence-gate`).** The owner states the
original Ultimate SMS software was legitimately purchased and that MotionGrove has been extensively rebuilt. The gate was
removed, not bypassed: no purchase code, placeholder or fake licence value is stored anywhere. This document is
engineering evidence, not legal advice.

## 1. What the gate was

| Piece | Was | Now |
|---|---|---|
| `ValidProduct` middleware (`RedirectIfNotValid`) on the admin and customer route groups | Redirected every signed-in user (owners and customers, i.e. every Business page) to `verify.license` while `app_config.license` was empty | Deleted, with its `Kernel` alias and its entries in `RouteServiceProvider` |
| Guest-area twin in `RedirectIfAuthenticated` | Same redirect for a signed-in user opening `/login` | Check removed |
| `packages/kashem/licenseChecker` (`/verify-purchase-code`) | Public, unthrottled; POSTed the purchase code and domain to `https://ultimatesms.codeglen.com/verify/` with TLS verification disabled | Package, service provider, view and both `composer.json` autoload entries deleted |
| `AppConfig::defaultSettings()` `license`, `license_type`, `valid_domain` | Seeded | No longer seeded; existing rows are inert |
| `config('app.purchase_key')` | Unused `PURCHASE_CODE` setting | Removed |

Authorization is unchanged: `auth`, `can:access backend` / `can:access_backend`, `twofactor`, entitlements, per-route
permissions and tenant scoping all still apply. The licence check never was an authorization system.

## 2. Other items removed in the same change

- Seeders for the vendor's demo support plugin, which created `is_admin` users `agent@codeglen.com`, `demo@admin.com`,
  `john@support.com`, `jane@support.com` with the literal password `12345678` (never called by any seeder chain, but
  one `db:seed --class=` away from a hardcoded privileged login): `SupportAdministratorsSeeder`, `SupportTicketSeeder`,
  `SupportArticleSeeder`, `SupportCategorySeeder`, `SupportSettingsSeeder`, `uSupportPluginSeeder`.
- Unused `config/usupport.php` and `config/ulanding.php`.
- Default `from_email` / `notification_email` that pointed at the vendor developer's personal mailbox: now `noreply@example.com`.
  Set the real sender in Platform Settings.
- `MAINTENANCE_SECRET_PATH` no longer defaults to the vendor word `codeglen` (unset means no preset secret; set a private value in Forge).
- The committed `.env.example` `APP_KEY` (a real-looking key) is blank.

## 3. What remains, classified

**Kept on purpose (legal / provenance):** author and copyright comments in inherited files (for example `routes/admin.php`,
`SMSCounter`) and the composer package name `codeglen/ultimatesms` record where the code came from. Third-party package
licences in `vendor/` are untouched.

**Kept, not a licence callback (product decisions, not dangerous):**
- Vendor demo presets in `EloquentSendingServerRepository` (`TYPE_ULTIMATESMS` -> `ultimatesms.codeglen.com/demo/...`,
  `TYPE_FORDEMO` -> `codeglen.com/demo`): only used if an admin deliberately adds that sending-server type. Retire with the legacy messaging surface.
- Default plan `sender_id` "Codeglen" in `Helper.php`, `data-framework="ultimatesms"` HTML attribute, the vendor's Dhaka
  company address default in `AppConfig` (set your own in Platform Settings), `public/installer` static assets and `public/rtl.zip`.
- The fallback identity "AI Business OS" (`config('app.name')` default, `AuthBrandPresenter::PRODUCT_NAME`, about ten branding tests).
  Only visible if `APP_NAME` is unset; renaming is a separate branding lane. Login branding comes from `APP_NAME`/`APP_TITLE`.
- Legitimate third-party payment and SMS gateway SDKs (Twilio, Stripe, and so on): real providers, not the product vendor.

**Searched and not found:** hidden admin/debug/phpinfo/install/migrate routes, maintenance bypasses other than the Laravel
secret above, self-update mechanisms, committed default-password logins (the only ones were the removed seeders).
`UserSeeder` creates only the `administrator` role; the Platform Owner is created by `platform:create-owner` with an
interactive password (minimum 12 characters).

## 4. Unresolved third-party entitlement (document before production)

Envato's Standard Licenses distinguish a Regular License (end product free to its users) from an Extended License (end
product sold or charged for). MotionGrove charges subscriptions, so if its code still derives from the CodeCanyon item the
right to run it commercially is the **Extended License, one per end product**. The repository cannot show which licence the
owner holds, and no receipt or purchase code is stored in it.

Owner to-do before production, not a code task:
1. Confirm in writing which licence (Regular or Extended) and which buyer account hold the purchase; keep the receipt outside the repository.
2. If only Regular, upgrade or obtain the vendor's written permission, or finish retiring the inherited surfaces
   (legacy messaging and admin base) so no licensed code remains.
3. Keep the attribution notices listed above.

Removal of the activation check is the owner's decision recorded here; whether the vendor's terms permit modification is
a question for the vendor or counsel, and the code cannot answer it.

## 5. Regression tests

`tests/Feature/Auth/LegacyLicenceGateRetiredTest.php` pins: owner and customer reach their dashboards with no licence rows;
a customer never reaches `admin.home`; cross-tenant access is denied; guests are denied; `/verify-purchase-code` and
`verify.license` are gone; the login page makes no outbound call; runtime source has no vendor-verify reference; no seeder
hardcodes a password or `@codeglen.com` account; default settings carry no licence rows or vendor mailbox.
`NoHardcodedDefaultAdministratorTest` and the two-factor and branding suites remain.
Not covered by an automated test: a real browser `POST /login` through to the dashboard (the existing auth and
two-factor suites cover login itself); verify on staging with the manual steps in `FORGE-STAGING.md` section 7.
