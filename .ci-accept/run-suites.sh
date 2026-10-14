#!/usr/bin/env bash
# Temporary acceptance runner. Usage: run-suites.sh <app-dir> [suite-name ...]
# Runs each suite sequentially against the job's own disposable MySQL service container.
set -uo pipefail
APP_DIR="$1"; shift
cd "$APP_DIR"
declare -A S=(
  [unit-navigation]="tests/Unit/Navigation"
  [mail]="tests/Feature/Mail"
  [notifications]="--filter Notification"
  [auth]="tests/Feature/Auth"
  [custom-fields]="tests/Feature/CustomFields"
  [customer-shell]="tests/Feature/CustomerShell"
  [navigation]="tests/Feature/Navigation"
  [contacts]="tests/Feature/Contacts"
  [calendar]="tests/Feature/Calendar"
  [workspace]="tests/Feature/Workspace"
  [analytics]="tests/Feature/Analytics"
  [website]="tests/Feature/Website"
  [design-system]="tests/Feature/DesignSystem"
  [branding]="tests/Feature/Branding"
  [documents]="tests/Feature/Documents"
  [forms]="tests/Feature/Forms"
)
ORDER=(unit-navigation mail notifications auth custom-fields customer-shell navigation contacts calendar workspace analytics website design-system branding documents forms)
[ "$#" -gt 0 ] && ORDER=("$@")
mkdir -p "$GITHUB_WORKSPACE/out"; : > "$GITHUB_WORKSPACE/out/summary.txt"; FAILED=()
for name in "${ORDER[@]}"; do
  echo "::group::$name  (${S[$name]})"
  # shellcheck disable=SC2086
  vendor/bin/phpunit --colors=never ${S[$name]} 2>&1 | tee "$GITHUB_WORKSPACE/out/$name.log"
  rc=${PIPESTATUS[0]}
  echo "::endgroup::"
  line=$(grep -E "^(OK|Tests:|No tests executed)" "$GITHUB_WORKSPACE/out/$name.log" | tail -1)
  echo "RESULT $name rc=$rc :: ${line:-<no summary line>}" | tee -a "$GITHUB_WORKSPACE/out/summary.txt"
  if [ "$rc" -ne 0 ] || [ -z "$line" ] || echo "$line" | grep -q "No tests"; then FAILED+=("$name"); fi
done
echo "FAILED_SUITES=${FAILED[*]}" | tee -a "$GITHUB_WORKSPACE/out/summary.txt"
echo "failed=${FAILED[*]}" >> "$GITHUB_OUTPUT"
exit 0
