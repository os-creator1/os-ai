# The inherited "Verify Product code" gate: assessment and decision record

**Status: decision required from the owner. The gate has NOT been removed, bypassed or faked.** This document is
engineering evidence, not legal advice. It records what the gate is, why it very likely applies to MotionGrove, what
entitlement it implies, the legitimate ways to proceed, and the clean replacement to implement only after the owner
confirms in writing that removal is permitted.

## 1. What the gate is

| Piece | Where | What it does |
|---|---|---|
| `ValidProduct` middleware | `app/Http/Middleware/RedirectIfNotValid.php`, wired in `app/Providers/RouteServiceProvider.php` | On the **admin** route group and the **customer** route group (`routes/customer.php`, which contains every Business page: CRM, Website, Calendar, Ads, SEO, Documents...), a signed-in user is redirected to `verify.license` while `app_config.license` is empty (`'' == null` in PHP). **While the licence is empty no signed-in customer can use any Business page either**, not only the Platform Owner. |
| Guest-area twin | `app/Http/Middleware/RedirectIfAuthenticated.php` | A signed-in user opening `/login` is sent to the same screen while the licence is empty. |
| Activation screen | `packages/kashem/licenseChecker/` (source vendored in the repo, not a Composer download), route `/verify-purchase-code` (`verify.license`) | Asks for an Envato **purchase code** and an application URL. |
| Vendor call | `ProductVerifyController::postVerifyPurchaseCode` | POSTs `{purchase_code, domain}` to **`https://ultimatesms.codeglen.com/verify/`**, the original product vendor's server. On `status: success` it stores the code in `app_config.license`, the licence type in `license_type` and `valid_domain = yes`. |
| Seeded state | `AppConfig::defaultSettings()` | `license = ''`, `license_type = 'Regular license'`, `valid_domain = 'yes'`. |

Not gated: the public login/registration pages, the public website renderer, webhooks, and the customer home
`user.home` (`/dashboard`, defined in `routes/auth.php`, which hands off to Business pages that are gated). The gate is a redirect, **not** an authorization system: who may see what is decided
separately by `can:access backend` / `can:access_backend`, `twofactor`, the entitlement system and per-route permissions.

## 2. Does it legitimately apply to MotionGrove?

**Almost certainly yes.** Evidence in this repository that MotionGrove is built on the commercial CodeCanyon product
"Ultimate SMS" by Codeglen:

- `composer.json` still names the package `codeglen/ultimatesms`;
- `config/app.php` documents the setting as a "purchase code from envato marketplace for verify real product";
- the baseline is Ultimate SMS 3.16.0 (`APP_VERSION`), and `CLAUDE.md` and `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md`
  describe large parts of Contacts, messaging, sending servers, sender IDs, invoices and the admin shell as the
  "legacy Ultimate SMS base";
- the gate ships inside the repo and guards exactly those inherited surfaces.

The package skeleton carries an MIT notice, but that covers the small helper package, not the product it enforces. The
root `composer.json` says `"license": "MIT"`, which is the generic Laravel skeleton default and is **not** evidence of
any right to the Ultimate SMS code. No licence, receipt or purchase code for the product exists in the repository.

**What entitlement that implies.** Envato's published Standard Licenses (https://codecanyon.net/licenses/standard)
distinguish a **Regular License**, for an end product that is free to its end users, from an **Extended License**, for
an end product that is sold or charged for. MotionGrove charges subscriptions, so if the code derives from the
CodeCanyon item the entitlement needed is **the Extended License, one per end product**, and the vendor's activation
server additionally ties a purchase code to a **domain**.

**What I could not determine** (it needs you, not code): which licence you actually hold; whether it is Regular or
Extended; whether Codeglen's own terms allow removing or replacing the activation check (Envato's summary does not
address modification); whether a staging domain needs a separate activation. If you hold no purchase code, or only a
Regular one, the gate is doing what the vendor intends and removing it would not be a clean-room fix.

## 3. What not to do (and a correction)

- Do not enter a made-up purchase code, and do not write a placeholder into `app_config.license`.
- **Correction:** earlier in this project a placeholder licence value was used on the local RC machine to get past the
  gate, and an earlier runbook step suggested the same for staging. That is a bypass of this check, not a configuration.
  It is withdrawn: do not use it on staging or production. (The test suite also seeds a fake `test-license-key` row; that is
  an isolated test fixture and never touches a real database.)

## 4. Legitimate ways to reach the dashboard

1. **Activate with a real purchase code.** Open `/verify-purchase-code` on staging while signed in, enter the code and
   `https://staging.getmotiongrove.com`. The vendor server must accept it for that domain (ask Codeglen whether a
   staging domain counts as a second activation). This is the supported path and needs the Extended License for a charged SaaS.
2. **Get written permission or the right licence.** Buy the Extended License if you only hold a Regular one, or obtain Codeglen's
   written confirmation that MotionGrove may replace the activation check. Then authorise section 5.
3. **Longer term: stop depending on the licensed code.** The V1 product surfaces (Business, Website, CRM, Calendar, Ads,
   Documents, SEO...) are new code; the dependence is the legacy messaging/admin base. Retiring those surfaces removes the
   reason for the gate.

## 5. The clean replacement (implement only after written authorisation)

Scope, so the change stays small and reviewable:

1. Remove `ValidProduct` from the two groups in `RouteServiceProvider` and the `app_config('license')` check from
   `RedirectIfAuthenticated`; delete `RedirectIfNotValid`, its `Kernel` alias, `packages/kashem/licenseChecker/` and its
   two `composer.json` autoload entries, the `verify.license` route and view.
2. Leave authorization exactly as is: `auth`, `can:access backend`/`access_backend`, `twofactor`, entitlements, tenancy.
   Platform Owners are admitted by those, never by a licence row.
3. Stop seeding/reading `license`, `license_type`, `valid_domain` (or leave the rows inert); no outbound call to the
   vendor remains.
4. Tests: `tests/Feature/Auth/LegacyLicenceGateTest.php` already pins the invariants. Change only its two "empty licence"
   expectations to "owner reaches the dashboard" and "customer reaches their area"; the guest, customer-vs-owner and public
   page assertions must stay green unchanged.
5. Deploy after a normal review; no database reset is needed.

## 6. Risks in the code as it stands (independent of the licence decision)

- `/verify-purchase-code` and its POST are **public** (route group is only `web`) and unthrottled.
- The vendor call disables TLS verification (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` false), so the purchase code and domain
  are sent over a connection that does not authenticate the server.
- The gate depends on a third party's server being up; if it disappears, activation cannot succeed.

## 7. Branding audit (legacy Ultimate SMS / AI Business OS)

**How public branding is wired:** the login, registration and activation pages take the name from `config('app.name')`
(the `APP_NAME` Forge variable) through `AuthBrandPresenter`; an authorised Agency brand for the host wins first.
There is no database name lookup on those pages. So staging branding is correct when Forge has `APP_NAME=MotionGrove`
and `APP_TITLE=MotionGrove`.

**Fixed in this change:** the activation screen now reads "Activate <platform name>" with a neutral explanation instead of
"Verify Product code"; the default page `keywords` no longer include "ai business os"; a config comment that named "Ultimate SMS"
is reworded (which turns the previously failing `BrandingConfigDefaultsTest` green).

**Remaining, deliberately not changed here** (each is a product decision or pinned by tests):
- Fallback identity "AI Business OS" (`config('app.name')` default, `AuthBrandPresenter::PRODUCT_NAME`, mail subject/body, copy in
  several views and ~10 branding tests): only visible if `APP_NAME` is unset. Renaming it is a separate branding lane.
- Seeded `app_config` defaults: `app_name = 'AI Business OS'`, `app_title`, a vendor Dhaka **company address** (shown on legacy
  admin invoices and pre-filled in Platform Settings; set your own there), and a default logo path.
- `config('app.maintenance_secret_path')` defaults to `codeglen`: set `MAINTENANCE_SECRET_PATH` to a private value in Forge.
- `data-framework="ultimatesms"` HTML attribute, `composer.json` package name, `.env.example`.
- Local developer `.env` files may carry the old `APP_KEYWORD="ultimate sms, codeglen, ..."`; do not copy that into Forge.

## 8. Needed from the owner

1. Which licence do you hold for the Ultimate SMS item (purchase code, Regular or Extended, buyer account)?
2. Either activate staging with that code (section 4.1), or provide Codeglen's written permission / the Extended License and
   authorise section 5.
3. Forge: `APP_NAME`, `APP_TITLE`, a private `MAINTENANCE_SECRET_PATH`; set the company address in Platform Settings.
