# Implementation Contract 14 — Dead-Model Cleanup

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contract 13 being merged first, and implicitly on every one of
Contracts 1–13 having already retired the callers this contract removes.
**This contract does not pre-compute a final deletion list** — by design,
per §4 — because every count in §3 will have changed by the time this
slice actually runs.

## 1. Objective

Delete the code and tables Contracts 1–13 have, by then, made genuinely
dead — `createBusinessInWorkspace()`'s existing-Workspace path,
`reassignBusiness()`, `SwitchBusinessAction`, the Business-switcher UI,
`workspace_membership_businesses`, `additional_business_slot_*` tables,
and the same-Workspace-only View As path — **verified dead at deletion
time**, never assumed dead because the Roadmap labeled it so.

## 2. Governing authority

- Addendum §18 step 8.
- Roadmap Slice 14.
- Every prior contract (1–13) — this slice's job is entirely defined by
  what they left behind.

## 3. Current repository reality — baseline reference counts (as of this contract's writing, on `main`, BEFORE any of Contracts 1–13 have shipped)

Confirmed via `git grep -c`, counting **files**, not raw occurrences —
every one of these is genuinely, substantially referenced today, proving
none of them are already dead and this slice's ordering (strictly last)
is load-bearing, not a formality:

| Symbol/table | Files referencing it today |
|---|---|
| `SwitchBusinessAction` | 6 |
| `createBusinessInWorkspace` | 12 |
| `reassignBusiness` | 20 |
| `workspace_membership_businesses` | 17 |
| `additional_business_slot_agreements` | 21 |
| `customer.context.business.switch` (route name) | 9 |

**These numbers are baseline evidence only — not the deletion
specification.** By the time Contract 14 actually runs, Contracts 1–13
will have removed or redirected most of these references (View As's old
path replaced by Contract 04's new one and finally deleted here; the
Business-switcher retired once Contract 07/08A's Client-Workspace flow
replaces its purpose; `workspace_membership_businesses` vestigial once
Contract 02/08B's Location ACL fully replaces it and Contract 13 makes
multi-Business structurally impossible; `additional_business_slot_*`
already frozen and resolved by Contract 11). **This contract's own first
action must be to re-run these exact `git grep` queries against the
actual `main` at implementation time** and build the real inventory from
that result — not from this baseline table.

## 4. Delta from current state to target — methodology, not a fixed list

This is the one contract in the series whose §4 is a **procedure**, not a
predetermined change set, precisely because "nothing is deleted merely
because the roadmap calls it dead" (the deep-dive's own instruction).

**Required procedure per candidate symbol/table:**
1. Re-run `git grep` for the exact symbol across `app/`, `resources/`,
   `routes/`, `tests/`.
2. For every remaining reference, classify it: (a) a genuine remaining
   caller (STOP — this candidate is not actually dead yet; report why and
   do not delete it), (b) a test that exercises the old path
   specifically because it's testing the old path's own now-retired
   behavior (delete the test alongside the code), (c) a comment/docblock
   mention (safe to leave or clean up, not a blocker).
3. Only delete a symbol/table once step 2 finds **zero** category-(a)
   references.
4. Delete in dependency order: UI/controller callers first, then the
   library method/class itself, then the underlying table (a table drop
   is the last, most irreversible step for each candidate — order matters
   independently per candidate, not just globally).

## 5. Data model contract

**Table drops, each gated by its own zero-reference proof (§4) and,
additionally, a zero-row proof** (the table must be both unreferenced in
code *and* empty, or its non-empty state explicitly reviewed and accepted
as historical data to archive-export before dropping — never dropped with
live rows merely because code no longer references it):
- `workspace_membership_businesses`
- `additional_business_slot_agreements`, `additional_business_slot_renewal_charges`, and any sibling transition table (exact set to be confirmed at implementation time via the same reference-count methodology, not assumed complete from this contract's own list)

## 6. Authority / security contract

Not applicable — code/schema deletion, no new actor-facing behavior.

## 7. Transaction / concurrency boundary

Table drops are standard migration DDL, same non-transactional caveat as
Contract 13 §7 — sequenced strictly after every other migration in this
series, with no concurrent write traffic expected against these
already-fully-retired tables by this point.

## 8. Migration / backfill

Not a backfill — pure removal. **Zero-row verification query per table**
before its drop migration runs (e.g. `SELECT COUNT(*) FROM
workspace_membership_businesses` must return 0, or the migration refuses
to proceed, mirroring every prior contract's own "assert before you
destroy" discipline).

## 9. Backwards compatibility

None applicable — this is explicitly the point in the roadmap where
backward compatibility with the old model is intentionally ended, and
only after every earlier contract has already provided the replacement.

## 10. Events / audit

No new events. Deleting `WorkspaceMembershipBusinessAssigned`/`Unassigned`
(the events tied to the retired pivot) is itself one of this slice's
candidate deletions, subject to the same §4 procedure.

## 11. Billing/provider safety

Not applicable to the code/schema deletion itself. Indirectly: this slice
must **not** run before Contract 11's own commercial-treatment resolution
(§8, Contract 11) — deleting `additional_business_slot_agreements` while
an unresolved paid holder still exists would be the exact silent-data-loss
failure Contract 11 was built to prevent; this slice's own §4 procedure
(zero-row proof) is what catches this if Contract 11 somehow left rows
behind, but Contract 11 itself is the real safeguard, not this slice.

## 12. Exact implementation allowlist

**Cannot be fully enumerated now** (§4) — the true file list is a
function of what Contracts 1–13 actually shipped. What can be stated now:
- **Category:** application code files (controllers, library classes,
  Blade views) currently referencing the six baseline symbols in §3, once
  re-verified dead.
- **Category:** new `drop_*` migrations for each table in §5, each gated
  by its own zero-row precondition query mirroring Contract 13's exact
  style.
- **Category:** test files exercising exclusively the old, now-removed
  paths (deleted alongside their subject code, per §4 step 2(b)).

**Explicit non-goal for this allowlist:** do not delete anything not
found dead by the §4 procedure, even if it appears in §3's baseline list
— that list is a starting point for investigation, not a delete manifest.

## 13. Required tests

No new *feature* tests are added by this slice (it removes code, it
doesn't add behavior) — instead, run the **full remaining regression**
for every domain touched (Workspace, Agency, Conversations, Contacts,
Opportunities) to confirm nothing outside the verified-dead set broke.

## 14. Acceptance criteria

1. Every deleted symbol/table passed the full §4 zero-reference
   procedure, documented per candidate (not merely "the roadmap said so").
2. Every dropped table passed a zero-row precondition.
3. Full regression suite for every touched domain passes.
4. `git diff --check` clean.

## 15. Non-goals

Does not delete anything Contracts 1–13 have not actually made dead by
the time this slice runs. Does not retroactively change any earlier
contract's own scope. Does not touch product modules outside the Agency/
tenancy migration's own scope (Calendar, Packages, Proposals, etc. — never
part of this cleanup).

## 16. Merge prerequisites

Contract 13 merged (hard — the DB constraint must already be live before
the code paths that could violate it are removed, not the reverse, so
that if cleanup is somehow incomplete, the DB constraint still catches
any residual bug independently).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Every prior contract (1–13) | this slice deletes what they made dead | Serialize — strictly last |

## 18. Implementation prompt

```
You are implementing Slice 14 of the V1 architecture migration for the
os-creator1/os-ai repository: dead-model cleanup, per docs/product/
implementation-contracts/14-DEAD-MODEL-CLEANUP.md -- the final slice in
this series.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contract 13 is merged -- hard prerequisite. Verify, to the best
   of your ability, that Contracts 1-12 have also all shipped (this slice
   assumes the full series is complete; if any earlier slice is still
   outstanding, STOP and report, since deleting code that an unshipped
   slice's replacement doesn't yet cover would be destructive).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-14-dead-model-cleanup).
4. Re-read the full contract, especially SS3's explicit warning that its
   own baseline reference counts are stale by design and SS4's required
   procedure.
5. Re-run the exact git grep queries in SS3 against the actual current
   main. Build your own real, current inventory -- do not use SS3's
   numbers as anything but historical context for why this ordering
   matters.

For EVERY candidate symbol/table: follow SS4's procedure exactly. Any
remaining genuine caller found means that candidate is NOT deleted in
this pass -- report it and move on, do not force a deletion by also
ripping out the caller unless that caller is itself unambiguously part of
the old model this migration is retiring (and if you are not sure, STOP
and report rather than guessing). For every table drop, run the zero-row
precondition query first and refuse to proceed if it's non-zero.

After implementing:
- Run the full regression suite for Workspace, Agency, Conversations,
  Contacts, and Opportunities domains.
- Run git diff --check.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files/tables deleted,
exact candidates found NOT yet dead (and why, if any), exact tests run
and counts, and confirmation every dropped table passed its zero-row
check. This is the last slice in the series -- after this report, the V1
architecture migration's tenancy/Agency portion is structurally complete
per the Blueprint and Addendum.
```
