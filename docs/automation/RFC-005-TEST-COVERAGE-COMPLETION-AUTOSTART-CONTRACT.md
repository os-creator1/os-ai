# RFC-005 Remediation #7 — Automated Implementation Start Contract

## Locked Remediation 7 Autostart

**Purpose:** resume the established subscription-backed implementation loop for the already-authorized, already-created clean Remediation #7 implementation target. This document introduces no product, schema, migration, route, configuration, deployment, tag, or live-environment scope.

### Locked target

- Implementation PR: #159
- Branch: `agent/rfc-005-test-coverage-completion-v2`
- Exact starting head: `e9c69de1532e2e9b2da5cac9192bea75a30b8ef4`
- Merged test-coverage contract: `docs/automation/RFC-005-TEST-COVERAGE-COMPLETION-CONTRACT.md`
- Merged branch correction: `docs/automation/RFC-005-TEST-COVERAGE-COMPLETION-IMPLEMENTATION-BRANCH-CORRECTION.md`
- Production allow-list: empty
- Test allow-list: exactly the 14 existing test files already locked by the merged contract
- Required method work: exactly 22 methods — 15 new and 7 strengthened
- Actual required new paths: zero

### Automatic-start decision

Human merge of this two-file governance PR explicitly authorizes the trusted `AI Subscription Contract Autostart` workflow to place the implementation lease on PR #159, apply `ai:implement`, and fire the existing subscription-backed Claude Routine. This restores the same implementation flow used by the earlier bounded slices; it does not authorize automatic merge or later-slice advancement.

The deterministic GitHub gate is intentionally limited to the 14 individually addressable focused test-file commands because the trusted command parser accepts only one test selector per `php artisan test` invocation. The implementation agent must additionally run and report the merged contract's broader regressions before completion: `php artisan test tests/Feature/Usage tests/Unit/Usage`, `php artisan test --stop-on-failure`, and `git diff --check`. Those broader commands remain completion evidence even though they are not encoded as deterministic-gate commands.

### Governance

- Human-only product merge remains mandatory.
- `advance_automatically` remains false.
- Exact 14-file implementation scope remains mandatory.
- No new file may be created by the implementation; the state file's required-new-path entry is only the existing-file compatibility sentinel required by the trusted validator.
- M6 remains frozen. Remediation #7 completion does not authorize M6.
- The abandoned original branch `agent/rfc-005-test-coverage-completion` remains forbidden.
- No live Stripe/refund/dispute/rate/meter/pilot action, deployment, or tag.
