#!/bin/sh
# Clears the assistant's own rows (and the rows the scenarios create) between runs; keeps the seeded tenants.
#
# DESTRUCTIVE (TRUNCATE / DELETE), so it fails closed. Before any destructive SQL it asks the SAME MySQL command which database it is
# connected to (SELECT DATABASE()) and delegates the decision to Tests\Support\TestDatabaseSafety, the repository's single authority
# (see AGENTS.md); this script restates none of its rules. Anything it does not approve aborts with nothing modified, and a rejected
# override never falls back to the default. MYSQL_CMD defaults to a local root socket; the default is validated like any override.
set -eu

MYSQL_CMD="${MYSQL_CMD:-mysql -uroot ultimatesms_testing}"
HERE="$(cd "$(dirname "$0")" && pwd)"

# Command substitution under `set -e` aborts the script if the MySQL command itself fails.
TARGET_DB="$($MYSQL_CMD -N -B -e "SELECT DATABASE()")"

php "$HERE/assert_safe_database.php" "$TARGET_DB" || { echo "reset.sh: nothing was modified." >&2; exit 1; }

$MYSQL_CMD -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE assistant_action_audits; TRUNCATE assistant_action_proposals; TRUNCATE assistant_messages; TRUNCATE assistant_conversations; DELETE FROM booking_types WHERE name='Wedding package'; TRUNCATE ai_usage_ledger; DELETE FROM ai_usage_periods WHERE period_key<>'trial'; SET FOREIGN_KEY_CHECKS=1;"
# The messages route is throttled per user; start each run with a clean limiter (file cache driver).
rm -rf "$HERE/../../../storage/framework/cache/data/"* 2>/dev/null || true
