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
            // Implementation Contract 15 §11 — Google/Outlook push
            // notifications carry no Laravel session; authenticity is the
            // URL-embedded HMAC token (ExternalCalendarWebhookToken),
            // verified in-controller before anything else.
            'webhooks/calendar/*',
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
            // Lane C §C4.1 — money lane C's own Agency SaaS subscription
            // webhook. Exempt for the same reason as the three above: Stripe
            // signs the raw body, and that signature is verified before a
            // single row is inserted.
            'stripe/webhook/agency-subscriptions',
            // Telnyx porting-order status events. Telnyx cannot hold a Laravel session or token; authenticity is the
            // Ed25519 signature over `timestamp|body`, verified in TelnyxPortingWebhookController BEFORE anything is read
            // or written (bad/missing signature => 403, nothing applied). This exact path only, never a wildcard.
            'webhooks/telnyx-porting',
        ];

        /**
         * A public form is framed by the customer's own website, a different site from this app: the browser sends the
         * iframe no session cookie, so a session CSRF token can never match and every real quote request would be refused
         * with a 419. Exactly ONE request shape is exempt: POST forms/{deploymentUid} (a UUID, nothing after it). It is
         * anonymous and creates no authenticated state; it is protected by the HMAC-signed, deployment- and version-pinned
         * operation token the form carries (FormOperationToken, verified before anything is read), the honeypot and the
         * 10/minute throttle. Any nested or future path under forms/ stays CSRF-protected.
         */
        private const PUBLIC_FORM_SUBMIT = '#^forms/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#i';

        protected function inExceptArray($request)
        {
            if ($request->isMethod('POST') && preg_match(self::PUBLIC_FORM_SUBMIT, $request->path()) === 1) {
                return true;
            }

            return parent::inExceptArray($request);
        }
    }
