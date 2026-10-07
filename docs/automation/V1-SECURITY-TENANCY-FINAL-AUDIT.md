# V1 Security / Tenancy / Authorization — Final Audit

Branch `agent/v1-security-tenancy-final`, based on `origin/agent/v1-completion-integration` (6c42beb3).
Scope: hardening only. No product modules rebuilt, no navigation or UX changed.

Method: three parallel static audits (auth/session/agency/platform; CRM/conversations/calendar/forms/automations;
website/SEO/ads/payments/secrets), then every claimed defect was re-read at the code before being fixed.
The newer Business-addressed layer (workspace → business → location chain, View As, Agency, CRM, Calendar,
Forms, Website, SEO, Ads OAuth, e-sign, Stripe webhooks) traced sound. **Every defect found was in legacy
Ultimate SMS code reused under it.**

## Defects found and fixed

| # | Sev | Defect | Fix |
|---|-----|--------|-----|
| 1 | Critical | `/api/http/*` resolved the actor with `User::where('api_token', $input)`; a missing token compiled to `api_token IS NULL` and authenticated the caller as the first user without a token | `User::findByApiToken()` rejects null/blank/non-string; all 19 call sites use it |
| 2 | Critical | Any customer could view / update / enable / disable / delete **any** user (incl. admins) via `/sub-accounts/{uid}/*` and batch action; update mass-assigned `is_admin`, `status`, `email`… | Parent-ownership check (404) on every route and batch query; update whitelisted to name + email |
| 3 | Critical | Unauthenticated `account/{user}/success/...` deleted users / granted plans; `account/{user}/cancel` and two gateway handlers deleted any user | Auth restored; handlers only act when the signed-in actor *is* `{user}` |
| 4 | High | Legacy contact API (Sanctum + `/api/http`) bound `{group_id}`/`{uid}` with no ownership check: read/write/delete another tenant's contacts and groups | `AuthorizesOwnedContactGroup` guard on every method; contact must belong to the routed group |
| 5 | High | Contact import steps accepted a client-supplied file path (arbitrary file read/rewrite, cross-tenant staged-file ingest) | `ContactGroups::resolveStagedImportFile()` — only `import-<id>.csv` inside the import temp dir |
| 6 | High | Group update mass-assigned `customer_id`/`business_id`/`sending_server` (move a group into another tenant) | Whitelist in `EloquentContactsRepository::update` |
| 7 | High | Keywords/numbers: `pay` took over another tenant's free keyword/number; keyword update mass-assigned price/owner/validity; no ownership on show/update/removeMMS | Owner-or-available checks; update whitelisted; `removeMMS` scoped to the routed keyword |
| 8 | High | Subscription cancel/logs/renew/preferences had no ownership check | Owner check on every method |
| 9 | High | 2FA disabled by a plain GET; login / 2FA / backup-code brute-forceable; backup codes reusable; `rand()` codes; "passed 2FA" session flag inherited across logins | GET no longer disables; throttles; single-use backup codes; code expiry enforced; `random_int`; flag cleared on credential login |
| 10 | Medium | Sessions survived password reset and account disablement | Reset sets `password_changed_at` + saves remember token; `CheckPasswordChanged` logs out disabled users |
| 11 | Medium | Admin with "edit customer" could rewrite / delete other admins and the super admin; repository mass-assigned `is_admin`, `status`… | `mayMutateAccount()` on update/information/permissions/destroy/avatar; repository whitelisted |
| 12 | Medium | Conversations list + pinned chats ignored the Location axis (Location-limited member saw other Locations' threads and numbers) | `CrmLocationScope::restrict` on both queries |
| 13 | Medium | Cross-tenant campaign detail leak (`viewCampaign`, both APIs) | Owner check |
| 14 | Med-High | A BYO Twilio server's valid signature let tenant A inject inbound SMS into tenant B's number | Customer-owned servers may only deliver for numbers their owner owns |
| 15 | Medium | Account `webhook_url` unvalidated + blind SSRF on forward | `PublicHttpsUrl` (https, public IPs only) at save time and at call time |
| 16 | Low | `User::$hidden` omitted `api_token`, `two_factor_code`, `two_factor_backup_code` | Added |

## Remaining external / unfixed risks (explicitly not changed)

- **Legacy `inbound/{provider}` and `dlr/*` gateway routes (~58)** have no signature or token check; attribution is by
  receiving number only. Needs per-gateway shared secrets or disabling unused gateways — a product/provider decision.
- **Callback routes `callback/sslcommerz|aamarpay/register`** are CSRF-exempt and trust query-string status; only
  reachable if those legacy gateways are enabled. Recommend deleting the legacy registration-gateway block.
- **Blueprint mode** guards mail, notifications, `Http::` and the explicit domain choke points; it does not intercept
  direct provider SDK / raw Guzzle calls, and the per-process singleton does not follow queued jobs. No real
  execution path was found from the Blueprint controller.
- **`ViewAsRouteClassification` gaps (baseline, unchanged):** platform notices/announcement dismiss, agency
  connect-existing Stripe callback and calendar-connection routes are unclassified, so View As fails closed (404) on
  them; `ViewAsRouteBoundaryTest` already fails on these two assertions on the base commit.
- Public subscribe / unsubscribe contact endpoints are unthrottled and skip group-status / account-lock checks.
- Sub-account permission set is not intersected with the parent's permission set.
- Legacy `PaymentController` `callback/*` routes (subscriptions, sender ID, numbers, keywords) bind by uid with
  permission gates only; not traced to the repository `payPayment` methods.
- Email enumeration via `exists:users` on password reset; `/logout` accepts any method.
- Production should rotate any `api_token` ever exposed through an unscoped serialization path.

## Tests

`tests/Feature/Security/V1SecurityTenancyFinalTest.php` — 21 adversarial tests (attacker = tenant A or a guest,
target = tenant B or a platform account). 16 fail on the unfixed base and all pass with the fixes.
