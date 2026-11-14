# Google Business Profile profile-cache (mirror) retention

**Default behavior:** `config/google_business_profile.php` leaves `GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS` unset, and an unset or invalid value means
effective retention of **0**. At 0 the platform stores no Google profile content between requests: "Refresh" reads Google live and shows the result
for that single response (the Platform vs Google page), and the overview stays on "Refresh required" by design.

**Production value is not verified here.** The owner must confirm what the production environment actually sets (for example by checking its
environment file or `php artisan tinker` -> `config('google_business_profile.mirror.retention_days')`) before relying on either behavior.

## To enable a 7-day profile cache (owner decision)

Only after the owner confirms this is acceptable under Google's Business Profile API policies and the platform's privacy terms:

1. Set in the production environment (not committed): `GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS=7`
2. Clear/rebuild cached config: `php artisan config:clear && php artisan config:cache` (and restart queue workers / PHP-FPM).
3. In the app, press **Refresh from Google** once per linked location. Each binding then stores the bounded profile
   mirror with `mirror_expires_at = fetched_at + 7 days`; the overview renders it until expiry.

Accepted values are integers 1-30; anything else (blank, 0, text, >30) fails closed to 0. The scheduled purge continues to remove
expired mirrors. The 24-hour encrypted Google performance store is separate and unaffected by this setting.
