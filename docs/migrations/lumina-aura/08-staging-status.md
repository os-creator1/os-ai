# 08 · Staging status and how to reproduce

Environment: **disposable local MariaDB `ultimatesms_testing_lane_lumina`** (a `TestDatabaseSafety`-style sibling; the guard also refuses production-shaped names), served by `php artisan serve` with `APP_URL=http://staging.lumina.local`. This is isolated from every real MotionGrove environment; no remote staging was touched (none is reachable from this session).

```
# 1. GHL snapshot (read-only; git-ignored output)
GHL_PRIVATE_INTEGRATION_TOKEN=… GHL_LOCATION_ID=7HLIWryaYyed99beyIB7 php artisan ghl:export-snapshot

# 2. Provision + dry-run + import  (guard: opt-in env var, allowed host, --confirm-database)
DEMO_PROVISIONING_ENABLED=true DEMO_PROVISIONING_ALLOWED_HOSTS=<staging host> \
  php artisan lumina:provision --confirm-database=<db> --snapshot=<dir> [--dry-run]

# 3. Website (platform preview path only)
DEMO_PROVISIONING_ENABLED=true … php artisan lumina:build-website --confirm-database=<db> --assets=<dir of originals>
```

Both commands fake the queue, mail and notifications and block every outbound HTTP request for their own process. Re-running is safe (everything is found-or-created / ledger-keyed).

## Result on staging (verified from the database)

| Item | Count |
|---|---|
| Workspace / Business / primary Location | 1 / 1 / 1 — America/Denver, Thornton CO, service areas Denver Metro + Colorado Springs |
| Staff members besides the owner | 2 (least privilege, unusable passwords, no invitations) |
| Contacts | **51** (1 opted out; 4 email-only with non-dialable `np-` keys) |
| Tags attached / custom field values | 38 / 72 |
| Opportunities | **37** — Inquiries: Lost 20, Cold (7+ days) 6, Proposal Sent 4, Follow-Up 3, In Contact 1; Bookings: Completed 3 · status open 10 / won 7 / lost 20 |
| Conversations | **45** (one per contact) |
| Messages | **733** = 362 native SMS bubbles + 371 read-only timeline items (94 email, 72 calls, 11 notes, 189 activity, 5 SMS GHL itself failed to deliver) |
| Ledger rows (source id → local id) | 866 = 51 + 37 + 45 + 733 |
| Draft workflows / published / enrollments | 11 / 0 / 0 |
| Messaging identities, numbers, senders, mail accounts, domains | 0 |
| Website | published to preview path, 9 pages, 20 images |

The owner login password is generated and shown once by the command; it is not stored in the repository.
