<?php

/*
|--------------------------------------------------------------------------
| Business Email foundation — connected-mailbox OAuth + send limits
|--------------------------------------------------------------------------
|
| Credentials for the Business-scoped connected email account (Google /
| Gmail / Workspace and Microsoft / Outlook / 365). Deliberately its OWN
| config file and env prefix, separate from `calendar_external` (per-User
| Calendar grant), `google_business_profile` (Business Profile grant) and
| `google` (Socialite sign-in): a different consent, different scopes, a
| different token record. A Calendar or Business Profile grant is never
| reused as permission to send mail.
|
| Every value is validated at the point of use (BusinessEmailOAuthConfig)
| and never trusted as a raw env string.
|
*/

return [

    'google' => [
        'client_id' => env('BUSINESS_EMAIL_GOOGLE_CLIENT_ID'),
        'client_secret' => env('BUSINESS_EMAIL_GOOGLE_CLIENT_SECRET'),
        'redirect' => env('BUSINESS_EMAIL_GOOGLE_REDIRECT'),
        // Minimal: identify the mailbox and send. NO mailbox-read scope —
        // inbound sync is a later slice and would need gmail.readonly
        // (a Google "restricted" scope) which this slice deliberately avoids.
        'scopes' => [
            'openid',
            'email',
            'https://www.googleapis.com/auth/gmail.send',
        ],
    ],

    'microsoft' => [
        'client_id' => env('BUSINESS_EMAIL_MICROSOFT_CLIENT_ID'),
        'client_secret' => env('BUSINESS_EMAIL_MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('BUSINESS_EMAIL_MICROSOFT_REDIRECT'),
        'tenant' => env('BUSINESS_EMAIL_MICROSOFT_TENANT', 'common'),
        // Identify the mailbox (User.Read) and send (Mail.Send). No Mail.Read.
        'scopes' => ['offline_access', 'User.Read', 'Mail.Send'],
    ],

    'oauth' => [
        // Pending-connection nonce TTL, clamped to [60, 3600] by the signer.
        'state_ttl_seconds' => env('BUSINESS_EMAIL_STATE_TTL_SECONDS', 600),
    ],

    'http' => [
        'connect_timeout_seconds' => env('BUSINESS_EMAIL_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('BUSINESS_EMAIL_REQUEST_TIMEOUT', 20),
    ],

    'send' => [
        // Message content bounds (plain text only in this slice).
        'max_subject_length' => 200,
        'max_body_length' => 20000,

        // A bounded retry budget per logical send (operation key). A
        // retryable provider failure (rate limited / temporary) may be
        // re-attempted by calling the sender again with the SAME operation
        // key, but never before next_attempt_at and never beyond this many
        // attempts. There is no automatic infinite retry anywhere.
        'max_attempts' => 3,
        'backoff_seconds' => [60, 300],

        // A `sending` row older than this is presumed to have crashed
        // mid-call. Because the provider may or may not have accepted the
        // message, it is NEVER re-sent: it becomes `unconfirmed`.
        'claim_lease_seconds' => 300,

        // Abuse bounds, counted over the last hour from the message
        // ledger itself. They stop one bad workflow (or one impatient
        // user) from turning into thousands of emails.
        'per_business_per_hour' => 200,
        'per_contact_per_hour' => 5,
    ],

];
