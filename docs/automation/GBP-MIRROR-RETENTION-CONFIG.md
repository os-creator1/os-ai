# Google Business Profile profile-cache (mirror) retention

**Current production state:** `GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS` is unset, so the effective retention is **0**.
At 0 the platform stores no Google profile content between requests. "Refresh" reads Google live and shows the result for that
single response (the Platform vs Google page); the overview stays on "Refresh required" by design. This is not a defect.

## To enable a 7-day profile cache (owner decision)

Only after the owner confirms this is acceptable under Google's Business Profile API policies and the platform's privacy terms:

1. Set in the production environment (not committed): `GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS=7`
2. Clear/rebuild cached config: `php artisan config:clear && php artisan config:cache` (and restart queue workers / PHP-FPM).
3. In the app, press **Refresh from Google** once per linked location. Each binding then stores the bounded profile
   mirror with `mirror_expires_at = fetched_at + 7 days`; the overview renders it until expiry.

Accepted values are integers 1-30; anything else (blank, 0, text, >30) fails closed to 0. The scheduled purge continues to remove
expired mirrors. The 24-hour encrypted Google performance store is separate and unaffected by this setting.
