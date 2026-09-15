# Implementation Contract 13 — Enforce Workspace:Business 1:1

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contracts 10 and 12 both being merged, run, and verified with a
**zero-violation** result immediately before this migration runs.

## 1. Objective

Add the unique constraint on `businesses.workspace_id` — the final schema
enforcement step, mirroring the exact precondition-then-DDL discipline
`2026_07_30_120006_enforce_business_workspace_constraint.php` already
established for the `NOT NULL` constraint on the same column.

## 2. Governing authority

- Addendum §1, §18 step 7.
- Roadmap Slice 13.
- Contracts 10 and 12 (this slice's own precondition — their combined
  "zero remaining violations" result).

## 3. Current repository reality

**`database/migrations/2026_07_30_120006_enforce_business_workspace_
constraint.php`** (full file read) — the **exact template** this slice's
migration mirrors:
- Two plain `SELECT`-based precondition checks run **before any DDL**,
  each throwing a specific, typed exception on failure
  (`WorkspaceBackfillIncompleteException`, `DanglingWorkspaceReferenceException`)
  — "neither relies on an opaque MySQL constraint-addition error as the
  primary signal" (the migration's own stated design principle, adopted
  verbatim here for the new uniqueness check).
- **Explicit, load-bearing operational caveat, quoted exactly because it
  applies identically to this slice**: *"This migration does not itself
  close the window between its own precondition queries and the
  subsequent DDL: MySQL DDL is not transactional, so a Business write
  landing in that window is a deployment-process concern, not something
  this migration can self-enforce. Business-write traffic must be
  quiesced for the duration of this migration when it is applied to a
  live database."* This slice's migration must carry the **identical**
  caveat and operational requirement — adding a `UNIQUE` constraint has
  exactly the same non-transactional-DDL race window as the `NOT NULL`
  change did.
- Separate `Schema::table()` calls per DDL step, in a specific order
  (index before foreign key, so InnoDB reuses the named index rather than
  creating a redundant implicit one) — this slice's `UNIQUE` index has no
  analogous foreign-key-ordering concern (it's the only new object), so
  this specific ordering detail doesn't transfer, but the **general
  discipline of one clearly-commented `Schema::table()` call per logical
  step** does.
- `businesses.workspace_id` is already `unsignedBigInteger`, `NOT NULL`,
  with a plain index (`businesses_workspace_id_index`) and a `restrictOnDelete`
  FK — **no NULL-handling concern for the new UNIQUE index** (MySQL
  treats multiple `NULL`s in a unique index as distinct, but this column
  cannot be `NULL` at all since the prior migration already enforced
  `NOT NULL` — confirmed, not assumed).

## 4. Delta from current state to target

**Changes:** one migration adding `UNIQUE` on `businesses.workspace_id`,
preceded by a precondition query proving zero Workspaces currently have
more than one Business.

**Explicitly does NOT change:** the existing plain index
(`businesses_workspace_id_index`) — MySQL permits a `UNIQUE` index and a
plain index on the same column to coexist, but the plain index becomes
redundant once the unique one exists (a unique index already serves every
lookup the plain one did); **recommend dropping the now-redundant plain
index in the same migration**, mirroring the precedent migration's own
"avoid redundant indexes" principle (stated there for the FK/index
ordering, generalized here to redundant-index avoidance) — flagged as a
design choice for implementation-time confirmation rather than assumed
required.

## 5. Data model contract

**Precondition query (mirroring the precedent's exact style):**
```php
$violatingWorkspaceIds = DB::table('businesses')
    ->select('workspace_id')
    ->groupBy('workspace_id')
    ->havingRaw('COUNT(*) > 1')
    ->pluck('workspace_id')
    ->all();

if ($violatingWorkspaceIds !== []) {
    throw new MultipleBusinessesPerWorkspaceException($violatingWorkspaceIds);
}
```

**DDL:**
```php
Schema::table('businesses', function (Blueprint $table) {
    $table->dropIndex('businesses_workspace_id_index'); // now redundant -- see SS4
});

Schema::table('businesses', function (Blueprint $table) {
    $table->unique('workspace_id', 'businesses_workspace_id_unique');
});
```

**`down()`**, mirroring the precedent's own reversal style exactly:
```php
Schema::table('businesses', function (Blueprint $table) {
    $table->dropUnique('businesses_workspace_id_unique');
});

Schema::table('businesses', function (Blueprint $table) {
    $table->index('workspace_id', 'businesses_workspace_id_index');
});
```

## 6. Authority / security contract

Not applicable — this is a schema-only migration with no actor-facing
authorization surface. The "authority" that matters here is **operational
sign-off** to run this migration against a real database, governed by
this repository's own route-3 migration-authorization rules — not
something this contract itself grants.

## 7. Transaction / concurrency boundary

**Explicitly non-transactional at the DDL layer** (§3's carried-forward
caveat) — the precondition query and the DDL are not atomic with respect
to concurrent writes. **Required operational step, stated as part of this
slice's own scope, not merely a footnote:** Contract 10 and Contract 12's
own migration commands (or an equivalent write-freeze mechanism) must
confirm no in-flight Business-creation/reassignment operation can land
between this slice's precondition check and its DDL — the same
"quiesce writes" requirement the precedent migration already states.

## 8. Migration / backfill

**This slice's precondition query *is* the required "zero-violation
assertion"** the deep-dive names explicitly. It is deliberately
**independent** of Contract 10/12's own internal verification steps — a
fresh, direct query against `businesses`/`workspace_id` immediately before
this migration's DDL, not merely trusting that Contract 10/12 "reported
success" earlier. This is the same defense-in-depth principle the
precedent migration itself demonstrates (its own two independent
precondition queries, not trusting the backfill migration's own earlier
success report).

## 9. Backwards compatibility

None applicable in the usual sense — this is the intentional, final
narrowing this entire roadmap has been building toward. Every Workspace
that Contracts 10/12 correctly processed is unaffected (already exactly
one Business); this migration only has any effect at all if the
precondition finds a violation, in which case it refuses to run rather
than silently truncating data.

## 10. Events / audit

None — a schema migration, not a business-logic event.

## 11. Billing/provider safety

Not applicable — no payer/wallet/provider interaction.

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_2x_100012_enforce_workspace_business_one_to_one_constraint.php`
- `app/Exceptions/Workspace/MultipleBusinessesPerWorkspaceException.php`
- `tests/Feature/Workspace/WorkspaceBusinessOneToOneEnforcementTest.php` (mirroring `WorkspaceEnforcementMigrationTest.php`'s own naming/shape convention, confirmed to exist as a precedent test file in the original audit's test inventory)

**No existing file modified.**

## 13. Required tests

`WorkspaceBusinessOneToOneEnforcementTest.php`: precondition correctly
detects a seeded violation and refuses to run the DDL (proving the
migration fails loudly, not silently, exactly matching this deep-dive's
own explicit requirement); zero-violation case runs cleanly; the unique
constraint, once applied, actually rejects a direct-insert attempt at the
database layer (a true DB-level test, not merely an application-level
assertion — this is the whole point of this slice, that even a
hypothetical future application bug can't violate the invariant); `down()`
correctly reverses.

## 14. Acceptance criteria

1. The precondition query correctly identifies a seeded violation and the
   migration throws rather than proceeding.
2. The unique constraint is live and provably enforced at the DB layer
   (direct-insert test, not just application-level).
3. `WorkspaceManager::createBusinessInWorkspace()` (if not yet removed by
   this point — Contract 14 does that) now fails at the DB layer as a
   final backstop for an existing Workspace, confirming the constraint
   genuinely closes the seam even if application code has a residual bug.
4. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not remove `WorkspaceManager::createBusinessInWorkspace()`,
`reassignBusiness()`, or any other now-partially-dead code (Contract 14).
Does not itself run Contract 10/12's migrations — assumes, and
independently re-verifies (§8), that they have already run successfully.

## 16. Merge prerequisites

Contracts 10 and 12, both merged, run, and independently verified
zero-violation immediately before this migration executes.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 10, 12 | this slice's precondition depends on their outcome | Serialize (hard prerequisites) |
| Contract 14 | removes code this constraint makes newly enforceable at the DB layer | Serialize, immediately after |

## 18. Implementation prompt

```
You are implementing Slice 13 of the V1 architecture migration for the
os-creator1/os-ai repository: enforcing the DB-level Workspace:Business
1:1 constraint, per docs/product/implementation-contracts/
13-ENFORCE-WORKSPACE-BUSINESS-ONE-TO-ONE.md. This is the single point in
the entire roadmap where an irreversible-in-practice narrowing happens --
treat it with the same caution this repository's own governance requires
for any destructive-adjacent database operation.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 10 and 12 are BOTH merged to main, AND confirm with
   the user/operator that their migrations have actually been run and
   independently verified as zero-violation against the actual target
   database -- this contract's SS8 requires this slice's own precondition
   query to be an independent re-check, not a trust of an earlier report.
   If you cannot confirm this, STOP and report rather than proceeding.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-13-enforce-workspace-business-1-1).
4. Re-read the full contract, especially SS3's carried-forward
   non-transactional-DDL caveat and SS7's write-quiescing requirement --
   these are not optional footnotes.
5. Re-read 2026_07_30_120006_enforce_business_workspace_constraint.php in
   its current actual state on main and confirm it still matches this
   contract's SS3 description before mirroring its style.

Implement exactly the scope in this contract: the precondition-then-DDL
migration, the new typed exception, dropping the now-redundant plain
index in the same migration (SS4/SS5). Do NOT run this migration against
any real or production-looking database yourself. Do NOT touch
WorkspaceManager or any other application code (Contract 14).

After implementing:
- Run the new focused test file, including a true DB-level insert-
  rejection test, not just an application-level assertion.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit confirmation the precondition query was
tested against both a violating and a clean fixture. Do NOT begin or
authorize Contract 14, and do NOT run this migration against any real
data yourself.
```
