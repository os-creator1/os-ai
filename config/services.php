<?php

    use App\Models\User;

    return [

        /*
        |--------------------------------------------------------------------------
        | Third Party Services
        |--------------------------------------------------------------------------
        |
        | This file is for storing the credentials for third party services such
        | as Stripe, Mailgun, SparkPost and others. This file provides a sane
        | default location for this type of information, allowing packages
        | to have a conventional place to find your various credentials.
        |
        */

        'mailgun' => [
            'domain'   => env('MAILGUN_DOMAIN'),
            'secret'   => env('MAILGUN_SECRET'),
            'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        ],

        'postmark' => [
            'token' => env('POSTMARK_TOKEN'),
        ],

        'ses' => [
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'sparkpost' => [
            'secret' => env('SPARKPOST_SECRET'),
        ],

        'stripe'   => [
            'model'   => User::class,
            'key'     => env('STRIPE_KEY'),
            'secret'  => env('STRIPE_SECRET'),
            'webhook' => [
                'secret'    => env('STRIPE_WEBHOOK_SECRET'),
                'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
            ],
            // Implementation Contract 17 §5.8/§12.E — money lane B's OWN
            // Connect webhook endpoint and secret, deliberately separate from
            // the lane-D usage-billing webhook above. One platform-level
            // Connect endpoint receives events for every connected account and
            // each event carries its own `account` field, so this is a single
            // secret rather than one per Business.
            'connect_webhook' => [
                'secret'    => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
                'tolerance' => env('STRIPE_CONNECT_WEBHOOK_TOLERANCE', 300),
            ],
            // Implementation Contract 21 §2/§12 — money lane A's OWN webhook
            // endpoint and signing secret, deliberately separate from lane B's
            // Connect endpoint above and lane D's usage-billing endpoint.
            // Three lanes, three endpoints, three secrets: a lane-A event must
            // never be verifiable by, or resolvable against, another lane.
            //
            // This stays ENVIRONMENT configuration (§5.1). It is never moved
            // into an editable database column to make an admin screen
            // convenient, and it is never rendered back to a browser.
            'platform_subscription_webhook' => [
                'secret'    => env('STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET'),
                'tolerance' => env('STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_TOLERANCE', 300),
            ],
            // Lane C §C4.1 — money lane C's OWN webhook endpoint and signing
            // secret. Four lanes, four endpoints, four secrets: an Agency SaaS
            // subscription event must never be verifiable by, or resolvable
            // against, another lane.
            //
            // These are CONNECT events, so one platform-level endpoint receives
            // them for every Agency's connected account and each event carries
            // its own `account` field — a single secret, like lane B's, rather
            // than one per Agency. The `account` is then proven to belong to a
            // known Agency connection AND to match the subscription the event
            // claims to be about (§C4.1), which is what stops Agency X's event
            // from touching Agency Y.
            //
            // ENVIRONMENT configuration, always. It is never moved into an
            // editable database column to make an admin screen convenient, and
            // it is never rendered back to a browser.
            'agency_subscription_webhook' => [
                'secret'    => env('STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_SECRET'),
                'tolerance' => env('STRIPE_AGENCY_SUBSCRIPTION_WEBHOOK_TOLERANCE', 300),
            ],
            // Lane C — "Connect existing Stripe account." The platform's own
            // OAuth `client_id` (never a secret; safe to embed in a redirect
            // URL), from https://dashboard.stripe.com/settings/connect/onboarding-options/oauth.
            // Distinct from `secret` above: OAuth token exchange still
            // authenticates with the platform secret key, this is only the
            // identifier Stripe uses to show the Agency which platform is
            // asking to connect.
            //
            // REQUIRED DASHBOARD CONFIGURATION (Task 1) — Stripe registers
            // exactly ONE redirect_uri per client_id, for each of TEST and
            // LIVE mode, at the same Connect OAuth settings page above. It
            // MUST be set to this exact, fixed, workspace-agnostic route:
            // route('customer.agency.stripe.connect-existing.callback')
            // (routes/customer.php) — e.g. https://<app-host>/agency/stripe/connect-existing/callback.
            // There is no /customer URL prefix: RouteServiceProvider assigns the
            // customer. route NAME, but does not prefix its URL path.
            // A mismatch here fails every "Connect existing account"
            // attempt at Stripe, before this application ever sees it.
            'agency_connect_client_id' => env('STRIPE_AGENCY_CONNECT_CLIENT_ID'),
            // RFC-005 M3 contract §19 — new keys, additive only.
            'mode'         => env('STRIPE_MODE', 'test'),
            'api_version'  => env('STRIPE_API_VERSION'),
        ],


        /*
         * Laravel socialite services
         */
        'facebook' => [
            'active'        => env('SOCIALITE_FACEBOOK'),
            'client_id'     => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect'      => env('FACEBOOK_REDIRECT'),
        ],

        'twitter' => [
            'active'        => env('SOCIALITE_TWITTER'),
            'client_id'     => env('TWITTER_CLIENT_ID'),
            'client_secret' => env('TWITTER_CLIENT_SECRET'),
            'redirect'      => env('TWITTER_REDIRECT'),
        ],

        'google' => [
            'active'        => env('SOCIALITE_GOOGLE'),
            'client_id'     => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect'      => env('GOOGLE_REDIRECT'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Google Business Profile — Slice A (contract §9.1)
        |--------------------------------------------------------------------------
        |
        | A DEDICATED OAuth client, deliberately separate from the
        | `google` Socialite block above, which is platform SIGN-IN only
        | and must never be widened or reused: its callback authenticates a
        | session and can create a User, it requests no scopes, and it
        | obtains no refresh token.
        |
        | Google exposes exactly one Business Profile scope,
        | https://www.googleapis.com/auth/business.manage, for both reads
        | and writes; there is no read-only variant. Slice A's read-only
        | behaviour is therefore enforced structurally by
        | App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient,
        | not by the scope.
        |
        | Laravel Socialite is NOT used for this client.
        |
        */
        'google_business_profile' => [
            'client_id'     => env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET'),
            'redirect'      => env('GOOGLE_BUSINESS_PROFILE_REDIRECT'),
        ],

        'github' => [
            'active'        => env('SOCIALITE_GITHUB'),
            'client_id'     => env('GITHUB_CLIENT_ID'),
            'client_secret' => env('GITHUB_CLIENT_SECRET'),
            'redirect'      => env('GITHUB_REDIRECT'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Open AI API Key
        |--------------------------------------------------------------------------
        */

        'openai' => [
            'active'       => env('OPENAI_ACTIVE', false),
            'api_key'      => env('OPENAI_API_KEY'),
            'model'        => env('OPENAI_MODEL', 'gpt-4o'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'project'      => env('OPENAI_PROJECT'),
            'role'         => env('OPENAI_ROLE', 'user'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Telnyx — the single platform managed-messaging credential
        |--------------------------------------------------------------------------
        |
        | Customer Experience Slice 3 §4.4. Under the §28.3 Candidate B
        | architecture there is exactly one platform Telnyx credential, shared
        | by every managed Business and never stored per-Business.
        |
        | Keys only — no value appears here, in any migration, or in any
        | seeder. Presence alone does not activate anything: sending also
        | requires config('messaging.managed_messaging_enabled') to be true.
        |
        */

        'telnyx' => [
            'api_key'             => env('TELNYX_API_KEY'),
            'webhook_public_key'  => env('TELNYX_WEBHOOK_PUBLIC_KEY'),
            'mode'                => env('TELNYX_MODE', 'sandbox'),
        ],
    ];
