# Design System M2 — Platform Branding & Assets Remediation Exception

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the remediation it
authorizes — the remediation requires its own separate commit(s), on
PR #94 itself, made only after this document is merged to `main`.**

---

## 0. Governance

- Drafted on branch
  `chore/design-system-m2-platform-branding-remediation-exception`, in
  an isolated linked worktree
  (`../design-system-m2-platform-branding-remediation-exception-worktree`),
  based on `origin/main` at `5fb308c`.
- Concerns Design System M2 Platform Branding & Assets, implemented
  under `docs/automation/DESIGN-SYSTEM-M2-PLATFORM-BRANDING-CONTRACT.md`
  on **PR #94** (`agent/design-system-m2-platform-branding`), current
  implementation head **`0ccb231`**.
- This document changes **exactly one file**: this document itself. No
  `resources/`, `app/`, `database/`, `routes/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #94's own branch at all.

---

## 1. Correction-round budget — exhausted

The contract's own §0 sets `maximum_correction_rounds: 2` for this
implementation. Both rounds have been consumed on PR #94, implementation
head `0ccb231`:

- **Correction round 1** — fixed a duplicate company-name/login-link
  footer rendering defect.
- **Correction round 2** — fixed missing auth-illustration adoption
  (`<x-branding-illustration>` not wired into the authentication
  surface).

Post-round-2 verification confirmed both fixes hold:

- Focused Branding suite (`tests/Feature/Branding/`): **36/36 passed**.
- Full regression: **2852/2852 passed, 0 failures**.

With both contractually-permitted correction rounds already spent, the
contract provides **no further automatic correction mechanism** for any
additional defect discovered on this implementation head. Any further
change to PR #94 requires a new, explicit, human-authorized exception —
exactly what this document is.

---

## 2. Discovered defect — exact record

During post-round-2 verification, after both correction rounds above
were already confirmed passing, one additional genuine defect was found
that neither correction round touched:

**File:** `resources/views/panels/footer.blade.php`

**Defect:** The view renders `<x-branding-footer />` — the contract's
own `BrandingPresenter::footerCopyrightLine()`-backed component, which
already composes the complete, owner-configurable copyright line
(`"© {year} {companyName}. {copyrightWording}"`) — and then
**unconditionally appends** the legacy, hardcoded, localized
`All rights reserved.` string (`locale.labels.all_rights_reserved`)
immediately after it, on every render, regardless of whether the owner
has configured their own `footer_copyright_text`.

**Observed effect:** when an owner configures `footer_copyright_text`,
the rendered result is, for example:

```
© 2026 Acme Test Co. All rights reserved worldwide All rights reserved.
```

The owner's own configured wording (`All rights reserved worldwide`) is
rendered, but is immediately followed by a second, redundant, legacy
`All rights reserved.` suffix that the owner never configured and
cannot remove. This is precisely the class of defect the contract's own
§6.5 and §6.4 were designed to prevent: `BrandingPresenter` already owns
the complete, owner-configurable copyright line end-to-end, and
`<x-branding-footer />` is documented (contract §6.5) as replacing —
not supplementing — the view's prior raw text output. The unconditional
legacy suffix in `footer.blade.php` prevents the owner-configured
wording from actually being authoritative, contradicting the contract's
own §4 locked requirement ("Footer copyright wording (**new** — a
short, owner-editable suffix...)") and its own acceptance criterion
(§10: "sees every one of them rendered in its correct location").

---

## 3. Authorization — one exceptional surgical remediation, and only one

This document **explicitly authorizes exactly one exceptional, surgical
remediation** to PR #94 (`agent/design-system-m2-platform-branding`),
to be performed **after this document is merged to `main`** as a human
decision, fixing the §2 defect and nothing else.

This authorization exists **outside** and **in addition to** the
contract's own two-round correction budget (§1) — it does not reopen,
extend, or reset that budget. It is a one-time, named exception for the
one specific defect recorded in §2, not a general grant of further
correction capacity.

### 3.1 Path scope — closed, exactly two entries

The remediation may touch **only**:

1. `resources/views/panels/footer.blade.php` — required.
2. One **already-existing** Branding test file under
   `tests/Feature/Branding/` (e.g. `BrandingFooterRenderTest.php`) — only
   if extending its existing regression coverage to assert the fixed
   behavior. **No new test file may be created.**

**No other implementation path, and no other test path, is authorized
under this exception.** No `app/`, `config/`, `database/`, `routes/`,
or any other `resources/views/**` file may be touched. If the
remediation is found to genuinely require touching any path outside
this two-entry list, that is itself a stop-and-report condition (§6),
not license to add the path unilaterally.

### 3.2 Required resulting behavior

1. `<x-branding-footer />` is the **sole** rendered copyright/company/
   custom-wording line in the footer. No second fragment, legacy or
   otherwise, may render alongside or after it.
2. **No unconditional legacy `All rights reserved.` suffix** (nor any
   equivalent hardcoded/localized copy) may be appended after
   `<x-branding-footer />`. The `locale.labels.all_rights_reserved`
   string is not referenced in `footer.blade.php` at all following the
   fix.
3. **Unconfigured fallback wording** (the state when an owner has not
   set `footer_copyright_text`) must still be supplied — but through
   `BrandingPresenter`/`<x-branding-footer />` itself (i.e., inside the
   component's own resolution logic, per the contract's existing §6.4
   architecture), **never** by a second Blade fragment in
   `footer.blade.php` or any other view. If achieving this requires a
   change to `BrandingPresenter::footerCopyrightLine()`'s own fallback
   wording, that change is understood to belong to
   `app/Library/Branding/BrandingPresenter.php` — **not** authorized by
   §3.1's path list above, and is therefore itself a stop-and-report
   condition unless the fix is achievable entirely within
   `footer.blade.php` (e.g. simply deleting the extra fragment, since
   the presenter already composes a complete line per the contract's
   own §6.4 design). This exception does not pre-authorize touching
   `BrandingPresenter.php`; if that turns out to be genuinely necessary,
   stop and report per §6 rather than expanding scope unilaterally.
4. **No duplicate** company name, login link, or wording of any kind
   anywhere in the rendered footer output.
5. **No other footer or layout redesign.** The remediation is limited to
   removing/correcting the defective unconditional suffix and, if
   needed, extending one existing test's assertions. No visual,
   structural, or behavioral change to any other part of
   `footer.blade.php` (privacy policy / terms-of-use links, custom
   script block, scroll-to-top button, footer type/hidden-state class
   logic) is authorized.

---

## 4. Required verification after remediation

1. **Focused Branding suite** (`tests/Feature/Branding/`) must be run
   and must pass in full, with a genuine positive test count reported
   (a `0`/`No tests found` result is a failure, not a pass, per the
   repository's own non-negotiable rule).
2. **Full regression suite** must be run and must pass in full, with an
   exact pass count reported (matching the rigor of the post-round-2
   evidence in §1: `2852/2852` was the last confirmed full-regression
   baseline — the post-remediation run must report its own exact count,
   not merely reference the prior one).
3. The **full required manual visual-verification matrix** (contract
   §12: all listed surfaces, at 375px/768px/1440px, in both light and
   dark theme, both unconfigured and owner-configured states) must
   **restart from check 1**, performed fresh against the resulting final
   head after remediation. Prior manual-verification evidence gathered
   before this remediation does not carry forward or satisfy any part of
   this restarted matrix — the footer row specifically must be
   re-verified to confirm no duplicate wording and no unconditional
   legacy suffix in every cell, and every other row must be re-walked in
   full since the matrix is defined as a single, whole-surface pass, not
   a footer-only spot check.

---

## 5. No further correction under this exception

This exception authorizes **exactly one** remediation pass. It is not
renewable, does not reset or extend the contract's own two-round
correction budget, and does not authorize any second attempt at fixing
the §2 defect if the first attempt is incomplete.

**If, during or after this remediation, another genuine defect is
found** — whether a new issue or an incomplete fix of the §2 defect
itself — **PR #94 must stop and be reported for a new, separate human
decision.** It must not be modified again under this document's
authority. A further defect requires its own new exception document (or
other explicit human authorization), exactly as this document itself
was required once the contract's own two correction rounds were spent.

---

## 6. Stop conditions

The remediation must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond §3.1's exact two-entry list (one required, one
  conditional) is found necessary.
- The unconfigured-fallback requirement (§3.2 item 3) cannot be
  satisfied without editing `BrandingPresenter.php` or any file outside
  §3.1.
- Any existing test fails for a reason not fixable within the §3.1 path
  scope.
- Any change beyond removing/correcting the unconditional legacy suffix
  is found necessary to satisfy §3.2.

---

## 7. PR #94 status

**PR #94 remains Draft.** It must not be merged until:

1. This document is itself merged to `main`, and
2. The single authorized remediation (§3) is performed on PR #94, and
3. Both the focused Branding suite and the full regression suite pass
   (§4.1-4.2), and
4. The full manual visual-verification matrix passes, restarted from
   check 1 on the resulting final head (§4.3).

No merge decision is authorized by this document. Merging PR #94 remains
a separate, explicit, future human decision, exactly as implementation
itself required its own separate authorization from the contract.

---

## 8. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/DESIGN-SYSTEM-M2-PLATFORM-BRANDING-REMEDIATION-EXCEPTION.md`,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize Platform Branding remediation exception`.
4. Push normally to
   `origin chore/design-system-m2-platform-branding-remediation-exception`.
   No force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the remediation this document
   authorizes.** Both require this document to be merged first, and the
   remediation itself remains its own separate, explicit action against
   PR #94, not performed as part of this commit.
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of Design System M2 Platform Branding & Assets Remediation
Exception. This document authorizes exactly one surgical remediation to
PR #94, strictly scoped per §3, with no further correction permitted
under its own authority (§5). PR #94 remains Draft until remediation and
verification are complete (§7).*
