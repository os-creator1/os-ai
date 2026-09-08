# CUSTOMER EXPERIENCE — SLICE 2: SHARED AUTHENTICATION BRANDING AND CUSTOMER SHELL

## 1. Status and authority

**Status:** Implemented on branch `agent/customer-experience-slice-2-auth-shell`.

**Parent contract:** `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md`,
whose §9 (authentication and shared shell), §17 (translation, accessibility,
empty states), §21/§21.1 (row 2), §22.1 (row 2 allowlist), §24/§24.1
(T-AUTH-1..2, T-I18N-1..2) and §28.6 (neutral typographic panel; artwork
deferred to Slice 10) govern this slice. Where this document and the parent
disagree, the parent wins.

**Verified base:** `origin/main` at `72f6cc7414fa192532bbe667f222baacee0a1743`
(`Merge pull request #217`, the Slice 1B correction round), with
`8aa51640e80021be4f905deed32cec30fc96c092` as an ancestor.

**Owned tests (parent §24.1):** T-AUTH-1, T-AUTH-2, T-I18N-1, T-I18N-2.

**Preserved unchanged:** the Slice 1B `CustomerContext`, Business/Account
frames, `CustomerMenuBuilder`, the context switcher, View-as-client and its
narrowing. No second switcher, menu builder or context resolver exists.

## 2. What the slice delivers

| Requirement (brief) | Delivered by |
|---|---|
| One shared branding seam with a safe presentation model | `app/Library/Branding/{AuthBrandPresenter,AuthBrand,AuthBrandSource,AgencyBrand,AgencyBrandSource,AgencyBrandResolver}.php` |
| Neutral AI Business OS identity without artwork | `AuthBrandPresenter::neutral()` + the typographic panel in `resources/views/components/branding-illustration.blade.php` |
| No `login-v2*.svg` (or any inherited Vuexy illustration) fallback | `BrandingPresenter::illustration('auth')` returns `src: null`; every auth view renders only through the component |
| Agency white-label precedence and isolation | `AgencyBrandResolver` (host only, re-validated against the Workspace), no per-tenant cache |
| Auth screens: one `<h1>`, visible labels, accessible password toggles, error association, no forced tab order | `resources/views/auth/**` (8 screens) + `resources/views/auth/partials/_flash-summary.blade.php` + the toggle-state script in `layouts/fullLayoutMaster.blade.php` |
| Shell: skip link, one `<main>` landmark, page + context in the document title, "Sign out" | `layouts/{fullLayoutMaster,verticalLayoutMaster,contentLayoutMaster}.blade.php`, `resources/lang/en/locale.php` |
| Translation completeness | `resources/lang/en/locale.php` (`menu.*` for every builder label; new `auth.*`, `labels.*` strings) |
| Empty-state foundation | `resources/views/layouts/partials/empty-state.blade.php` |

## 3. Authentication screens audited and changed

Every real customer authentication screen was audited by direct inspection:

| Screen | Route | Before | After |
|---|---|---|---|
| Sign in | `login` | `<x-branding-illustration>` whose fallback was `images/pages/login-v2*.svg`; `<h2>`; `<span>` password toggle; `tabindex` 3/4; errors only as toasts | Branding seam (mark + panel); `<h1>`; `<button aria-pressed aria-label>` toggle; no tabindex; inline `#auth-flash` summary tied to the email field |
| Register | `register` | direct `images/pages/create-account.svg`; no page-level heading | Seam; `<h1>` above the wizard; button toggles with state |
| Forgot password | `password.request` | direct `forgot-password-v2*.svg` | Seam; `<h1>`; inline summary; field association |
| Reset password | `password.reset` | direct `reset-password-v2*.svg`, `alt="Register V2"` | Seam; `<h1>`; button toggles; invalid-link message; field association |
| Email verification | `verification.notice` | seam with `login-v2` fallback; resend via JS-only link | Seam; `<h1>`; a real "Send me another verification link" submit button |
| Two-factor code | `verify.index` | direct `two-steps-verification-illustration*.svg`; `<h6>` instead of a label; hard-coded English | Seam; `<h1>`; `<label for="two_factor_code">`; `inputmode="numeric" autocomplete="one-time-code"`; all copy translated |
| Backup code | `verify.backup` | same as above | same treatment |
| Accept invitation | `sub_account.accept` | direct `not-authorized*.svg` | Seam; `<h1>`; button toggles; field association |

Not in scope (and untouched): `auth/payment/**` (registration checkout, a
billing surface), `auth/profile/**` (the authenticated profile), `loggedAs`
(impersonation banner), `termsOfUses`/`privacyPolicy` (public text pages that
already carried no illustration). The shared HTTP error pages
(`resources/views/errors/*`) render the *generic* Vuexy `images/pages/error*.svg`
stock image and are outside this slice's allowlist — see §9.

## 4. The branding seam

```
AuthBrandPresenter::for(Request) : AuthBrand
    1. AgencyBrandResolver::resolve(Request)   → AgencyBrand | null
         - reads ONLY $request->getHost()
         - consults a bound AgencyBrandSource (none is bound at this base)
         - re-checks the Workspace: exists, is_active, active Agency-tier plan
    2. platform: config('app.name') / BrandingPresenter::illustration('auth')
    3. neutral: "AI Business OS", initials mark, tagline, product areas
```

`AuthBrand` is a readonly presentation model of display strings and
already-validated public asset paths: no model, no Workspace uid, no config
value, no filesystem path ever reaches Blade. Normalization rules:

* an asset reference is emitted only when it is a relative path under
  `images/branding/`, has a permitted extension (`png jpg jpeg webp svg gif`),
  contains no `..`, `//`, `:` or unexpected character, and names an existing
  public file — otherwise it silently becomes "no image" (typographic mark);
* display text is tag-stripped, control-character-free, whitespace-collapsed
  and bounded (name 80, tagline 160); an empty Agency name falls back to the
  product name;
* only `--auth-brand-accent` may be customized, and only with a validated
  `#rrggbb` value; the panel's other tokens are the merged design-system
  custom properties (`--color-primary`, `--color-card`, `--color-border`, …)
  with Bootstrap fallbacks — no hex literal, no font-family, no animation.

**Precedence (contract §9.1):** Agency → owner platform → neutral. The owner's
configured `auth_illustration` still renders (as a decorative `alt=""`
image) when set and valid; the owner's `app.name`/logo keep working through
the existing `<x-branding-logo>` seam. The neutral identity is a finished
typographic panel — mark, name, tagline "Sign in to run your business from
one place.", and the seven product areas the merged shell actually offers
(Contacts, Conversations, Campaigns, Automations, Website, Google Business
Profile, Analytics). No statistic, testimonial, screenshot or social-login
implication is invented.

### 4.1 Agency white-label: what is and is not shipped

Repository inspection found **no authoritative unauthenticated tenancy
signal**: there is no custom-domain mapping, no Workspace branding storage,
and the `white_label` platform feature is `Planned`
(`App\Library\Entitlement\PlatformFeatureRegistry`). Therefore:

* `AgencyBrandSource` is the single, documented extension point (an exact
  host → brand lookup). **Nothing binds it in production**; every request
  resolves to the platform/neutral identity today.
* `AgencyBrandResolver` already enforces everything a future source must
  not be trusted with: host-only input, Workspace re-validation, exception
  containment, no caching.
* The tests bind a host-map fake at exactly that seam and prove the
  isolation properties end to end (§7, T-AUTH-2), so the future slice that
  ships the real signal (Agency white-label settings, contract §9.3 item 3)
  inherits them without re-deriving them.

Never consulted, structurally: query parameters, submitted Workspace uids,
session values, "the first Workspace", another user's selection, or any
shared mutable state. The only cache in the branding path is
`BrandingPresenter`'s platform cache (`platform_branding:resolved`), which is
tenant-independent by construction and is proven never to carry an Agency
name.

## 5. Shared authenticated shell (through the allowlisted layouts)

* `verticalLayoutMaster`: a `Skip to main content` link (first focusable
  element) and exactly one `<main id="main-content">` around the page body
  (both content-layout branches).
* `contentLayoutMaster`: the document title becomes
  `{Page} · {current Business or account} - {platform title}` from the Slice 1B
  context stored on the request by `ResolveCustomerContext` — never a bare
  product name, never "Workspace" for a Core/Growth customer.
* `fullLayoutMaster` (guest): `<title>{Page} · {resolved brand}</title>` so a
  white-labelled screen never leaks the platform's own title; `<main>`
  landmark; the password-toggle state script.
* The user menu now reads **Sign out** (`locale.menu.Logout` → "Sign out");
  Profile and the existing Billing/Pricing/Announcements entries are
  unchanged; sign-out stays a CSRF-protected POST.
* Slice 1B's sidebar context (`data-role="sidebar-context"`), the switcher
  (`aria-label="Current business: … Switch business"` / "Choose a …") and the
  View-as banner (`role="status"`, Exit control) are rendered exactly as
  before — proven by `CustomerShellLayoutTest` and `AuthBrandTenantIsolationTest`.

### 5.1 Shell behaviour that this allowlist cannot deliver (stop-and-report)

The brief's §6/§10 name three navbar-level behaviours that live in
`resources/views/panels/navbar.blade.php`, which is **not** in the Slice 2
allowlist (only `resources/views/layouts/**` is). They were not edited:

1. **Requirement:** the user-menu control carries an accessible name and an
   expanded/collapsed state (`aria-expanded`), and the icon-only notification
   and language controls carry accessible names.
   **Path required:** `resources/views/panels/navbar.blade.php`.
   **Why no allowlisted path can satisfy it:** the control markup is emitted
   by that partial; a layout can only include it, and re-implementing the
   navbar under `layouts/` would duplicate the shell (forbidden by §6).
2. **Requirement:** the user menu's plan entry reads "Plan & subscription"
   and the legacy "Billing"/"Pricing" wording is retired there.
   **Path required:** the same partial (the label text is in it; the
   catalogue value `locale.labels.billing` is shared with other pages, so it
   cannot be re-purposed safely).
3. **Requirement:** the HTTP error pages used by expired/invalid links (403,
   419, …) render no inherited Vuexy `error*.svg`.
   **Path required:** `resources/views/errors/{401,403,404,419,429,500,503}.blade.php`.

**Narrowest proposed amendment:** add
`resources/views/panels/navbar.blade.php` (user-menu labels and ARIA state
only) and `resources/views/errors/{401,403,404,419,429,500,503}.blade.php`
(illustration replacement only) to the Slice 2 allowlist, or defer them to
Slice 10 with the artwork drop-in.

## 6. Translation inventory and fallback

* **Inventory (mechanical):** every literal label in
  `CustomerMenuBuilder` (`$this->item(..., 'Label', ...)`, `new MenuItem(...,
  'Label', ...)`) plus the computed ones (`Team & agency account`, `Choose an
  account`, `Businesses`, `Client accounts`, `Business`, `Client account`)
  now has an English `locale.menu.*` entry — 23 entries added: Home,
  Advisor, Conversations, Website, Google Business Profile, Business
  details, Blocked numbers, Usage & billing, Team & account, Team & agency
  account, Plan & subscription, Advanced, Messaging provider, Sender IDs,
  Choose an account, Business, Businesses, Client account, Client accounts,
  Prospecting, Accounts, Profile, Sign out. `CustomerShellTranslationTest`
  re-derives the inventory from the builder source on every run, so a new
  label without a translation fails the build.
* **Fallback (Slice 1B, verified here):**
  `components/customer-nav-item.blade.php` renders `__('locale.menu.<label>')`
  only when `Lang::has()` is true and otherwise the builder's trusted human
  label — never the key path. Proven with an untranslated label.
* **Auth strings:** `show_password`, `hide_password`, `continue_with`,
  `brand_panel_areas_label`, `did_not_receive_email`,
  `request_another_link`, `two_factor_code_sent_to_email`,
  `enter_security_code`, `did_not_get_code`, `resend_code`, `or_lowercase`,
  `backup_code_help`, `enter_backup_code`, `verify_with_email_code`,
  `reset_link_invalid`; shell strings `skip_to_main_content`,
  `empty_state_{empty,unconfigured,locked}`.
* **T-I18N-1 matrix:** Core owner, Growth owner (Home, Contacts, Campaigns,
  Automations, Website, GBP, Analytics, Usage & billing, account list,
  account page), Agency owner (same + Account frame), Agency admin, scoped
  staff, View-as, profile, and the guest screens — no response contains
  `locale.`.

## 7. Empty-state foundation

`resources/views/layouts/partials/empty-state.blade.php` is the shared
pattern, delivered through the allowlisted layout surface (the
`components/**` directory is not in this slice's allowlist, and no feature
page was rewritten). It answers the four questions — *what this is* (title),
*why it is empty or unavailable* (explanation + a visible state word:
"Nothing here yet" / "Not set up yet" / "Not included in your plan"), *what
next* (primary and optional secondary action), *who can change it*
(`ownerHint`) — with a decorative icon by default (or an accessibly named
one), escaped fields, `data-state` for tests and styling, and a responsive
centred layout. Feature pages adopt it in their own slices with
`@include('layouts.partials.empty-state', [...])`; **no global adoption is
claimed.**

## 8. Accessibility (rendered-DOM assertions)

`AuthNeutralBrandingTest`, `CustomerShellLayoutTest`, `EmptyStateFoundationTest`:
one `<h1>` per auth page; every email/password/code input has a `<label for>`;
password toggles are `<button type="button">` with `aria-label`,
`aria-pressed`, `aria-controls`, kept in step by the layout script; error
summaries have `role="alert"` (or `role="status"` for success) and are named
by the field's `aria-describedby` with `aria-invalid="true"`; no positive
`tabindex`; decorative images carry `alt=""`; informative Agency marks carry
the Agency name; the shell has a skip link and one `<main>`; the current
context and the View-as banner keep their Slice 1B accessible names and
`role="status"`. Contrast and focus come from the merged design tokens
(`--color-focus-ring`, primary/button-text pairs) — no new colours were
introduced. The panel uses no animation, so no reduced-motion rule is
needed. Full WCAG conformance is **not** claimed (Slice 10, T-A11Y-1..3).

## 9. Changed paths

Implementation: `app/Library/Branding/{AgencyBrand,AgencyBrandResolver,AgencyBrandSource,AuthBrand,AuthBrandPresenter,AuthBrandSource}.php`
(new), `app/Library/Branding/BrandingPresenter.php` (auth fallback → null),
`resources/views/components/branding-illustration.blade.php`,
`resources/views/auth/{login,register,verify,twoFactor,twoFactorBackUp}.blade.php`,
`resources/views/auth/passwords/{email,reset}.blade.php`,
`resources/views/auth/subAccount/acceptInvitation.blade.php`,
`resources/views/auth/partials/_flash-summary.blade.php` (new),
`resources/views/layouts/{fullLayoutMaster,verticalLayoutMaster,contentLayoutMaster}.blade.php`,
`resources/views/layouts/partials/empty-state.blade.php` (new),
`resources/lang/en/locale.php`.

Tests: `tests/Feature/Auth/AuthNeutralBrandingTest.php` (new, T-AUTH-1 +
accessibility), `tests/Feature/Branding/{AuthBrandTenantIsolationTest (T-AUTH-2),AuthBrandPresenterTest}.php`
(new), `tests/Feature/Theme/{CustomerShellTranslationTest (T-I18N-1..2),CustomerShellLayoutTest,EmptyStateFoundationTest}.php`
(new), `tests/Feature/Branding/{BrandingComponentRenderTest,BrandingPresenterFallbackTest}.php`
(the two assertions that pinned the Vuexy fallback).

Documentation: this file; `DESIGN-SYSTEM-M2-PLATFORM-BRANDING-CONTRACT.md`
(§3.8/§6.4 supersession note); `DESIGN-SYSTEM-M2-SLICE-2-CONTRACT.md` (§5 note).

Not changed, by rule: `resources/sass/**` is named in the allowlist but does
not exist in the repository (the stylesheet sources are `resources/scss/**`);
no stylesheet source was edited and no asset build was required — the panel
is styled by a token-only `<style>` block emitted once per page by the
component. No dependency, lock file, manifest, `.env` or compiled bundle was
touched.

## 10. Evidence

All runs: `php artisan test <path>` (PHP 8.3.30, MySQL 8.4), one path per
invocation, strictly serial, against the disposable `ultimatesms_testing`
database, on this branch's worktree.

**Owned and touched tests (final state):**

| File | Result |
|---|---|
| `tests/Feature/Auth/AuthNeutralBrandingTest.php` (T-AUTH-1, accessibility) | 13 passed, 312 assertions |
| `tests/Feature/Branding/AuthBrandTenantIsolationTest.php` (T-AUTH-2) | 11 passed, 106 assertions |
| `tests/Feature/Branding/AuthBrandPresenterTest.php` | 7 passed, 61 assertions |
| `tests/Feature/Theme/CustomerShellTranslationTest.php` (T-I18N-1, T-I18N-2) | 6 passed, 183 assertions |
| `tests/Feature/Theme/CustomerShellLayoutTest.php` | 6 passed, 36 assertions |
| `tests/Feature/Theme/EmptyStateFoundationTest.php` | 4 passed, 30 assertions |
| `tests/Feature/Branding/BrandingComponentRenderTest.php` (amended) | 5 passed, 62 assertions |
| `tests/Feature/Branding/BrandingPresenterFallbackTest.php` (amended) | 9 passed, 18 assertions |
| `tests/Feature/Auth/{AuthPageRender,AuthDesignSystemContent,AuthFormContract,AuthValidationDisplay,AuthActiveThemeRendering}Test.php` (existing) | 7 / 4 / 3 / 4 / 2 passed |
| `tests/Feature/Branding/BrandingDesignSystemContentTest.php` (existing) | 4 passed, 120 assertions |

**Regression:** `tests/Feature/Auth` 33 passed; `tests/Feature/Branding`
52 passed, 1 failed (pre-existing, below); `tests/Feature/Theme` 85 passed;
Slice 1B owned tests `CustomerContextResolutionTest` 16, `CustomerContextSecurityTest`
9, `ViewAsClientTest` 5, `ViewAsAccessLossTest` 6, `ViewAsRouteBoundaryTest`
9, `WorkspaceAccountFrameAccessTest` 6 — all passed; `tests/Feature/DesignSystem`
214 passed; `tests/Feature/Security` 140 passed; `tests/Feature/Workspace`
774 passed; `tests/Feature/Business` 540 passed, 3 failed (pre-existing);
`tests/Feature/Website` 121 passed, 4 failed (pre-existing);
`tests/Feature/GoogleBusinessProfile` 136 passed; `tests/Feature/Analytics`
62 passed; `tests/Feature/Automations` 86 passed; `tests/Feature/Outreach`
35 passed; `tests/Feature/Usage` 903 passed, 1 failed (a real-two-process
concurrency test that passed on an immediate repeat and in the full suite);
full suite `tests` **4983 passed, 9 failed (24 914 assertions)**.

**The 9 full-suite failures**, none in a path this slice touches, reproduce
identically on a detached worktree at exact `origin/main` `72f6cc7` with the
same database and environment: `BrandingAdminFooterRenderTest` (expects the
default company name; this environment's `.env` sets a placeholder footer
company), the three `BusinessKnowledgeProfile*` tests, the
`OpportunityManagerBeginRunTest` heartbeat-boundary case, and the four
Website tests (CRLF `robots.txt`, MySQL JSON key ordering). See the branch
report for the per-file baseline counts.

No asset build was run or required; `git diff --check` is clean.

## 11. Deferred to Slice 10 (by contract §28.6)

Commissioned AI Business OS illustration artwork and its visual assertions;
the error-page illustration replacement (§5.1 item 3) if not amended into
this slice; full T-A11Y-1..3 conformance.
