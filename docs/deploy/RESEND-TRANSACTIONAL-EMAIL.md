# Resend transactional email (platform mail, production)

MotionGrove sends platform transactional email (notifications, invitations,
platform automation mail) through Resend's **HTTPS API** using Laravel's native
`resend` mailer. SMTP is not used: DigitalOcean blocks standard SMTP ports.
Verified sending domain: `notify.getmotiongrove.com`.

Customer-connected email accounts do not use `config('mail.*')`; they are
unaffected. The admin System Email screen offers "Resend (API)"; saving it
preserves `MAIL_MAILER=resend` and never reads or writes `RESEND_API_KEY`
(Forge environment only) or the SMTP fields.

## Forge environment variables (Site -> Environment)

```
MAIL_MAILER=resend
RESEND_API_KEY=<set in Forge only; never commit>
MAIL_FROM_ADDRESS=notifications@notify.getmotiongrove.com
MAIL_FROM_NAME="MotionGrove"
```

The from-address must be on the verified domain. Then redeploy/clear config
(`php artisan config:clear`, or `config:cache` + restart queue workers:
`php artisan queue:restart`) so queued jobs pick up the new mailer.

Marketing campaigns and customer-connected email accounts are not routed
through this mailer: no code selects the `resend` mailer explicitly; only the
platform default mailer (`MAIL_MAILER`) uses it.

## Deployment steps (production, after merge to main)

1. Forge -> Environment: set the variables above (key entered in Forge only).
2. Deploy the release (runs `composer install`).
3. `php artisan config:clear` (or `config:cache`), then `php artisan queue:restart`
   and restart Forge queue workers so they load the new mailer.

## Safe test send

1. Set the variables above (staging first if available).
2. `php artisan tinker`, then send to an address you own:
   `Mail::mailer('resend')->raw('MotionGrove Resend test', fn ($m) => $m->to('you@example.com')->subject('Resend test'));`
3. Confirm delivery and the sending domain in the Resend dashboard (Emails).
4. On production use one internal recipient, or trigger a real password-reset for an internal account. No public test endpoint exists or should be added.

Rollback: set `MAIL_MAILER` to the previous value and clear config.
