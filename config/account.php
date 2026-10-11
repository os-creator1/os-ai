<?php

    return [
        /*
        |--------------------------------------------------------------------------
        | Account settings
        |--------------------------------------------------------------------------
        */

        'updating_enabled'  => env('ACCOUNT_UPDATING_ENABLED', true),
        'can_register'      => env('ACCOUNT_CAN_REGISTER', true),
        'can_delete'        => env('ACCOUNT_CAN_DELETE', true),
        'verify_account'    => env('ACCOUNT_VERIFICATION', true),
        'create_subaccount' => env('ACCOUNT_CREATE_SUBACCOUNT', true),
        'delete_subaccount' => env('ACCOUNT_DELETE_SUBACCOUNT', true),
        // Social providers whose e-mail address is asserted verified by the provider itself (used for linking / sign-up).
        'social_verified_email_providers' => ['google', 'github'],
    ];
