<?php

namespace App\Library\Business;

use App\Enums\Business\OnboardingStep;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Executes an onboarding "first-value action" (RFC-001 §14) from a closed
 * allowlist. Never accepts a request-controlled field, class, route, or
 * callback — the allowlist below is the only thing that decides what data
 * an action key is allowed to touch.
 */
class OnboardingActionExecutor
{
    /**
     * Every allowlisted action and the onboarding step it belongs to.
     */
    private const ACTION_STEPS = [
        'add_phone' => OnboardingStep::Business,
        'add_email' => OnboardingStep::Business,
        'add_website' => OnboardingStep::Business,
        'add_description' => OnboardingStep::Business,
        'add_location' => OnboardingStep::Location,
        'complete_location' => OnboardingStep::Location,
        'add_service' => OnboardingStep::Services,
        'confirm_primary_service' => OnboardingStep::Services,
        'add_gbp_url' => OnboardingStep::Assets,
        'add_facebook_url' => OnboardingStep::Assets,
        'add_instagram_url' => OnboardingStep::Assets,
    ];

    /**
     * Actions simple enough to fix with one inline value, and the exact
     * Business field that value is allowed to write.
     */
    private const INLINE_FIELDS = [
        'add_phone' => 'phone',
        'add_email' => 'email',
        'add_website' => 'website_url',
        'add_description' => 'description',
        'add_gbp_url' => 'google_business_profile_url',
        'add_facebook_url' => 'facebook_url',
        'add_instagram_url' => 'instagram_url',
    ];

    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessManager $businessManager,
        private readonly InitialBusinessSnapshotBuilder $snapshotBuilder,
        private readonly OnboardingManager $onboardingManager,
    ) {
    }

    /**
     * @return array{completed: bool, onboarding: CustomerOnboarding, step: OnboardingStep}
     *
     * @throws InvalidArgumentException if $actionKey isn't allowlisted, or if
     *                                   $fingerprint doesn't resolve to a finding in the onboarding's
     *                                   persisted analysis_payload with a matching action_key (covers
     *                                   unknown, stale — i.e. superseded by a newer analysis run — and
     *                                   action-key-mismatched submissions alike).
     * @throws AuthorizationException if the onboarding or its business doesn't belong to $customer.
     */
    public function execute(CustomerOnboarding $onboarding, Customer $customer, string $fingerprint, string $actionKey, ?string $value = null): array
    {
        if (! array_key_exists($actionKey, self::ACTION_STEPS)) {
            throw new InvalidArgumentException("Unknown onboarding action [{$actionKey}].");
        }

        $this->assertOwnership($customer, $onboarding);
        $business = $this->resolveOwnedBusiness($onboarding, $customer);

        $finding = $this->resolvePersistedFinding($onboarding, $fingerprint);

        if ($finding === null || $finding['action_key'] !== $actionKey) {
            throw new InvalidArgumentException('This finding is no longer available. Refresh and try again.');
        }

        return DB::transaction(function () use ($onboarding, $customer, $business, $fingerprint, $actionKey, $value) {
            // A click alone never counts as completion: this only writes data when
            // the request actually supplied a value for an inline-editable action.
            if (isset(self::INLINE_FIELDS[$actionKey]) && $value !== null && $value !== '') {
                $this->businessManager->updateBusiness($customer, $business, [
                    self::INLINE_FIELDS[$actionKey] => $value,
                ]);
            }

            $freshBusiness = $this->businessRepository->findOwnedByCustomer($business->id, $customer->user_id);
            $snapshot = $this->snapshotBuilder->build($freshBusiness, $onboarding->primary_goals ?? []);

            // Checked by the exact fingerprint the customer selected, not merely by
            // action_key — a different finding that happens to share the same
            // action_key must never be able to count as this one clearing.
            $fingerprintStillPresent = collect($snapshot['findings'])
                ->contains(fn (array $f) => $f['fingerprint'] === $fingerprint);

            if ($fingerprintStillPresent) {
                return [
                    'completed' => false,
                    'onboarding' => $onboarding,
                    'step' => self::ACTION_STEPS[$actionKey],
                ];
            }

            $updated = $this->onboardingManager->recordFirstValueAction($onboarding, $customer, $actionKey);

            return [
                'completed' => true,
                'onboarding' => $updated,
                'step' => OnboardingStep::Complete,
            ];
        });
    }

    /**
     * Looks up $fingerprint only in the onboarding's currently persisted
     * analysis_payload (never a fresh rebuild) — a fingerprint from a
     * superseded analysis run that no longer appears here is indistinguishable
     * from, and rejected the same as, one that never existed.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePersistedFinding(CustomerOnboarding $onboarding, string $fingerprint): ?array
    {
        $findings = $onboarding->analysis_payload['findings'] ?? [];

        return collect($findings)->firstWhere('fingerprint', $fingerprint);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOwnership(Customer $customer, CustomerOnboarding $onboarding): void
    {
        if ((int) $onboarding->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This onboarding record does not belong to the given customer.');
        }
    }

    /**
     * @throws InvalidArgumentException if no business is attached yet.
     * @throws AuthorizationException if the attached business doesn't belong to $customer.
     */
    private function resolveOwnedBusiness(CustomerOnboarding $onboarding, Customer $customer): Business
    {
        if ($onboarding->business_id === null) {
            throw new InvalidArgumentException('The business step must be completed before an action can be taken.');
        }

        $business = $this->businessRepository->findOwnedByCustomer($onboarding->business_id, $customer->user_id);

        if ($business === null) {
            throw new AuthorizationException('The onboarding record references a business that does not belong to this customer.');
        }

        return $business;
    }
}
