<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free trials on ordinary (public) signup
    |--------------------------------------------------------------------------
    |
    | OFF. A new customer who signs up normally pays immediately; the only way
    | to receive a free trial is an eligible Private Offer, which a Platform
    | Owner extends to one specific customer. The plan catalog's trial fields
    | are inert while this is false, and no public page advertises a trial.
    |
    | This exists as a switch rather than being deleted so a deliberate,
    | company-wide trial promotion can be run later without a code change; it is
    | not an environment toggle anyone should flip casually, and the per-customer
    | one-trial-ever ledger applies either way.
    */
    'allow_public_trials' => (bool) env('PLATFORM_ALLOW_PUBLIC_TRIALS', false),

    /*
    |--------------------------------------------------------------------------
    | Private Offers
    |--------------------------------------------------------------------------
    |
    | OFF. While false no new Private Offer can be created, linked, viewed,
    | accepted or applied, and the owner screens and offer links answer 404.
    | Agreements already in force are unaffected: webhooks, renewals, schedule
    | reconciliation, cancel/resume and revoking all keep working, so existing
    | subscribers keep their original contractual billing terms either way.
    |
    | Enforced on the server (route middleware and the offer services), never
    | by hiding a button.
    */
    'private_offers_enabled' => (bool) env('PLATFORM_PRIVATE_OFFERS_ENABLED', false),

];
