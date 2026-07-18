<?php

namespace App\Http\Middleware;

use App\Enums\Business\OnboardingStatus;
use App\Library\Business\OnboardingManager;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects an authenticated customer to their onboarding step only when the
 * feature is enabled by configuration AND onboarding exists AND is marked
 * required AND isn't finished (RFC-001 §21). Existing customers without an
 * onboarding row are never touched. Disabling the feature only disables this
 * automatic redirect — it never touches onboarding data, and direct
 * onboarding routes remain reachable regardless.
 */
class EnsureRequiredBusinessOnboardingIsComplete
{
    private const EXEMPT_ROUTE_NAME_PREFIXES = [
        'customer.onboarding.',
        'logout',
        'verification.',
        'customer.subscriptions.',
        'support.',
    ];

    public function __construct(
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly OnboardingManager $onboardingManager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // A missing config/business.php (Milestone 6) resolves this to null,
        // which must safely behave as disabled — hence the explicit default.
        if (! config('business.onboarding.enabled', false)) {
            return $next($request);
        }

        if (! auth()->check()) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        $customer = auth()->user()->customer;

        if ($customer === null) {
            return $next($request);
        }

        $onboarding = $this->onboardingRepository->findByCustomer($customer);

        if ($onboarding === null || ! $onboarding->is_required) {
            return $next($request);
        }

        if (in_array($onboarding->status, [OnboardingStatus::Completed, OnboardingStatus::Dismissed], true)) {
            return $next($request);
        }

        $step = $this->onboardingManager->resolveStep($onboarding);

        return redirect()->route('customer.onboarding.show', ['step' => $step->value]);
    }

    private function isExemptRoute(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return false;
        }

        foreach (self::EXEMPT_ROUTE_NAME_PREFIXES as $prefix) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
