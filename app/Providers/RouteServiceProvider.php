<?php

    namespace App\Providers;

    use Illuminate\Cache\RateLimiting\Limit;
    use Illuminate\Support\Facades\RateLimiter;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
    use Illuminate\Http\Request;

    class RouteServiceProvider extends ServiceProvider
    {
        /**
         * This namespace is applied to your controller routes.
         *
         * In addition, it is set as the URL generator's root namespace.
         *
         * @var string
         */
        protected $namespace = 'App\Http\Controllers';

        /**
         * Define your route model bindings, pattern filters, etc.
         *
         * @return void
         */
        public function boot()
        {
            //

            // Release-risk closure item 6 — the public contact subscribe /
            // unsubscribe MUTATION endpoints (routes/public.php). Two bounds,
            // both keyed on the group named in the URL so one Business's
            // traffic never spends another's allowance: a per-visitor bound
            // sized for a person fixing typos, and a per-group ceiling so a
            // distributed flood cannot fill or empty one list. The existing
            // group lookup / captcha / validation inside the controllers are
            // untouched.
            RateLimiter::for('public-contact-consent', function (Request $request) {
                $group = $request->route('contact');
                $group = is_object($group) ? ($group->uid ?? get_class($group)) : (string) $group;

                return [
                    Limit::perMinute(10)->by('visitor|' . $group . '|' . $request->ip()),
                    Limit::perMinute(120)->by('group|' . $group),
                ];
            });

            // Public proposal signing / payment. The framework's string form
            // (`throttle:10,1`) keys every such route on domain|IP ALONE, so
            // the document view, signing, pay-start, pay-status polling and the
            // booking/forms mutations all drew down ONE shared per-IP counter:
            // a few page views plus a status poll could spend the 10 a signer
            // needed. These named limiters give each action its own bucket.
            //
            // Keys carry a one-way hash of the document uid (a public
            // identifier, never the bearer token) and the IP. Three bounds:
            // per document+visitor (a person fixing typos), per visitor across
            // ALL documents (rotating uids cannot dodge it), and per document
            // across visitors (a distributed hammer on one link).
            // Native website analytics: its OWN bucket. A numeric `throttle:N,1` keys anonymous visitors by route domain + IP, so it would
            // share a counter with the booking submission's `throttle:10,1`, and measuring a visit could cost that visitor their booking.
            RateLimiter::for('analytics-collect', fn (Request $request) => Limit::perMinute(240)->by('analytics-collect|' . $request->ip()));
            RateLimiter::for('public-document-sign', fn (Request $request) => $this->documentLimits($request, 'sign', 10, 60, 60));
            RateLimiter::for('public-document-pay', fn (Request $request) => $this->documentLimits($request, 'pay', 10, 60, 60));
            RateLimiter::for('public-document-pay-status', fn (Request $request) => $this->documentLimits($request, 'status', 40, 240, 240));

            parent::boot();
            $this->map();
        }

        /**
         * Define the routes for the application.
         *
         * @return void
         */
        public function map()
        {
            // A dedicated SMTP gateway must not expose the main app's public,
            // customer, admin or API routes on its gateway hostname.
            if (config('business_email.smtp_gateway.serve')) {
                $this->mapSmtpGatewayRoutes();

                return;
            }

            $this->mapApiRoutes();
            $this->mapWebRoutes();
        }

        /**
         * Define the "web" routes for the application.
         *
         * These routes all receive session state, CSRF protection, etc.
         *
         * @return void
         */
        protected function mapWebRoutes()
        {

            // The public marketing site claims `/` and its page URLs before the legacy root redirect.
            Route::middleware('web')
                ->group(base_path('routes/marketing.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::middleware(['web', \App\Http\Middleware\RecordLegacyWebhookUsage::class, \App\Http\Middleware\DisableUnsupportedLegacyWebhooks::class])
                ->namespace($this->namespace)
                ->group(base_path('routes/public.php'));

            Route::middleware(['web', 'auth', 'can:access backend', 'twofactor'])
                ->namespace($this->namespace . '\Admin')
                ->prefix(config('app.admin_path'))
                ->as('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware(['web', 'twofactor', 'legacy.payments'])
                ->namespace($this->namespace)
                ->group(base_path('routes/auth.php'));

            Route::middleware(['web', 'auth', 'can:access_backend', 'twofactor', 'legacy.payments', \App\Http\Middleware\SoftphoneFrame::class])
                ->namespace($this->namespace . '\Customer')
                ->as('customer.')
                ->group(base_path('routes/customer.php'));
        }

        /** Only a deployment explicitly configured as the SMTP gateway exposes these (stateless, signed). */
        protected function mapSmtpGatewayRoutes()
        {
            if (! config('business_email.smtp_gateway.serve')) {
                return;
            }

            Route::prefix('internal/smtp-gateway/v1')
                ->group(base_path('routes/smtp_gateway.php'));
        }

        /**
         * Define the "api" routes for the application.
         *
         * These routes are typically stateless.
         *
         * @return void
         */
        protected function mapApiRoutes()
        {
            $this->configureRateLimiting();

            Route::prefix('api/http')
                ->name('api_http.')
                ->middleware(['api', 'json.response'])
                ->namespace($this->namespace . '\API')
                ->group(base_path('routes/http.php'));

            Route::prefix('api/v3')
                ->name('api.')
                ->middleware(['api', 'auth:sanctum', 'json.response', \App\Http\Middleware\RejectMobileTokenOnLegacyApi::class, \App\Http\Middleware\CustomerAccountAccessApiGate::class])
                ->namespace($this->namespace . '\API')
                ->group(base_path('routes/api.php'));

            // MotionGrove Mobile (see docs/mobile/ARCHITECTURE.md). Its own versioned surface; login is public and
            // throttled, everything else needs a `mobile`-ability Sanctum token.
            Route::prefix('api/mobile/v1')
                ->name('mobile.')
                ->middleware(['api', 'json.response'])
                ->group(base_path('routes/mobile.php'));

        }

        /**
         * @return array<int, \Illuminate\Cache\RateLimiting\Limit>
         */
        protected function documentLimits(Request $request, string $action, int $perDocumentVisitor, int $perVisitor, int $perDocument): array
        {
            $doc = sha1((string) $request->route('uid'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute($perDocumentVisitor)->by($action . '|doc-visitor|' . $doc . '|' . $ip),
                Limit::perMinute($perVisitor)->by($action . '|visitor|' . $ip),
                Limit::perMinute($perDocument)->by($action . '|doc|' . $doc),
            ];
        }

        /**
         * Configure the rate limiters for the application.
         *
         * @return void
         */
        protected function configureRateLimiting()
        {
            RateLimiter::for('api', function (Request $request) {
                return Limit::perMinute(config('app.api_rate_limit'))->by(optional($request->user())->id ?: $request->ip());
            });
        }

    }
