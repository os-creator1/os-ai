<?php

    namespace App\Http\Middleware;

    use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

    class VerifyCsrfToken extends Middleware
    {
        /**
         * Indicates whether the XSRF-TOKEN cookie should be set on the response.
         *
         * @var bool
         */
        protected $addHttpCookie = true;

        /**
         * The URIs that should be excluded from CSRF verification.
         *
         * @var array
         */
        protected $except = [
            '/callback/sslcommerz/*',
            '/callback/aamarpay/*',
            '/callback/flutterwave/*',
            '/callback/razorpay/*',
            '/callback/paytech/*',
            'inbound/*',
            '/payment/*',
            'dlr/*',
            'webhooks/prospecting/*',
            'maintenance/notify',
            'stripe/webhook/usage-billing',
            // Implementation Contract 17 §5.8 — money lane B's own Connect
            // webhook. Exempt because Stripe signs the raw body; that
            // signature is verified before anything is inserted (§8.2).
            'stripe/webhook/business-payments',
            // Implementation Contract 21 §2/§12 — money lane A's own platform
            // subscription webhook. Exempt for the same reason: Stripe signs
            // the raw body, and that signature is verified before a single row
            // is inserted.
            'stripe/webhook/platform-subscriptions',
        ];

    }
