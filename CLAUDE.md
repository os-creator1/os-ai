# CLAUDE.md

## Claude Code GitHub Actions (`.github/workflows/claude.yml`)

Claude runs in this repository's GitHub Actions only when a repository
collaborator writes `@claude` in an issue or PR comment. It operates under
these rules:

- **Branch-only development.** Claude works exclusively on the branch that
  triggered the run. It never creates or depends on any other branch as a
  side effect.
- **Never push directly to `main`.** All changes go through the triggering
  PR's own branch, reviewed by a human before merge.
- **Never merge pull requests.** Claude opens/updates PRs and leaves the
  merge decision to a human reviewer.
- **Use the `ultimatesms_testing` database only.** Any test run — PHPUnit or
  otherwise — must target `ultimatesms_testing` exclusively.
- **Never touch a production-looking database, deployment credential, or
  unrelated secret.** If a task appears to require one, stop and report
  that instead of proceeding.
- **Run focused tests before a full regression.** Start with the tests
  scoped to the change; only broaden to a full suite run if the task
  explicitly calls for it.
- **Report exact test counts and exact git scope.** Every summary states
  the precise number of tests run/passed and the precise list of files
  changed — never an approximation.
