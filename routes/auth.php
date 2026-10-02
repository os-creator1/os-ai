<?php

    Route::group(
        ['namespace' => 'Auth'],
        function () {
            /*
            |------------------------------------------------------------
            | V1 signup steps: PLAN -> ACCOUNT -> BUSINESS -> PAYMENT
            |------------------------------------------------------------
            |
            | Registered whether or not signup is open, on purpose: when
            | `account.can_register` is off (or nothing is sellable) every
            | step renders the branded "registrations are unavailable" auth
            | screen instead of a bare 404 — see V1SignupController's
            | guestGate(). The authenticated re-entry routes further down
            | (Checkout return / resume) stay inside the open-signup block.
            |
            | GUEST ONLY. An already-authenticated user POSTing here with
            | another email would create a second User and silently switch
            | Auth::login() into it; the controller enforces that boundary
            | itself as well as via the `guest` middleware.
            */
            // Only the steps that write (the account check and the payment
            // commit) are throttled; plan and business answers are session-only.
            Route::middleware('guest')->group(function () {
                Route::get('register', 'V1SignupController@show')->name('register');
                Route::get('register/plan', 'V1SignupController@planStep')->name('register.plan');
                Route::post('register/plan', 'V1SignupController@selectPlan')->name('register.plan.select');
                Route::get('register/account', 'V1SignupController@accountStep')->name('register.account');
                Route::post('register/account', 'V1SignupController@storeAccount')->middleware('throttle:10,1')->name('register.account.store');
                Route::get('register/business', 'V1SignupController@businessStep')->name('register.business');
                Route::post('register/business', 'V1SignupController@storeBusiness')->name('register.business.store');
                Route::get('register/payment', 'V1SignupController@paymentStep')->name('register.payment');
                Route::post('register/payment', 'V1SignupController@startPayment')->middleware('throttle:10,1')->name('register.payment.start');
            });

            if (config('account.can_register')) {
                /*
                |------------------------------------------------------------
                | Implementation Contract 21 §7 — THE canonical V1 signup.
                |------------------------------------------------------------
                |
                | `register` resolves to V1SignupController. The legacy
                | RegisterController selects a legacy `Plan` and one of eight
                | inherited payment gateways, and produces a legacy
                | `Subscription` plus a FABRICATED complimentary Core
                | assignment (§3.4) — it is not, and must not be, the V1
                | customer signup.
                |
                | The legacy controller is left on disk for inherited installs
                | rather than deleted (deleting it would broaden scope well
                | past payments), but it is no longer routed: there are not two
                | equally valid customer signup paths.
                |
                | The legacy per-gateway registration payment routes below
                | (braintree / authorize-net / sslcommerz / aamarpay /
                | vodacommpesa, and pay-offline / pay-nowpayments) are
                | deliberately NOT part of V1 signup. V1 takes exactly one
                | payment route: a hosted lane-A Stripe Checkout Session.
                */
                // The authenticated re-entry routes (signup.plan / signup.resume)
                // are the supported path for someone who is already signed in;
                // the guest steps are registered above.
                // The Checkout return. `success` trusts provider truth, never a
                // query flag (§8.5); `cancelled` never deletes the account.
                Route::get('signup/complete', 'V1SignupController@success')->middleware('auth')->name('signup.success');
                Route::get('signup/cancelled', 'V1SignupController@cancelled')->middleware('auth')->name('signup.cancelled');
                // The resumable state for an account that exists with no plan.
                Route::get('signup/plan', 'V1SignupController@plan')->middleware('auth')->name('signup.plan');
                Route::post('signup/plan', 'V1SignupController@resume')->middleware(['auth', 'throttle:10,1'])->name('signup.resume');

                // Legacy-only, and unreachable from V1 signup: these two names
                // are still referenced by inherited Blade views that only the
                // now-unrouted RegisterController could render. Kept
                // registered so removing the signup entry point cannot make an
                // inherited view throw on a missing route name.
                Route::post('pay-offline', 'RegisterController@PayOffline')->name('payment.offline');
                Route::post('pay-nowpayments', 'RegisterController@PayNowpayments')->name('payment.nowpayments');

                Route::get('/email/verify', 'VerificationController@verificationNotice')->middleware(['auth'])->name('verification.notice');
                Route::get('/email/verify/{id}/{hash}', 'VerificationController@verificationVerify')->middleware(['auth', 'signed'])->name('verification.verify');
                Route::post('/email/verification-notification', 'VerificationController@verificationSend')->middleware(['auth', 'throttle:6,1'])->name('verification.send');
                Route::get('/sub-account/accept/{token}', 'VerificationController@subAccountAccept')->name('sub_account.accept');
                Route::post('/sub-account/accept/{token}', 'VerificationController@subAccountAcceptSubmit')->name('sub_account.accept.submit');
            }

            // Authentication Routes...
            Route::get('login', 'LoginController@showLoginForm')->name('login');
            Route::post('login', 'LoginController@login');
            Route::any('logout', 'LoginController@logout')->name('logout');

            Route::get('login/{provider}', 'LoginController@redirectToProvider')->name('social.login');
            Route::get('login/{provider}/callback', 'LoginController@handleProviderCallback')->name('social.callback');

            // Password Reset Routes...
            Route::get('password/reset', 'ForgotPasswordController@showLinkRequestForm')->name('password.request');
            Route::post('password/email', 'ForgotPasswordController@sendResetLinkEmail')->name('password.email');
            Route::get('password/reset/{token}', 'ResetPasswordController@showResetForm')->name('password.reset');
            Route::post('password/reset', 'ResetPasswordController@reset')->name('password.update');

            //two-step verification routes
            Route::get('verify/resend', 'TwoFactorController@resend')->name('verify.resend');
            Route::get('verify/backup-code', 'TwoFactorController@backUpCode')->name('verify.backup');
            Route::post('verify/backup-code', 'TwoFactorController@updateBackUpCode')->name('verify.backup.store');
            Route::resource('verify', 'TwoFactorController')->only(['index', 'store']);

            //common or public data access routes
            Route::get('download-sample-file', 'LoginController@downloadSampleFile')->name('sample.file');

        }
    );

    Route::group(
        [
            'namespace'  => 'User',
            'as'         => 'user.',
            'middleware' => ['auth', 'verified'],
        ],
        function () {
            /*
             * User Dashboard Specific
             */
            Route::get('/dashboard', 'UserController@index')->middleware('business.onboarding')->name('home');

            /*
             * switch view
             */
            Route::get('/switch-view', 'AccountController@switchView')->name('switch_view');

            /*
             * User Account Specific
             */
            Route::get('account', 'AccountController@index')->name('account');
            Route::get('avatar', 'AccountController@avatar')->name('avatar');
            Route::post('avatar', 'AccountController@updateAvatar');
            Route::post('remove-avatar', 'AccountController@removeAvatar')->name('remove_avatar');

            /*
             * User Profile Update
             */
            Route::patch('account/update', 'AccountController@update')->name('account.update');
            Route::post('account/update-information', 'AccountController@updateInformation')->name('account.update_information');

            Route::post('account/change-password', 'AccountController@changePassword')->name('account.change.password');

            Route::get('account/two-factor/{status}', 'AccountController@twoFactorAuthentication')->name('account.twofactor.auth');
            Route::get('account/generate-two-factor-code', 'AccountController@generateTwoFactorAuthenticationCode')->name('account.twofactor.generate_code');
            Route::post('account/two-factor/{status}', 'AccountController@updateTwoFactorAuthentication');

            Route::get('account/top-up', 'AccountController@topUp')->name('account.top_up');
            Route::post('account/top-up', 'AccountController@checkoutTopUp');
            Route::post('account/pay-top-up', 'AccountController@payTopUp')->name('account.pay');

            /*Version 3.9*/
            Route::post('account/get-units', 'AccountController@getUnits')->name('account.get.units');


            //notifications

            Route::post('account/notifications', 'AccountController@notifications')->name('account.notifications');
            Route::post('account/notifications/{notification}/active', 'AccountController@notificationToggle')->name('account.notifications.toggle');
            Route::post('account/notifications/{notification}/delete', 'AccountController@deleteNotification')->name('account.notifications.delete');
            Route::post('notifications/batch_action', 'AccountController@notificationBatchAction')->name('account.notifications.batch_action');

            //Registration Payment
            Route::any('account/{user}/success/{plan}/{payment_method}', 'AccountController@successfulRegisterPayment')->name('registers.payment_success')->withoutMiddleware(['verified', 'auth']);
            Route::any('account/{user}/cancel', 'AccountController@cancelledRegisterPayment')->name('registers.payment_cancel');
            Route::post('account/{user}/braintree', 'AccountController@braintreeRegister')->name('registers.braintree');
            Route::post('account/{user}/authorize-net', 'AccountController@authorizeNetRegister')->name('registers.authorize_net');
            Route::any('callback/sslcommerz/register', 'AccountController@sslcommerzRegister')->name('callback.sslcommerz.register');
            Route::any('callback/aamarpay/register', 'AccountController@aamarpayRegister')->name('callback.aamarpay.register');
            Route::post('account/{user}/vodacommpesa', 'AccountController@vodacommpesaRegister')->name('registers.vodacommpesa');
            Route::post('account/delete', 'AccountController@delete')->name('account.delete');

            /*
             * Version 3.7
             */

            Route::get('pricing', 'AccountController@pricing')->name('account.pricing');
            Route::post('pricing', 'AccountController@searchPricing');
            Route::post('pricing/view', 'AccountController@viewPricing')->name('account.pricing-view');

            /*
             * Product updates — read-only. The platform owner publishes
             * (admin Announcements); a customer lists and opens what was sent
             * to them, and opening one marks it read. No search, bulk or
             * mark-read endpoints exist on this side any more.
             */
            Route::get('announcement', 'AccountController@announcement')->name('account.announcement');
            Route::get('announcement/{announcement}', 'AccountController@viewAnnouncement')->name('account.announcement.view');

            Route::post('account/dlt-entity-id', 'AccountController@dltEntityId')->name('account.dlt-entity-id');
            Route::post('account/dlt-telemarketer-id', 'AccountController@dltTelemarketerId')->name('account.dlt-telemarketer-id');

            /*Version 3.13*/

            Route::get('account/{user}/impersonate', 'AccountController@impersonate')->name('account.login_as');

        }
    );
