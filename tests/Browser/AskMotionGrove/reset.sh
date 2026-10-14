#!/bin/sh
# Clears the assistant's own rows (and the rows the scenarios create) between runs; keeps the seeded tenants.
# MYSQL_CMD defaults to the disposable test database on a local root socket; override for your environment.
MYSQL_CMD="${MYSQL_CMD:-mysql -uroot ultimatesms_testing}"
$MYSQL_CMD -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE assistant_action_audits; TRUNCATE assistant_action_proposals; TRUNCATE assistant_messages; TRUNCATE assistant_conversations; DELETE FROM booking_types WHERE name='Wedding package'; TRUNCATE ai_usage_ledger; DELETE FROM ai_usage_periods WHERE period_key<>'trial'; SET FOREIGN_KEY_CHECKS=1;"
# The messages route is throttled per user; start each run with a clean limiter (file cache driver).
rm -rf "$(dirname "$0")/../../../storage/framework/cache/data/"* 2>/dev/null
