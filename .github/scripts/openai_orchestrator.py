#!/usr/bin/env python3
"""
Bounded AI orchestration pilot helper for RFC-003 Milestone 3.

Talks to the OpenAI Responses API to produce a strictly-validated decision
object, and safely executes the focused PHPUnit/Artisan test commands that
decision names. Everything here is stdlib-only by design: no third-party
packages are installed at CI runtime, which keeps the pilot's dependency
surface (and therefore its attack surface) as small as possible.

Security invariants this file exists to uphold:
  - OPENAI_API_KEY is read from the environment only, never accepted as a
    CLI argument, never logged, and redacted from any error text.
  - Model output is treated as untrusted data. It is schema-validated before
    any field is used, and `claude_prompt` is additionally scanned for
    attempts to escape the bounded slice (merges, main-branch writes,
    workflow-file edits, out-of-scope product work).
  - Test commands are never run through a shell. Each candidate command is
    parsed, checked against a strict allow-list, and executed via
    subprocess.run(argv, shell=False) or not at all.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import subprocess
import sys
import urllib.error
import urllib.request
import uuid

API_URL = "https://api.openai.com/v1/responses"
MODEL = "gpt-5.6-luna"
MAX_OUTPUT_TOKENS = 2500

ALLOWED_DECISIONS = {"IMPLEMENT", "FIX", "READY", "NEEDS_HUMAN"}
ALLOWED_SEVERITIES = {"critical", "high", "medium", "low"}

# Context inputs are capped so a runaway diff or doc can't blow the token
# budget or smuggle unrelated repository content into the prompt.
MAX_CONTEXT_CHARS = 60_000

DECISION_JSON_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "decision",
        "summary",
        "claude_prompt",
        "required_tests",
        "blocking_questions",
        "review_findings",
    ],
    "properties": {
        "decision": {
            "type": "string",
            "enum": sorted(ALLOWED_DECISIONS),
        },
        "summary": {"type": "string"},
        "claude_prompt": {"type": "string"},
        "required_tests": {"type": "array", "items": {"type": "string"}},
        "blocking_questions": {"type": "array", "items": {"type": "string"}},
        "review_findings": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["severity", "file", "finding", "required_change"],
                "properties": {
                    "severity": {"type": "string", "enum": sorted(ALLOWED_SEVERITIES)},
                    "file": {"type": "string"},
                    "finding": {"type": "string"},
                    "required_change": {"type": "string"},
                },
            },
        },
    },
}

# Given verbatim by the human operator as the locked Slice 3B product
# contract. The planning-stage model is told to bound its instruction to
# exactly this contract, not to invent scope from the RFC on its own. Every
# product decision that would otherwise require judgment -- route name, page
# structure, field set, ordering, owner-row treatment -- is deliberately
# pre-decided here so the plan stage never has grounds to return
# NEEDS_HUMAN for anything other than a real repository contradiction.
SLICE_3B_CONTRACT = """\
Locked Slice 3B product contract (RFC-003 Milestone 3):

ROUTE AND PAGE
- Add exactly one new customer GET route:
  name: customer.workspaces.show
  URI: workspaces/{workspaceUid}
- This route renders one read-only Workspace overview page.
- The membership directory is part of that same overview page -- not a
  separate page, route, or endpoint.
- Do not add a customer.workspaces.members.index route.
- Do not add a customer.workspaces.businesses.index route.
- Do not add any POST, PUT, PATCH, or DELETE Workspace route.

OVERVIEW CONTENT
The overview section displays exactly:
- Workspace name
- Workspace status: Active or Inactive
- current user's effective Workspace role: Owner, Admin, or Staff
Do not display: Workspace numeric ID, owner numeric ID, owner email,
Business list, Business names, Business count, navigation into a Business,
or billing/wallet/usage/plan data.

AUTHORIZATION
The overview returns 200 only when the authenticated customer user is the
Workspace owner, or an active Workspace membership holder.
The overview returns 404 when: the Workspace uid is unknown; the user is
unrelated; the user has only an inactive membership; the user only
directly owns a Business inside that Workspace; or users.parent_id /
users.is_admin is the only possible access path.
Owner status always wins over an anomalous coexisting membership row.
An inactive Workspace remains addressable and returns 200 to its owner and
active membership holders. It must visibly show Inactive.

MEMBERSHIP DIRECTORY VISIBILITY
- Workspace owner can view the membership directory.
- Active Admin membership can view the membership directory.
- Active Staff membership can view the overview but cannot view the
  directory. Staff responses must not receive member rows in view data,
  not merely hide them with CSS.
- Unknown or unauthorized users still receive 404 rather than 403.

MEMBERSHIP DIRECTORY ROWS
The directory contains active workspace_memberships rows only.
- Do not synthesize the Workspace owner as a membership row.
- Do not include inactive memberships.
- Sort rows by workspace_memberships.id ascending.
Each row exposes exactly: member name; role label (Admin or Staff); scope
label (All Businesses for scope all, Selected Businesses for scope
selected); selected-Business assignment count as an integer. The
assignment count is the actual number of workspace_membership_businesses
rows for that membership -- for an All Businesses membership this should
normally be zero under the domain invariant, but the presentation must
report the actual assignment-row count rather than loading or exposing
Business names.
Do not expose: member email, user numeric ID, membership numeric ID,
Workspace numeric ID, assigned Business IDs, assigned Business names, or
inactive rows.

IMPLEMENTATION BOUNDARY
The expected implementation surface is limited to:
- app/Http/Controllers/Customer/Workspace/WorkspaceController.php
- routes/customer.php
- resources/views/customer/workspaces/show.blade.php
- tests/Feature/Workspace/WorkspaceOverviewHttpTest.php
- the existing WorkspaceSwitcherHttpTest only where its Slice 3A "no later
  route exists" assertion must now permit customer.workspaces.show while
  continuing to forbid member-list and Business-list routes
Use these existing repository contracts -- do not add repository methods
unless inspection proves one of them does not exist or cannot satisfy the
contract, in which case state the smallest required repository addition
without changing domain semantics:
- WorkspaceRepository::findByUid()
- WorkspaceMembershipRepository::findByWorkspaceAndUser()
- WorkspaceMembershipRepository::activeForWorkspace()
- WorkspaceMembershipBusinessRepository::assignedBusinessIds()

READ-ONLY SAFETY
- Every Slice 3B request is GET/HEAD only.
- A GET must produce no database writes.
- The page contains no forms, mutation buttons, mutation links, or
  JavaScript mutation actions.
- No Slice 3C Business list or navigation.
- No mutation service or WorkspaceManager method is called.

REQUIRED TEST COVERAGE
Require at least:
  php artisan test --filter=WorkspaceOverviewHttpTest
  php artisan test --filter=WorkspaceSwitcherHttpTest
WorkspaceOverviewHttpTest must prove: route name, URI, and GET/HEAD-only
methods; guest rejection; owner overview access; active Admin overview
access; active Staff overview access; inactive Workspace remains
addressable; unknown uid returns 404; unrelated user returns 404; inactive
membership returns 404; direct Business ownership alone returns 404; owner
sees active membership directory; active Admin sees active membership
directory; Staff receives no membership rows or member data; inactive
memberships are omitted; name, role, scope, and assignment count are
correct; owner is not synthesized into the membership rows; email, numeric
IDs, Business IDs, and Business names are absent; deterministic membership
ordering; GET causes no database writes; no separate members route; no
Business-list route; no mutation verbs or surfaces.
"""

# Coarse, deliberately fail-closed guardrails against a claude_prompt that
# tries to escape the bounded slice. These are heuristics, not a semantic
# understanding of the prompt: false positives (blocking a legitimate
# prompt) just cost a NEEDS_HUMAN stop, which is the safe direction. A
# false negative would not be safe, so the patterns favor over-blocking.
FORBIDDEN_PROMPT_PATTERNS = [
    (re.compile(r"\bgit\s+push\b[^\n]*\bmain\b", re.I), "requests a push to main"),
    (re.compile(r"\bgit\s+push\b[^\n]*\bmaster\b", re.I), "requests a push to master"),
    (re.compile(r"\bmerge\b", re.I), "mentions merging"),
    (re.compile(r"\bgh\s+pr\s+merge\b", re.I), "requests PR merge"),
    (re.compile(r"\bforce[- ]push\b", re.I), "requests a force-push"),
    (re.compile(r"jazmisuh_jasmin", re.I), "references the production database name"),
    (re.compile(r"\bproduction\b[^\n]{0,30}\bdatabase\b", re.I), "references a production database"),
    (re.compile(r"\.github/workflows", re.I), "requests a workflow-file change"),
    (re.compile(r"\bphpunit\.xml\b", re.I), "requests a phpunit.xml change"),
    (re.compile(r"\.env(\.\w+)?\b", re.I), "requests an env-file change"),
    (
        re.compile(r"\b(add|build|implement|create)\b[^\n]{0,60}\b(navigation|business[- ]list)\b", re.I),
        "requests Slice 3C (navigation / Business list) work",
    ),
]

# A match immediately preceded by one of these cues (within a short window)
# is a boundary being *respected*, not crossed -- see scope_violations().
NEGATION_CUES = re.compile(r"\b(no|not|never|without|cannot|must\s+not)\b|n['’]t\b", re.I)

# Only these two literal command shapes may ever reach subprocess.run, and
# only after every token is checked against the identifier pattern below.
SAFE_IDENTIFIER = re.compile(r"^[A-Za-z0-9_:\\.]+$")
SAFE_TEST_PATH = re.compile(r"^tests/(Feature|Unit)(/[A-Za-z0-9_]+)*(\.php)?$")


class SchemaError(Exception):
    pass


class OpenAIError(Exception):
    pass


def redact(text: str, secret: str | None) -> str:
    if secret:
        text = text.replace(secret, "***REDACTED***")
    return text


def read_capped(path: str, limit: int = MAX_CONTEXT_CHARS) -> str:
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        content = fh.read()
    if len(content) > limit:
        content = content[:limit] + f"\n... [truncated, {len(content) - limit} more characters omitted]\n"
    return content


def build_user_content(args: argparse.Namespace) -> str:
    parts = [f"Orchestration stage: {args.stage}", f"PR number: {args.pr_number}", f"Target slice: {args.slice}"]

    if args.stage == "plan":
        parts.append(SLICE_3B_CONTRACT)

    parts.append("--- CLAUDE.md (repository AI rules) ---\n" + read_capped(args.claude_md_file))
    parts.append("--- RFC-003 (Workspace and Business Account Core) ---\n" + read_capped(args.rfc_file))

    if args.pr_title_file:
        parts.append("--- PR title ---\n" + read_capped(args.pr_title_file, 2_000))
    if args.pr_body_file:
        parts.append("--- PR body ---\n" + read_capped(args.pr_body_file, 10_000))
    if args.diff_file:
        parts.append("--- git diff origin/main...HEAD ---\n" + read_capped(args.diff_file))
    if args.changed_files_file:
        parts.append("--- changed files ---\n" + read_capped(args.changed_files_file, 5_000))
    if args.test_results_file:
        parts.append("--- test results ---\n" + read_capped(args.test_results_file, 20_000))
    if args.prior_decision_file:
        parts.append("--- prior orchestrator decision ---\n" + read_capped(args.prior_decision_file, 10_000))

    return "\n\n".join(parts)


SYSTEM_PROMPTS = {
    "plan": (
        "You are the planning stage of a bounded, one-slice AI orchestration pilot for a Laravel "
        "application. You determine the exact next-slice implementation instruction for Claude Code, "
        "strictly bounded to the locked Slice 3B product contract supplied in the user message. That "
        "contract is complete: every product decision that could otherwise require judgment -- route "
        "name, page structure, field set, display ordering, and whether the owner is synthesized into "
        "the membership rows -- has already been made and is not yours to revisit. Do not return "
        "NEEDS_HUMAN merely because you would have chosen a different route name, page structure, field "
        "set, ordering, or owner-row treatment; the contract's answer on each of those points is final. "
        "You do not have authority to expand scope, invent product requirements, approve a merge, or "
        "authorize any change to main, to workflow files, or to database configuration. Return "
        "decision=IMPLEMENT with a complete, bounded claude_prompt that names the exact files to add or "
        "change (limited to the contract's implementation boundary), the exact authorization rule to "
        "enforce, and the exact focused test command(s) to run, unless repository inspection reveals one "
        "of: a true contradiction in the repository's existing architecture (for example, one of the "
        "named repository methods does not exist or cannot satisfy the contract, and no minimal, "
        "semantics-preserving addition resolves it); a required change to a forbidden path (workflow, "
        "config, or main); an unresolved security or data-isolation problem the contract does not already "
        "settle; or an implementation that would require Slice 3C or mutation scope to satisfy. In any of "
        "those cases, return decision=NEEDS_HUMAN with the specific contradiction in blocking_questions "
        "rather than guessing or inventing a workaround. Respond with only the structured JSON object "
        "requested."
    ),
    "review": (
        "You are the review stage of a bounded, one-slice AI orchestration pilot. You review Claude's "
        "implementation of the locked Slice 3B contract against: RFC-003, the Slice 3B contract, "
        "Workspace isolation rules, route and authorization boundaries, absence of any mutation surface, "
        "absence of scope creep beyond Slice 3B, test coverage, and database safety. Base findings only "
        "on the diff and test results provided, not on assumptions. Return decision=READY only if there "
        "are no unresolved critical/high/medium findings. Return decision=FIX with review_findings and a "
        "claude_prompt containing only the exact corrections needed if issues are fixable within scope. "
        "Return decision=NEEDS_HUMAN if you find a true unresolved product question, evidence you cannot "
        "verify, or anything requiring a merge, a main-branch write, or a workflow-file change. Respond "
        "with only the structured JSON object requested."
    ),
    "final_review": (
        "You are the final review stage of a bounded, one-slice AI orchestration pilot, evaluating the "
        "single permitted correction round. Return decision=READY only if every critical/high/medium "
        "finding from the prior review is resolved; low-severity stylistic findings may be reported "
        "without blocking if they do not affect the RFC contract. Return decision=NEEDS_HUMAN if any "
        "critical/high/medium finding remains, if evidence cannot be verified, or if anything requires a "
        "merge, a main-branch write, or a workflow-file change. Do not return decision=FIX at this stage "
        "-- only one correction round is permitted, so a further fix request must go to a human. Respond "
        "with only the structured JSON object requested."
    ),
}

REASONING_EFFORT = {"plan": "low", "review": "medium", "final_review": "medium"}


def call_openai(system_prompt: str, user_content: str, effort: str, api_key: str) -> dict:
    payload = {
        "model": MODEL,
        "reasoning": {"effort": effort},
        "max_output_tokens": MAX_OUTPUT_TOKENS,
        "input": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_content},
        ],
        "text": {
            "format": {
                "type": "json_schema",
                "name": "orchestrator_decision",
                "strict": True,
                "schema": DECISION_JSON_SCHEMA,
            }
        },
    }
    req = urllib.request.Request(
        API_URL,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:
            body = resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        err_body = redact(exc.read().decode("utf-8", errors="replace")[:2000], api_key)
        raise OpenAIError(f"OpenAI API HTTP {exc.code}: {err_body}") from None
    except urllib.error.URLError as exc:
        raise OpenAIError(redact(f"OpenAI API request failed: {exc.reason}", api_key)) from None
    return json.loads(body)


def extract_decision_text(response: dict) -> str:
    if isinstance(response.get("output_text"), str) and response["output_text"]:
        return response["output_text"]
    for item in response.get("output", []):
        if item.get("type") == "message":
            for content in item.get("content", []):
                if content.get("type") in ("output_text", "text") and content.get("text"):
                    return content["text"]
        if item.get("type") == "refusal":
            raise OpenAIError(f"model refused: {item.get('refusal', 'no reason given')}")
    raise OpenAIError("no output_text found in OpenAI response")


def validate_decision(obj) -> dict:
    if not isinstance(obj, dict):
        raise SchemaError("top-level response is not a JSON object")
    required_keys = {
        "decision",
        "summary",
        "claude_prompt",
        "required_tests",
        "blocking_questions",
        "review_findings",
    }
    missing = required_keys - obj.keys()
    if missing:
        raise SchemaError(f"missing keys: {sorted(missing)}")
    if obj["decision"] not in ALLOWED_DECISIONS:
        raise SchemaError(f"unknown decision: {obj['decision']!r}")
    if not isinstance(obj["summary"], str):
        raise SchemaError("summary must be a string")
    if not isinstance(obj["claude_prompt"], str):
        raise SchemaError("claude_prompt must be a string")
    for key in ("required_tests", "blocking_questions"):
        val = obj[key]
        if not isinstance(val, list) or not all(isinstance(x, str) for x in val):
            raise SchemaError(f"{key} must be a list of strings")
    if not isinstance(obj["review_findings"], list):
        raise SchemaError("review_findings must be a list")
    for finding in obj["review_findings"]:
        if not isinstance(finding, dict):
            raise SchemaError("review_findings entries must be objects")
        for key in ("severity", "file", "finding", "required_change"):
            if key not in finding or not isinstance(finding[key], str):
                raise SchemaError(f"review_findings entry missing/invalid field: {key}")
        if finding["severity"] not in ALLOWED_SEVERITIES:
            raise SchemaError(f"unknown severity: {finding['severity']!r}")
    return obj


def scope_violations(claude_prompt: str) -> list[str]:
    violations = []
    for pattern, msg in FORBIDDEN_PROMPT_PATTERNS:
        for match in pattern.finditer(claude_prompt):
            preceding = claude_prompt[max(0, match.start() - 25):match.start()]
            if NEGATION_CUES.search(preceding):
                # The Slice 3B contract now explicitly spells out several
                # of these boundaries (e.g. "no Slice 3C Business list or
                # navigation work"), so a well-behaved claude_prompt is
                # likely to echo that same boundary back as a reminder.
                # Flagging the reminder itself as a violation would force
                # NEEDS_HUMAN on a prompt that is doing exactly the right
                # thing, so a negated match is not treated as a violation.
                continue
            violations.append(msg)
            break
    return violations


def force_needs_human(decision: dict, reason: str) -> dict:
    decision = dict(decision)
    decision["decision"] = "NEEDS_HUMAN"
    decision["blocking_questions"] = list(decision.get("blocking_questions", [])) + [reason]
    decision["claude_prompt"] = ""
    return decision


def cmd_decide(args: argparse.Namespace) -> int:
    api_key = os.environ.get("OPENAI_API_KEY")
    if not api_key:
        print("::error::OPENAI_API_KEY is not set in the environment", file=sys.stderr)
        return 2

    system_prompt = SYSTEM_PROMPTS[args.stage]
    effort = REASONING_EFFORT[args.stage]
    user_content = build_user_content(args)

    try:
        response = call_openai(system_prompt, user_content, effort, api_key)
        decision_text = extract_decision_text(response)
        decision = validate_decision(json.loads(decision_text))
    except (OpenAIError, SchemaError, json.JSONDecodeError) as exc:
        print(f"::error::orchestrator stage '{args.stage}' failed validation: {exc}", file=sys.stderr)
        fallback = {
            "decision": "NEEDS_HUMAN",
            "summary": f"Stage '{args.stage}' produced an invalid or unusable response: {exc}",
            "claude_prompt": "",
            "required_tests": [],
            "blocking_questions": [f"OpenAI response for stage '{args.stage}' failed validation: {exc}"],
            "review_findings": [],
        }
        write_outputs(args, fallback, usage={})
        return 1

    if decision["decision"] == "IMPLEMENT" and decision["claude_prompt"]:
        violations = scope_violations(decision["claude_prompt"])
        if violations:
            decision = force_needs_human(
                decision, "claude_prompt rejected by scope guard: " + "; ".join(violations)
            )
            print(f"::error::claude_prompt rejected by scope guard: {violations}", file=sys.stderr)

    if decision["decision"] == "FIX" and decision["claude_prompt"]:
        violations = scope_violations(decision["claude_prompt"])
        if violations:
            decision = force_needs_human(
                decision, "correction claude_prompt rejected by scope guard: " + "; ".join(violations)
            )
            print(f"::error::correction claude_prompt rejected by scope guard: {violations}", file=sys.stderr)

    write_outputs(args, decision, response.get("usage", {}))
    return 0


def write_outputs(args: argparse.Namespace, decision: dict, usage: dict) -> None:
    with open(args.output_json, "w", encoding="utf-8") as fh:
        json.dump({"decision": decision, "usage": usage}, fh, indent=2)

    if args.claude_prompt_file:
        with open(args.claude_prompt_file, "w", encoding="utf-8") as fh:
            fh.write(decision.get("claude_prompt", ""))

    if args.github_output:
        delimiter = f"gh_delim_{uuid.uuid4().hex}"
        with open(args.github_output, "a", encoding="utf-8") as fh:
            fh.write(f"decision={decision['decision']}\n")
            fh.write(f"summary<<{delimiter}\n{decision['summary']}\n{delimiter}\n")


def is_safe_test_command(cmd: str) -> bool:
    try:
        tokens = shlex.split(cmd)
    except ValueError:
        return False
    if not tokens:
        return False

    def args_ok(rest: list[str]) -> bool:
        for tok in rest:
            if tok.startswith("--filter="):
                if not SAFE_IDENTIFIER.match(tok[len("--filter="):]):
                    return False
            elif tok.startswith("tests/"):
                if not SAFE_TEST_PATH.match(tok):
                    return False
            else:
                return False
        return True

    if tokens[:3] == ["php", "artisan", "test"]:
        return args_ok(tokens[3:])
    if tokens[0] == "vendor/bin/phpunit":
        return args_ok(tokens[1:])
    return False


def run_focused_tests(commands: list[str], repo_root: str) -> dict:
    results = []
    for cmd in commands:
        if not is_safe_test_command(cmd):
            results.append({"command": cmd, "status": "rejected", "reason": "not on the safe command allow-list"})
            continue
        argv = shlex.split(cmd)
        try:
            proc = subprocess.run(
                argv,
                cwd=repo_root,
                capture_output=True,
                text=True,
                timeout=600,
                shell=False,
            )
            output_tail = (proc.stdout or "")[-4000:] + "\n" + (proc.stderr or "")[-2000:]
            results.append(
                {
                    "command": cmd,
                    "status": "passed" if proc.returncode == 0 else "failed",
                    "exit_code": proc.returncode,
                    "output": output_tail,
                }
            )
        except subprocess.TimeoutExpired:
            results.append({"command": cmd, "status": "timeout", "reason": "exceeded 600s"})
    return {"results": results}


def cmd_run_tests(args: argparse.Namespace) -> int:
    with open(args.decision_file, "r", encoding="utf-8") as fh:
        decision_payload = json.load(fh)
    required_tests = decision_payload.get("decision", decision_payload).get("required_tests", [])

    if not required_tests:
        output = {"results": [], "verified": False, "note": "no required_tests supplied"}
        with open(args.output_json, "w", encoding="utf-8") as fh:
            json.dump(output, fh, indent=2)
        print("::warning::no required_tests supplied by the orchestrator decision", file=sys.stderr)
        return 0

    output = run_focused_tests(required_tests, args.repo_root)
    rejected = [r for r in output["results"] if r["status"] == "rejected"]
    ran = [r for r in output["results"] if r["status"] != "rejected"]
    all_passed = bool(ran) and all(r["status"] == "passed" for r in ran) and not rejected
    output["verified"] = all_passed and not rejected

    with open(args.output_json, "w", encoding="utf-8") as fh:
        json.dump(output, fh, indent=2)

    if rejected:
        print(f"::error::{len(rejected)} required test command(s) failed the safety allow-list", file=sys.stderr)
        return 1
    return 0 if all_passed else 1


def cmd_check_scope(args: argparse.Namespace) -> int:
    forbidden_prefixes = (".github/", ".env", ".pilot-tooling/")
    forbidden_exact = {"phpunit.xml", "config/database.php"}
    with open(args.changed_files_file, "r", encoding="utf-8") as fh:
        changed = [line.strip() for line in fh if line.strip()]

    violations = [
        path
        for path in changed
        if path.startswith(forbidden_prefixes) or path in forbidden_exact
    ]
    if violations:
        print(f"::error::forbidden paths changed by implementation step: {violations}", file=sys.stderr)
        return 1
    print(f"scope check passed: {len(changed)} file(s) changed, none forbidden")
    return 0


def cmd_selftest(_args: argparse.Namespace) -> int:
    """Exercise schema validation, the scope guard, and the test-command
    allow-list against fixed, mocked inputs -- no network access and no
    OPENAI_API_KEY required. Run automatically at the start of every pilot
    dispatch so a change to this file can't silently break its own safety
    checks."""
    failures: list[str] = []
    total = 0

    def check(label: str, condition: bool) -> None:
        nonlocal total
        total += 1
        if not condition:
            failures.append(label)

    good_decision = {
        "decision": "IMPLEMENT",
        "summary": "Add read-only Workspace membership list",
        "claude_prompt": "Implement the membership list view exactly as scoped.",
        "required_tests": ["php artisan test --filter=WorkspaceSwitcherHttpTest"],
        "blocking_questions": [],
        "review_findings": [],
    }
    try:
        validate_decision(good_decision)
        check("valid decision accepted", True)
    except SchemaError:
        check("valid decision accepted", False)

    try:
        validate_decision({"decision": "IMPLEMENT"})
        check("decision missing keys rejected", False)
    except SchemaError:
        check("decision missing keys rejected", True)

    try:
        validate_decision({**good_decision, "decision": "MAYBE"})
        check("unknown decision value rejected", False)
    except SchemaError:
        check("unknown decision value rejected", True)

    try:
        validate_decision(
            {**good_decision, "review_findings": [{"severity": "extreme", "file": "", "finding": "", "required_change": ""}]}
        )
        check("unknown severity rejected", False)
    except SchemaError:
        check("unknown severity rejected", True)

    check(
        "scope guard catches a merge request",
        bool(scope_violations("Implement the slice, then merge the pull request.")),
    )
    check(
        "scope guard catches a main-branch push",
        bool(scope_violations("Run git push origin main when finished.")),
    )
    check(
        "scope guard catches a workflow-file edit",
        bool(scope_violations("Update .github/workflows/ai-orchestrator-pilot.yml to raise the turn limit.")),
    )
    check(
        "scope guard catches Slice 3C scope creep",
        bool(scope_violations("Also build the Business list navigation while you're in there.")),
    )
    check(
        "scope guard leaves an in-scope prompt untouched",
        not scope_violations(good_decision["claude_prompt"]),
    )
    check(
        "scope guard does not flag a boundary reminder that echoes the contract",
        not scope_violations(
            "Implement the Workspace overview and membership directory. "
            "Do not add Business list navigation -- that is Slice 3C and out of scope. "
            "Never merge this PR or push to main."
        ),
    )

    check(
        "artisan filter command accepted",
        is_safe_test_command("php artisan test --filter=WorkspaceSwitcherHttpTest"),
    )
    check(
        "phpunit directory command accepted",
        is_safe_test_command("vendor/bin/phpunit tests/Feature/Workspace"),
    )
    check(
        "shell metacharacter command rejected",
        not is_safe_test_command("php artisan test --filter=Foo; rm -rf /"),
    )
    check(
        "arbitrary binary rejected",
        not is_safe_test_command("rm -rf /"),
    )
    check(
        "unlisted flag rejected",
        not is_safe_test_command("php artisan test --coverage"),
    )

    mock_response = {
        "output_text": json.dumps(good_decision),
        "usage": {"input_tokens": 100, "output_tokens": 50},
    }
    try:
        text = extract_decision_text(mock_response)
        validate_decision(json.loads(text))
        check("mocked OpenAI response parses end-to-end", True)
    except (OpenAIError, SchemaError, json.JSONDecodeError):
        check("mocked OpenAI response parses end-to-end", False)

    if failures:
        print("SELFTEST: FAIL")
        for label in failures:
            print(f"  - {label}")
        return 1
    print(f"SELFTEST: PASS ({total} checks)")
    return 0


def _load_decision_payload(path: str) -> dict | None:
    if not path:
        return None
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return None


def _load_test_results(path: str) -> dict | None:
    if not path:
        return None
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return None


def _test_summary(results: dict | None) -> str:
    if not results or not results.get("results"):
        return "not run"
    counts = {"passed": 0, "failed": 0, "rejected": 0, "timeout": 0}
    for r in results["results"]:
        counts[r.get("status", "rejected")] = counts.get(r.get("status", "rejected"), 0) + 1
    return ", ".join(f"{v} {k}" for k, v in counts.items() if v)


def cmd_render_comment(args: argparse.Namespace) -> int:
    plan = _load_decision_payload(args.plan_decision)
    review = _load_decision_payload(args.review_decision)
    final_review = _load_decision_payload(args.final_review_decision)
    tests_1 = _load_test_results(args.test_results_1)
    tests_2 = _load_test_results(args.test_results_2)

    plan_d = (plan or {}).get("decision", {})
    review_d = (review or {}).get("decision", {})
    final_d = (final_review or {}).get("decision", {})

    claude_runs = sum(
        1 for x in (args.claude_implementation_ran, args.claude_correction_ran) if x in ("success", "failure")
    )

    scope_ok = args.scope_guard_outcome in ("success", "not-run")

    if final_d:
        final_status = "READY_FOR_HUMAN_REVIEW" if final_d.get("decision") == "READY" else "NEEDS_HUMAN"
    elif review_d and review_d.get("decision") == "READY":
        final_status = "READY_FOR_HUMAN_REVIEW"
    elif review_d and review_d.get("decision") == "NEEDS_HUMAN":
        final_status = "NEEDS_HUMAN"
    elif review_d and review_d.get("decision") == "FIX":
        final_status = "FAILED" if not scope_ok else "NEEDS_HUMAN"
    elif plan_d and plan_d.get("decision") == "NEEDS_HUMAN":
        final_status = "NEEDS_HUMAN"
    elif plan_d and plan_d.get("decision") == "IMPLEMENT" and not scope_ok:
        final_status = "FAILED"
    elif plan_d and plan_d.get("decision") == "IMPLEMENT" and not review:
        final_status = "FAILED"
    else:
        final_status = "FAILED"

    total_usage = {"input_tokens": 0, "output_tokens": 0}
    for payload in (plan, review, final_review):
        if not payload:
            continue
        usage = payload.get("usage", {})
        total_usage["input_tokens"] += usage.get("input_tokens", 0) or 0
        total_usage["output_tokens"] += usage.get("output_tokens", 0) or 0

    unresolved = []
    for source_d in (final_d, review_d):
        for finding in source_d.get("review_findings", []):
            if finding.get("severity") in ("critical", "high", "medium"):
                unresolved.append(finding)
        if source_d.get("review_findings"):
            break  # prefer the most recent review's findings only

    changed_files: list[str] = []
    if args.changed_files_file and os.path.exists(args.changed_files_file):
        with open(args.changed_files_file, "r", encoding="utf-8") as fh:
            changed_files = [line.strip() for line in fh if line.strip()]

    final_sha = ""
    if args.repo_root:
        try:
            proc = subprocess.run(
                ["git", "rev-parse", "HEAD"],
                cwd=args.repo_root,
                capture_output=True,
                text=True,
                timeout=30,
                shell=False,
            )
            final_sha = proc.stdout.strip() if proc.returncode == 0 else ""
        except (OSError, subprocess.TimeoutExpired):
            final_sha = ""

    lines = [
        "[AI-ORCHESTRATOR-PILOT]",
        "",
        f"**Slice:** {args.slice}",
        f"**Starting SHA:** `{args.start_sha}`",
        f"**Final SHA:** `{final_sha or 'unchanged'}`",
        f"**Final status:** {final_status}",
        "",
        "**OpenAI decisions:**",
        f"- plan: {plan_d.get('decision', 'not run')} -- {plan_d.get('summary', '')}",
        f"- review: {review_d.get('decision', 'not run')} -- {review_d.get('summary', '')}",
        f"- final review: {final_d.get('decision', 'not run')} -- {final_d.get('summary', '')}",
        "",
        f"**Claude executions:** {claude_runs} of 2 (implementation: {args.claude_implementation_ran}, correction: {args.claude_correction_ran})",
        "",
        "**Tests run:**",
        f"- after implementation: {_test_summary(tests_1)}",
        f"- after correction: {_test_summary(tests_2)}",
        "",
        f"**Workflow/env/config scope guard:** {args.scope_guard_outcome}",
        "",
        f"**Changed-file scope ({len(changed_files)}):**",
    ]
    if changed_files:
        lines.extend(f"- {f}" for f in changed_files)
    else:
        lines.append("- (none)")
    lines.append("")

    if unresolved:
        lines.append("**Unresolved findings:**")
        for finding in unresolved:
            lines.append(
                f"- [{finding.get('severity')}] {finding.get('file') or '(no file)'}: "
                f"{finding.get('finding')} -- required: {finding.get('required_change')}"
            )
        lines.append("")
    else:
        lines.append("**Unresolved findings:** none")
        lines.append("")

    all_blocking = list(plan_d.get("blocking_questions", [])) + list(review_d.get("blocking_questions", [])) + list(
        final_d.get("blocking_questions", [])
    )
    if all_blocking:
        lines.append("**Blocking questions:**")
        for q in all_blocking:
            lines.append(f"- {q}")
        lines.append("")

    lines.append(
        f"**OpenAI token usage:** {total_usage['input_tokens']} input / {total_usage['output_tokens']} output "
        "(sum across plan/review/final-review calls, as returned by the API)"
    )
    lines.append(
        "**Claude cost:** see the job summary produced by claude-code-action "
        "(`display_report: true`) linked below; not independently re-measured here."
    )
    lines.append(f"**GitHub Actions run:** {args.run_url}")
    lines.append("")
    lines.append(
        "This pilot never merges, never closes the PR, and never pushes to main -- "
        "a human reviewer decides what happens next."
    )

    with open(args.output, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")
    return 0


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    decide = sub.add_parser("decide", help="Call OpenAI and produce a validated decision JSON")
    decide.add_argument("--stage", choices=["plan", "review", "final_review"], required=True)
    decide.add_argument("--pr-number", required=True)
    decide.add_argument("--slice", required=True)
    decide.add_argument("--rfc-file", required=True)
    decide.add_argument("--claude-md-file", required=True)
    decide.add_argument("--pr-title-file")
    decide.add_argument("--pr-body-file")
    decide.add_argument("--diff-file")
    decide.add_argument("--changed-files-file")
    decide.add_argument("--test-results-file")
    decide.add_argument("--prior-decision-file")
    decide.add_argument("--output-json", required=True)
    decide.add_argument("--claude-prompt-file")
    decide.add_argument("--github-output")
    decide.set_defaults(func=cmd_decide)

    run_tests = sub.add_parser("run-tests", help="Safely validate and execute required_tests from a decision")
    run_tests.add_argument("--decision-file", required=True)
    run_tests.add_argument("--repo-root", default=".")
    run_tests.add_argument("--output-json", required=True)
    run_tests.set_defaults(func=cmd_run_tests)

    check_scope = sub.add_parser("check-scope", help="Fail if changed files touch forbidden paths")
    check_scope.add_argument("--changed-files-file", required=True)
    check_scope.set_defaults(func=cmd_check_scope)

    selftest = sub.add_parser("selftest", help="Run schema/scope-guard/allow-list checks against mocked input")
    selftest.set_defaults(func=cmd_selftest)

    render_comment = sub.add_parser("render-comment", help="Render the final [AI-ORCHESTRATOR-PILOT] PR comment")
    render_comment.add_argument("--pr-number", required=True)
    render_comment.add_argument("--slice", required=True)
    render_comment.add_argument("--start-sha", required=True)
    render_comment.add_argument("--run-url", required=True)
    render_comment.add_argument("--plan-decision", default="")
    render_comment.add_argument("--review-decision", default="")
    render_comment.add_argument("--final-review-decision", default="")
    render_comment.add_argument("--test-results-1", default="")
    render_comment.add_argument("--test-results-2", default="")
    render_comment.add_argument("--changed-files-file", default="changed_files.txt")
    render_comment.add_argument("--claude-implementation-ran", default="skipped")
    render_comment.add_argument("--claude-correction-ran", default="skipped")
    render_comment.add_argument("--scope-guard-outcome", default="not-run")
    render_comment.add_argument("--repo-root", default=".")
    render_comment.add_argument("--output", required=True)
    render_comment.set_defaults(func=cmd_render_comment)

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_arg_parser()
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
