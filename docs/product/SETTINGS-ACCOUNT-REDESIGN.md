# Settings & Account redesign

Visual redesign of ten customer screens. **No backend behaviour changed** except
two small, additive view-data fields (listed below). Every screen keeps its
routes, controllers, validation, permissions and persisted data; only the
Blade presentation was rebuilt to the approved reference screenshots.

## Page chrome

`public/css/settings-redesign.css` is a plain stylesheet (no build step) that
only consumes the existing design tokens (`--color-*`, `--radius-*`,
`--space-*`, `--shadow-*`). Everything is namespaced `.sr-*`; pages still built
from the shared `x-card` component inside `.sr-page` pick up the same card
chrome. Pages load it through `customer/settings/_redesign-assets.blade.php`
(`@section('page-style')`). `customer/settings/_sr-header.blade.php` is the one
page heading (back link, title, optional chip, description). The application
shell supplies no breadcrumb or page title, so each screen has exactly one
`<h1>`.

## Screens

| Screen | View | Notes |
|---|---|---|
| Plan & subscription | `customer/workspaces/plan.blade.php`, `partials/subscription.blade.php` | Plan name, status, prices, capability list, capacity, change-plan options and cancel all come from `WorkspacePlanPresenter` / `CustomerSubscriptionPresenter` (the plan catalogue). Nothing is named in the template. Stripe flows (payment method, change, resume, cancel, resubscribe) are untouched. `WorkspacePlanPresenter` gained a `meter` entry (`used`, `total`) drawn from the same capacity decision as the existing sentence. |
| Usage & billing | `customer/business/usage-billing/show.blade.php` | All figures still the presenter's integer micro-unit strings. The "who pays" statement moved into the page lede; AI usage is the third stat when it has no per-Business rows, otherwise it keeps its own card. Spending limit, capability limits, pause, agency controls, billing contact, funding and history keep their forms and ids; History is one card with Usage / Funding tabs. |
| Team | `customer/workspaces/partials/team.blade.php` | Add-member and member-management forms post to the same endpoints. `WorkspaceController` adds `teamOwner` (the Owner row; their e-mail only when the viewer is that Owner). Member e-mail addresses are still never listed. There is no invitation workflow (a person needs an existing account), so there are no pending invitations. |
| Account / Security / Information | `auth/profile/*` | Same routes and server validation. The password requirement line is read from the live `Password::default()` rule. "Same as my address" only copies values client-side before save. The delete-account safeguards (acknowledgement checkbox gates the button, confirmation dialog) are unchanged. |
| Business details | `customer/business/edit.blade.php` | Sidebar summary is built from the saved Business; works with partially completed Businesses. |
| Text messaging | `customer/settings/text-messaging/states/*` | Local and toll-free choices keep their real sequences and disclosed charges; buttons are disabled (not hidden) when number setup is unavailable. |
| Services | `customer/business/services/index.blade.php` | One list; on/off, make primary and rename post to the existing `BusinessServicesController` actions. |
| Locations | `customer/business/locations/index.blade.php` | Capacity bar and "you can add N more" come from the `LocationSlotCapacityDecision`; nothing about a plan allowance is written in the page. |

## Copy that tests now pin differently

A few assertions in existing tests pinned wording the reference redesign
changes: the Add-member hint, the single-Business sentence, and the Locations
capacity figure ("N of M in use" / "You can add N more"). Those assertions were
updated; their intent (the hint, the sentence, the live numbers) is unchanged.
