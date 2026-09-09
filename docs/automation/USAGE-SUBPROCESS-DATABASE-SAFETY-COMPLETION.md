# USAGE SUBPROCESS DATABASE SAFETY COMPLETION

**Status:** Test-only change. Zero production code, zero migrations, zero
configuration, zero generated assets. Eleven paths, all under `tests/` and
`docs/`.

This branch finishes the work
`docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §7 item 2
explicitly deferred:

> Nine `tests/Feature/Usage/**` tests generate a runner into
> `sys_get_temp_dir()` at runtime and spawn it with no database guard […]
> None hardcodes a name, so none is broken today — they inherit the
> caller's environment, and `phpunit.xml` pins no `DB_DATABASE`. The
> hazard is latent, not active.

That reasoning was right, and it is exactly why this had to be finished
rather than left. A latent hazard is one that has not fired *yet*. These
nine tests spawn real OS processes that write to a database nobody proved
anything about: the child simply inherited whatever the environment
happened to say. Point the suite at the wrong database — or run it with a
stale `DB_DATABASE` in the ambient environment — and nine concurrency
tests would have written into it silently and successfully.

`Tests\Support\TestDatabaseSafety` is treated as **read-only** here. Its
existing API was sufficient; no capability was missing, and it was not
modified. It remains the single database-name validation authority, and
this branch adds no competing policy.

---

## 1. Baseline

| Item | Value |
|---|---|
| `origin/main` at start | `16f53dd5e156a85fe89ae7cf0434bc5bf9845292` |
| PR #228 merge commit | the same commit — it is `origin/main`'s tip, so ancestry is trivially satisfied |
| Branch | `agent/baseline-usage-subprocess-db-safety-completion`, created directly from that SHA |
| Worktree | a **fresh, separate** worktree, `git status` empty at creation. No other lane's worktree was entered |
| Database | `ultimatesms_testing_lane_e`, created for this lane, `migrate:fresh` → **250 migrations, 0 pending** |
| Canonical database | `ultimatesms_testing` was **never** reset, migrated, truncated or written by this lane. Verified still present with 139 tables afterwards |

**A deliberate, disclosed divergence from `AGENTS.md`.** That file says
"Run only against the disposable `ultimatesms_testing` database." This lane
was instructed to use an isolated database instead, and did. The two rules
point the same way — never run destructive suites against anything but a
throwaway database — and PR #228 is what made the isolated form
expressible: `TestDatabaseSafety` accepts `ultimatesms_testing` *or a
clearly derived disposable sibling*. `ultimatesms_testing_lane_e` is such a
sibling, and the helper validates it as one. Using it is what let this lane
run without racing any other lane over the canonical database.

**A second disclosure, about the automation state file.**
`docs/automation/AI-AUTONOMY-STATE.json` currently reads
`gate_label: "ai:paused"`, `implementation_authorized: false`, and
`expected_head_sha: 2132c2dd…` — a commit far behind current `main`. Its
`forbidden_scope` closes with "Any future work requires separate, explicit
human authorization". This branch proceeds on exactly that: a direct,
explicit human instruction naming these eleven paths. The state file was
read completely, as required, and is **not modified** by this branch. It is
stale with respect to `main` — PR #228 itself merged test changes after
that file's recorded head — and reconciling it is not this lane's business.

---

## 2. What changed

### 2.1 The contract, applied identically nine times

Every guarded runner now does this before its first database write:

```php
const WRONG_DATABASE_EXIT_CODE = 3;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    \Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}
```

and every parent hands the database down explicitly:

```php
private function childEnvironment(): array
{
    $database = TestDatabaseSafety::activeTestDatabase();

    return [
        'DB_DATABASE' => $database,
        'EXPECTED_TEST_DATABASE' => $database,
    ];
}
```

This is copied from the merged precedent, not reinvented:
`tests/Feature/Usage/Support/concurrent_conversations_send_runner.php` for
the child half, `ConversationsConcurrencyTest::childEnvironment()` for the
parent half. Same exit code, same message wording, same mandatory-ness.

**Why `EXPECTED_TEST_DATABASE` is mandatory rather than optional.**
`TestDatabaseSafety::assertMatchesActiveTestDatabase()` treats a null or
empty expectation as "no expectation" and only enforces the name policy.
That is correct for the helper — it is a general-purpose comparison — but
it is not sufficient at the boundary. In a spawned child, a missing value
does not mean "no expectation"; it means **the handoff did not happen**, so
the child cannot know which database it is authorized to write to. The
runner therefore refuses first, and only then delegates to the helper. The
mandatory-ness lives in the runner, exactly as the merged precedent puts
it, and the helper is left alone.

### 2.2 The one structural difference from the precedent

The two already-merged runners are **static files**. These nine generate
their runner source at run time into `sys_get_temp_dir()` and spawn that.
The guard therefore had to be injected into the generated source, inside an
interpolating heredoc — which is why it appears in the diff with escaped
`\$` sigils. It is byte-identical in behaviour; §4.1 proves that against
the real generated files rather than against a restatement of them.

`ProviderRefundDisputeConcurrencyTest` generates **two** distinct runner
scripts, and `ConcurrentTopUpConcurrencyTest` builds three scripts from one
shared `baseRunnerPreamble()`. Guarding the preamble covers all three. Ten
guard blocks therefore cover twelve runner variants across nine files.

### 2.3 Exact per-file inventory

| File | Guard blocks | `new Process` calls given the handoff |
|---|---|---|
| `tests/Feature/Usage/AutoRechargeFailedPaymentRetryTest.php` | 1 | 2 |
| `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | 1 (shared preamble, 3 scripts) | 6 |
| `tests/Feature/Usage/PayerAssignmentConcurrencyTest.php` | 1 | 2 |
| `tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php` | 2 | 4 |
| `tests/Feature/Usage/RefundablePaidAvailableAccountingTest.php` | 1 | 2 |
| `tests/Feature/Usage/Slice5/AutoRechargeRollingWindowConcurrencyTest.php` | 1 | 2 |
| `tests/Feature/Usage/UsageWalletBackfillV1ConcurrencyTest.php` | 1 | 4 |
| `tests/Feature/Usage/UsageWalletManagerConcurrencyTest.php` | 1 | 6 |
| `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php` | 1 | 1 |
| **Total** | **10** | **29** |

### 2.4 What was deliberately not touched

Every deleted line in the aggregate diff is a `new Process([...])` line
rewritten to carry the handoff, plus one `]);` closing a multi-line call.
Enumerated and checked one by one:

* **Zero** assertions removed or weakened.
* **Zero** barriers, handshakes, `waitUntil` callbacks or signal files
  changed.
* **Zero** `sleep`/`usleep` added anywhere, and no barrier replaced by one.
* **Zero** concurrency tests serialized.
* **Zero** changes to accounting, idempotency, reservation or lock-ordering
  semantics.
* **Zero** second validation helper, and no competing database-name policy.

---

## 3. The permanent unit suite

`tests/Unit/Support/TestDatabaseSafetyTest.php` — **60 tests, 122
assertions, green.**

PR #228 proved the helper with a temporary suite that it deliberately did
not commit, because the path was outside its own allowlist (its §4.2 says
so). This branch commits that coverage permanently.

It extends `PHPUnit\Framework\TestCase`, not `Tests\TestCase`, on purpose.
The three pure entry points — `isSafeTestDatabaseName()`,
`assertSafeTestDatabaseName()`, and `derivedName()` with an explicit base —
are total functions of their input, touching no container, config or
connection. Booting Laravel to exercise them would add a dependency the
behaviour does not have, and would make the suite unrunnable exactly when
it matters most: when the database is misconfigured. The two
connection-dependent entry points are covered where they actually live —
end to end against real spawned processes, in §4.1.

Coverage includes: the canonical name; nine accepted sibling shapes;
non-string inputs of four types; the empty string; names that merely
contain "test"; all eight forbidden suffix segments, each also checked as
an *ordinary* substring that must still be accepted (`ultimatesms_testing_domain`,
`_reproduction`, `_delivery`); unsafe characters both before and after the
underscore separator, which take different branches and report different
reasons; the 64-character identifier limit at and past the boundary; the
exact refusal-message wording; and that the class is still `final`.

One characterisation worth recording, because it is easy to assume wrongly:
`ultimatesms_testing;DROP` is refused as *"not the canonical test database
or a suffixed sibling of it"*, **not** as a suffix-pattern violation — it
never reaches the pattern, because it does not carry the `_` separator.
`ultimatesms_testing_lane;DROP` is the one that hits the pattern. Both are
refused; the suite asserts each against the reason it actually produces.

---

## 4. Verification

All runs against `ultimatesms_testing_lane_e`, 250 migrations, 0 pending.

### 4.1 The guard, proven against the real generated runners

The runner source was extracted by reflectively invoking the **same private
builder method the test itself calls**, so the probed bytes are exactly the
bytes the test spawns. Twelve runner variants × six cases = **72 probes**.

Every refusal case additionally carries a **row-count sentinel**: the row
counts of nine usage tables are snapshotted immediately before and after
each probe. `UNCHANGED` is what proves the refusal happened *before the
first write*, and it is only meaningful because the positive controls show
the same runners **do** change those counts when the handoff is correct.

| Case | Handoff | Expected | Result across all twelve runners |
|---|---|---|---|
| 1 | `EXPECTED_TEST_DATABASE` unset | refuse | exit **3**, *"was not handed down by the parent test"*, sentinel UNCHANGED |
| 2 | set to empty string | refuse | exit **3**, same message, sentinel UNCHANGED |
| 3 | mismatched but safe (`ultimatesms_testing_other`) | refuse | exit **3**, *"a spawned child must resolve the very same disposable database"*, sentinel UNCHANGED |
| 4 | `acme_test_live` — merely contains "test" | refuse | exit **3**, *"not the canonical test database or a suffixed sibling of it"*, sentinel UNCHANGED |
| 5 | `ultimatesms_testing_prod` — production-looking canonical sibling | refuse | exit **3**, *"its suffix contains the segment \"prod\""*, sentinel UNCHANGED |
| 6 | correct validated handoff | proceed | reaches the intended runner mode; positive controls show rows change |

Runners probed: `autorecharge_failed_payment_retry`,
`autorecharge_rolling_window`, `concurrent_topup_confirm`,
`concurrent_topup_hold`, `payer_assignment`, `provider_refund_dispute`,
`provider_refund_dispute_diffcum`, `refundable_paid_available`,
`usage_wallet_backfill_v1`, `usage_wallet_manager`,
`usage_wallet_manager_set_active_rate` — covering all nine files.

**Cases 4 and 5 carry a second, independent proof.** Both set
`DB_DATABASE` to the refused name as well as `EXPECTED_TEST_DATABASE`, and
neither database exists on the server. Had the guard not fired before the
first write, the failure would have been a driver connection error — a
different exit code and a different message. Exit 3 with a *name-policy*
message is only reachable if the refusal happened before any connection was
attempted.

### 4.2 No silent fallback to the canonical database

Case 1 is the direct test of this. With `EXPECTED_TEST_DATABASE` absent,
the old behaviour would have been to run against whatever the environment
supplied. The guarded runners refuse instead. `ultimatesms_testing` is
never reachable as an implicit default from any of the nine.

### 4.3 The guard never wrongly refused

Across the **entire 5 178-test repository regression**, this branch's three
refusal messages appear **zero** times:

| Wording | Occurrences in the full log |
|---|---|
| *"was not handed down by the parent test"* | **0** |
| *"must resolve the very same disposable database"* | **0** |
| *"suffixed sibling of it"* | **0** |

The string `Refusing to run` does appear 11 times, and every one of them is
the **old hardcoded pin** — *"expected [ultimatesms_testing]"*, wording this
branch does not produce — in `EntitlementManagerConcurrencyTest`,
`OpportunityManagerBeginRunTest` and `WorkspaceTransitionsMigrationSchemaTest`.
Those are precisely the Workspace/Entitlement canonical-name pins this lane
was instructed **not** to repair. They fail only because this lane runs on an
isolated database, which is the very problem PR #228 set out to fix and which
those files have not yet adopted.

### 4.4 Repeated concurrency runs

Each of the nine, run **individually, ten consecutive times**:

| Test file | Runs | Result |
|---|---|---|
| `AutoRechargeFailedPaymentRetryTest` | 10 | 10/10 identical — 10 tests, 35 assertions |
| `ConcurrentTopUpConcurrencyTest` | 10 | 10/10 identical — 4 tests, 22 assertions |
| `PayerAssignmentConcurrencyTest` | 10 | 10/10 identical — 1 test, 3 assertions |
| `ProviderRefundDisputeConcurrencyTest` | 10 | 10/10 identical — 4 tests, 31 assertions |
| `RefundablePaidAvailableAccountingTest` | 10 | 10/10 identical — 14 tests, 57 assertions |
| `Slice5/AutoRechargeRollingWindowConcurrencyTest` | 10, then **20** | 9/10 first sample; **20/20 identical** on a clean re-sample — 1 test, 10 assertions |
| `UsageWalletBackfillV1ConcurrencyTest` | 10 | 10/10 identical — 2 tests, 6 assertions |
| `UsageWalletManagerConcurrencyTest` | **20** | **20/20 identical** — 3 tests, 15 assertions |
| `UsageWalletManagerSetActiveRateConcurrencyTest` | 10 | 10/10 identical — 2 tests, 18 assertions |

Every file clears the ten-consecutive-identical-passes bar, and the two that
were re-sampled clear twenty.

**The one thing worth reporting honestly.** When all nine run *together in a
single PHPUnit process*, an occasional flake appears — a child announcing
neither `LOCKED` nor `WAITING` inside its handshake deadline. Measured:

| Configuration | Combined-set flake rate |
|---|---|
| This branch | 3 of 10 runs |
| Pristine `origin/main`, same protocol, same database | 1 of 10 runs |
| `UsageWalletManagerConcurrencyTest` alone, this branch | **0 of 20** |
| `Slice5/AutoRechargeRollingWindowConcurrencyTest` alone, this branch | **0 of 20** |

**This is not caused by the guard, and the evidence is specific rather than
reassuring.** The symptom exists on pristine `main` — the PR #228 baseline log
captured on this same machine shows the identical
*"Holder process never confirmed its lock"* failure in the same class, with
this branch's files absent. Every affected test is stable in isolation over
twenty runs. The guard adds a `getenv()` and one config read; it opens no
connection, because `DB::connection()->getDatabaseName()` reads the resolved
configuration rather than connecting. And the deadlines involved are wall-clock
handshake windows (15 seconds for both children to announce readiness) on a
Windows host that must boot the whole framework twice per test. 3-of-10 against
1-of-10 at n=10 is not a distinguishable difference; the isolated 0-of-20 and
0-of-20 results are the stronger measurement, and both point the same way.

No barrier was touched, no sleep was introduced, and nothing was serialized to
make these numbers look better.

### 4.5 Head-to-head against pristine `origin/main`

Same machine, same isolated database, same migration state, and a
`migrate:fresh` immediately before **each** side so neither inherits the
other's residue. The only variable is this branch's diff.

| Suite | pristine `main` | this branch | delta |
|---|---|---|---|
| `tests/Unit/Usage` | 21 tests, 46 assertions, green | 21 tests, 46 assertions, green | **identical** |
| `tests/Feature/Usage/Slice5` | 92 tests, 1 082 assertions, green | 92 tests, 1 082 assertions, green | **identical** |
| `tests/Feature/Usage` | 998 tests, 5 190 assertions, green | 998 tests, 5 190 assertions, green | **identical** |
| `tests/Unit/Support` | does not exist | 60 tests, 122 assertions, green | **+60 tests, +122 assertions** |

Zero new errors, zero new failures, zero new risky tests. The only delta is
the permanent unit suite this branch adds.

**A note on an earlier, discarded baseline.** A first `tests/Feature/Usage`
baseline taken before the database was re-freshed reported 83 errors and 1
failure. All 83 had a single cause —
`BusinessCurrencyUnresolvableException: … ambiguous` — from duplicate active
currency rows accumulated by earlier runs against that database, not from any
code. Re-running both sides from `migrate:fresh` removed it on both sides,
which is why the table above is green on both. The discarded figure is
recorded here rather than omitted, because a reader comparing logs would
otherwise find it unexplained.

### 4.6 Full repository regression

Mechanically feasible, so it was run — **on both sides**, each from
`migrate:fresh` *and* from an identically clean generated-asset state.

| | pristine `main` | this branch | delta |
|---|---|---|---|
| Tests | 5 118 | 5 178 | **+60** — exactly the new unit suite |
| Assertions | 26 094 | 26 216 | **+122** — exactly the new unit suite |
| Errors | 3 | 3 | **0** |
| Failures | 25 | 25 | **0** |

The failing-test **name sets are identical**. Diffed both ways:

* only on this branch: **none**
* only on pristine: **none**

All 28 shared failures and errors sit in classes this branch never touches:
`EntitlementManagerConcurrencyTest` (8), `ViewAsClientTest` (2),
`WebsiteDraftPageServiceSeamTest` (2), and one each in
`WorkspaceTransitionsMigrationSchemaTest`, `WorkspaceManagerTest`,
`WorkspaceManagerConcurrencyTest`, `WebsiteDraftPublishTest`,
`Public\WebsiteIndexingTest`, `CustomerShellTranslationTest`,
`CustomerShellLayoutTest`, `OutreachSecurityTest`,
`OpportunityManagerBeginRunTest`, `CustomerShellNavigationTest`,
`BusinessKnowledgeProfileSeamTest`, `BusinessKnowledgeProfileHoursTest`,
`BusinessKnowledgeProfileControllerTest`, `BusinessDataTenancyDualWriteTest`,
`BrandingAdminFooterRenderTest` and `AuthBrandTenantIsolationTest` — the
Workspace/Entitlement canonical-name pins, the theme-tokens asset, and the
other unrelated failures this lane was told to leave alone. **Zero come from
the nine guarded files or the new unit suite.**

**A first branch run reported 33 failures rather than 25, and that is worth
recording rather than quietly dropping.** The eight extras were all in
`AuthNeutralBrandingTest` and `BrandingComponentRenderTest`. They were caused
by runtime-generated branding assets (`public/images/branding/logo_compact/`,
`public/images/websites/`) that the run itself created and that were present
when those tests executed — not by this diff. Proof: re-running exactly those
two classes against the branch working tree gives **18 tests, 374 assertions,
green**, and the clean-state re-run above reproduces pristine's 25 exactly.
Both sides are therefore reported from the same clean generated-asset state.
This is also why §5 insists on inspecting `public/` before and after every
major run.

---

## 5. Generated-file hygiene

`vendor/` was copied from the main checkout after confirming
`composer.lock` is **byte-identical** between the two, then
`composer dump-autoload` was run. `vendor/`, `.env` and the lane database
are all outside version control; `git check-ignore` confirms `.env` is
ignored.

`bootstrap/cache/packages.php` and `bootstrap/cache/services.php` are
**tracked** files that Laravel rewrites whenever `artisan` or
`composer dump-autoload` runs. Both were regenerated on disk during this
lane, purely as a side effect of preparing and running the verification in
§4 — no edit was ever made to either by hand.

They were handled in three steps:

1. **Regenerated temporarily for verification.** Running the suites
   required a working autoloader and package manifest in this fresh
   worktree, so `composer dump-autoload` and `artisan` rewrote both files.
2. **Excluded from the implementation commit.** Every path in
   `0c578de` was staged by name; neither cache file was ever staged, and
   neither appears in the branch diff.
3. **Restored explicitly to `HEAD` after all testing completed**, with a
   path-scoped command naming only these two files:

   ```
   git restore --source=HEAD -- bootstrap/cache/packages.php bootstrap/cache/services.php
   ```

   No broad `checkout`, `clean`, or `reset` was used, and no other file was
   touched. Both then hashed byte-identical to `HEAD`:
   `packages.php` → `6779c61d…`, `services.php` → `eeb76a39…`.

**The final worktree contains no modified, staged or untracked paths** —
`git status --short` is empty.

**Correcting an earlier claim in this document.** A previous revision
stated that leaving these two tracked files modified was "the correct
handling", on the reasoning that restoring generated artifacts has
previously broken view-rendering suites in this repository. That reasoning
does not apply here and the conclusion was wrong. Restoring a tracked file
to *this branch's own committed content* is not the hazardous case; the
hazardous case is restoring a stale artifact that no longer matches the
code around it. Leaving tracked modifications behind is not an acceptable
final state under the project's operating rules, regardless of whether they
were staged. The files are restored, and this section records what was
actually done rather than defending the shortcut.

No `git add -A` was used at any point. Every path was staged by name.

---

## 6. Scope

Exactly eleven paths, matching the allowlist with nothing added:

1. `docs/automation/USAGE-SUBPROCESS-DATABASE-SAFETY-COMPLETION.md` *(this file)*
2. `tests/Feature/Usage/AutoRechargeFailedPaymentRetryTest.php`
3. `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php`
4. `tests/Feature/Usage/PayerAssignmentConcurrencyTest.php`
5. `tests/Feature/Usage/ProviderRefundDisputeConcurrencyTest.php`
6. `tests/Feature/Usage/RefundablePaidAvailableAccountingTest.php`
7. `tests/Feature/Usage/Slice5/AutoRechargeRollingWindowConcurrencyTest.php`
8. `tests/Feature/Usage/UsageWalletBackfillV1ConcurrencyTest.php`
9. `tests/Feature/Usage/UsageWalletManagerConcurrencyTest.php`
10. `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php`
11. `tests/Unit/Support/TestDatabaseSafetyTest.php`

Zero changes under `app/`, `public/`, `bootstrap/cache/`, `vendor/`,
`node_modules/`, `database/`, `routes/`, `config/`, `resources/`, dependency
files, generated assets, `tests/Support/TestDatabaseSafety.php`, the merged
Conversations/Slot Agreement/Blacklists files, Workspace/Entitlement files,
`AGENTS.md`, `CLAUDE.md`, or `AI-AUTONOMY-STATE.json`.

Deliberately **not** repaired here, as instructed: the Workspace/Entitlement
canonical-name pins, the missing theme-tokens asset, `.env` isolation,
`Controllers.zip`, and unrelated production failures.
