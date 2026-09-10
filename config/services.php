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
