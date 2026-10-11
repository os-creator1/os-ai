# Legacy Ultimate SMS UI - inventory and retirement

Classification: **A** obsolete UI (removed), **B** legacy functionality MotionGrove still uses (needs modernising),
**C** infrastructure / data that must be preserved.

## A. Removed in this change
| What | Where it was | Now |
|---|---|---|
| Inherited admin dashboard (SMS charts, revenue/customer charts, sender-ID list, "getting started" gateway checklist) | `Admin\AdminBaseController@index`, `resources/views/admin/dashboard.blade.php` (809 lines) | **Deleted.** `/admin/dashboard` (`admin.home`) is a redirect only. |
| Dashboard cache warmer | `dashboard:warm` command + hourly schedule + `AdminBaseController::warmCache()` | **Deleted** (it only fed the dead dashboard). |
| "Dashboard" / "Messaging Dashboard" sidebar entries | `Helper::menuData()` | Replaced by **Home** (staff landing); owners keep the real Platform Owner Home. |
| ~190 hard-coded breadcrumb links to `/admin/dashboard` in 41 admin controllers/views | `url(config('app.admin_path') . '/dashboard')` | `Helper::adminHomeUrl()` (Platform Owner Home, or staff Home). |
| Brand/logo/login/language/impersonation redirects to `admin.home` | LoginController, LanguageController, AccountController, User/Customer repositories, navbar, sidebar | Resolve through `Helper::adminHomeUrl()` / `Helper::home_route()`. |
| Vendor demo company address as the default `company_address` setting | `AppConfig::defaultSettings()` | Empty default (existing rows are unchanged: edit it in Platform Settings). |
| Vendor bank details / `codeglen@gmail.com` in the seeded offline-payment text | `PaymentMethodsSeeder` | Neutral placeholder (existing rows unchanged; legacy payments are also closed by `LegacyPaymentGate`). |
| "Codeglen" sample names in the customer API docs; "Ultimate SMS" wording in gateway setup help | `customer/Developers/*`, `admin/SendingServer/create.blade.php` | Neutral wording. |

New landing: `GET /admin/staff-home` (`admin.staff-home`) for backend staff who are not Platform Owners - lists only the
sections their role may open (sidebar visibility rules; every target re-checks authority).

## B. Still used by MotionGrove - modernise later
* **Messaging stack UI** (admin Sending Servers / Sender IDs / Numbers / Keywords / Reports / Blacklists; customer Campaigns,
  Contacts, Templates, Keywords, Chat Box, Developers API docs): this is the working SMS product MotionGrove sells; the pages
  keep the inherited look and some inherited copy (see lang files). Needs design-system migration, not deletion.
* Legacy customer pages for Plans/Subscriptions/Invoices/Sender-ID purchase: payments are closed by `LegacyPaymentGate`; the
  pages themselves should be folded into the V1 billing surfaces.
* Language files still contain thousands of inherited strings (`resources/lang/*`).
* Admin "Settings" pages (general, authentication, notifications, pusher, DLT, permissions) are inherited layouts.

## C. Must be preserved
* Tables and models: `users`, `customers`, `subscriptions`, `plans`, `invoices`, `reports`, `senderid`, `keywords`, `phone_numbers`,
  `sending_servers`, ... (data, FK targets, billing history, messaging pipeline).
* `data-framework="ultimatesms"` attribute on the layout `<body>`: a CSS/JS hook (`resources/js/core/app.js`); not user-visible.
* `software_version` setting, `Codeglen\Usupport` package references in `FaqSeeder` (vendor package namespace).
* Gateway adapters in `SendCampaignSMS`, DLR/inbound webhooks (`LegacyWebhookRouteRegistry`), `WarmDashboard`-free caches.
