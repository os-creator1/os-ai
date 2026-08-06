# RFC-003 Milestone 3 Slice 3C — Locked Contract

## Status and authority

This is the human-approved, bounded product contract for RFC-003 Milestone 3
Slice 3C on pull request #2 (`feature/rfc-003-m3` -> `main`). It is read
together with:

- `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`, especially
  sections 7.2-7.5, 14, 20, 21.7, 22, and Milestone 3;
- `docs/automation/AI-AUTONOMY-STATE.json`;
- `CLAUDE.md` and `AGENTS.md`.

If code, comments, review text, or a generated plan contradict this document,
the automation must stop at `ai:needs-human`. This contract authorizes only
Slice 3C. It does not authorize Milestone 3 aggregate regression work,
Milestone 4 mutations, or a merge.

## Locked Slice 3C contract

### Outcome

Complete the remaining read-only Milestone 3 customer surface by:

1. showing, on the existing Workspace overview, only the Businesses the
   current user can effectively access;
2. adding ordinary customer navigation to the existing Workspace index; and
3. linking each Workspace row on the existing index to that Workspace's
   existing overview.

No new Business detail/edit destination is introduced in this slice. The
existing `customer.business.edit` route resolves the signed-in customer's
primary Business and cannot represent an arbitrary Workspace-assigned
Business. A Slice 3C Business row must therefore not link or redirect there.

### Route and page shape

- Reuse only the existing read-only routes:
  - `GET /workspaces` (`customer.workspaces.index`)
  - `GET /workspaces/{workspaceUid}` (`customer.workspaces.show`)
- Add no route. In particular, do not create
  `customer.workspaces.businesses.index`, a per-Business route, or any
  POST/PUT/PATCH/DELETE Workspace route.
- The Business list is a section embedded on the existing Workspace overview,
  not a separate page.
- Add a customer-menu item named `Workspaces`, linked to `/workspaces`, with
  the existing `access_backend` permission and an English menu translation.
- On the Workspace index, make the existing Workspace name link to
  `customer.workspaces.show` using the already-present Workspace UID.
- On the Workspace overview, provide a plain link back to
  `customer.workspaces.index`.
- Business rows are display-only. They have no per-Business link, action,
  button, form, selection control, or JavaScript behavior.

### Workspace and Business authorization

The Slice 3B Workspace-overview boundary remains unchanged:

- 200 only for the Workspace owner or a holder of an active membership in
  that Workspace;
- 404 for an unknown UID, unrelated user, inactive-membership-only user,
  direct-Business-only user, or a user relying only on `users.parent_id` or
  platform-admin state as a customer-side Workspace path;
- owner status wins over an anomalous coexisting membership row; and
- an inactive Workspace remains addressable by its owner or active members so
  they can see the inactive tenancy state.

After that Workspace boundary succeeds:

1. Start from `WorkspaceRepository::businessesForWorkspace($workspace)`.
2. For every candidate Business, call
   `WorkspaceManager::userCanAccessBusiness($userId, $business)` and retain
   only `true` results.
3. Do not reproduce, shortcut, or partially reimplement the RFC-003 section
   14.1 algorithm in the controller or view.
4. Sort retained Businesses by persisted `businesses.id` ascending for a
   deterministic list, but never expose that numeric ID.

This produces the following required behavior:

- an active Workspace owner sees every Business in that Workspace;
- an active `all`-scope Admin or Staff member sees every Business;
- an active `selected`-scope Admin or Staff member sees only explicitly
  assigned Businesses;
- role never widens Business scope;
- within an otherwise accessible Workspace, direct Business ownership remains
  an independent access path exactly as RFC-003 section 14.1 specifies;
- an inactive membership grants no Workspace page and therefore no list;
- an inactive Workspace returns the overview but an empty Business list for
  every customer-side role, including direct and Workspace owners; and
- a Business from another Workspace never appears.

### View-data and rendering boundary

- The overview receives a `businesses` array for every authorized response,
  including an empty array.
- Each Business row contains exactly one key: `name`.
- Render the Businesses section for Owner, Admin, and Staff users alike.
- Render an explicit empty state when no Business survives the effective
  access filter.
- Escape Business names through normal Blade `{{ }}` output.
- Preserve Slice 3B's `workspace` data shape and membership-directory rules:
  Owner and active Admin receive `directory`; active Staff does not receive
  that key at all.
- Do not expose Business numeric IDs, UIDs, `customer_id`, `workspace_id`,
  owner/contact fields, counts, status, configuration, or relationship data.
- Do not render hidden identifiers, `data-*` identifiers, or URLs containing
  a Business identifier.

### Read-only and compatibility boundary

- GET/HEAD only; a request performs no database write, event dispatch,
  transition creation, session-based Business selection, or other state
  change.
- Call no mutating `WorkspaceManager`, repository, or model method.
- Do not alter the RFC-001 `customer.business.edit` or update behavior.
- Do not change `BusinessRepository`, `WorkspaceRepository`,
  `WorkspaceManager`, models, enums, migrations, service-provider bindings,
  permissions, middleware, or database configuration.
- Do not change the established Slice 3A/3B authorization or response-data
  contracts except for the explicitly added navigation links and
  `businesses` overview data.

## Allowed implementation files

The authoritative allow-list is duplicated in
`AI-AUTONOMY-STATE.json`. Slice 3C may modify only:

- `app/Helpers/Helper.php`
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `resources/lang/en/locale.php`
- `resources/views/customer/workspaces/index.blade.php`
- `resources/views/customer/workspaces/show.blade.php`
- `tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`

`tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php` must be created.
An implementation that requires another path must stop for human review rather
than silently expanding the allow-list.

## Required verification

The deterministic gate must run all of these commands and discover a positive
test count from every command:

```text
php artisan test --filter=WorkspaceBusinessListHttpTest
php artisan test --filter=WorkspaceOverviewHttpTest
php artisan test --filter=WorkspaceSwitcherHttpTest
php artisan test --filter=WorkspaceEffectiveAccessTest
```

At minimum, the Slice 3C HTTP coverage must prove:

- the existing route shape and absence of a separate Business-list or
  mutation route;
- guest rejection and every existing Workspace-overview 404 case;
- full active-Workspace visibility for the owner and `all`-scope Admin/Staff;
- selected-scope filtering for both Admin and Staff, including an empty
  assignment set and deterministic order;
- direct Business ownership as an independent Business-access path only after
  the Workspace page boundary succeeds;
- an inactive Workspace remains viewable but leaks no Business name;
- no foreign-Workspace Business appears;
- every `businesses` row has exactly the `name` key and no identifier or owner
  data reaches the response;
- Business names are escaped;
- the customer menu links to `/workspaces`, Workspace index names link to the
  correct overview, and the overview links back to the index;
- no Business row links to `/business` or contains a Business identifier;
- Slice 3B membership-directory visibility remains unchanged; and
- a GET performs no database writes.

## Explicitly forbidden scope

- Any per-Business customer detail/edit route or view.
- Any link or redirect from a listed Business to `customer.business.edit`.
- Active-Business selection, session state, cookie state, or a Workspace/
  Business switcher beyond the links defined above.
- New access algorithms or modifications to repository/manager/model/domain
  contracts.
- Workspace or Business mutation controls.
- Plans, entitlements, billing, wallets, usage, credits, Stripe, CRM, contacts,
  conversations, calendars, automations, websites, or later RFC work.
- Workflow, environment, dependency, migration, database-configuration, or
  production changes.
- Milestone 3 aggregate regression, merge, or automatic advancement beyond
  Slice 3C.

## Completion boundary

After a real Slice 3C commit passes the exact scope and test gate and a trusted
current-head Codex review has no unresolved P0/P1 finding, automation must set
`ai:ready-for-human` and stop. PR #2 remains draft and unmerged. The next
candidate is the Milestone 3 aggregate regression and human merge decision;
`advance_automatically` remains `false`.
