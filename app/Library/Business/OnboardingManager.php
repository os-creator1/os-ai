<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Business\CustomerOnboardingStarted;
use App\Events\Business\CustomerOnboardingStepCompleted;
use App\Enums\Website\WebsiteMode;
use App\Enums\Website\WebsiteStatus;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\Website\WebsiteModeManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\Website;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates the onboarding state machine on top of the Milestone 1
 * CustomerOnboardingRepository (pure data access, no step-order awareness)
 * and Milestone 2 BusinessManager (business identity orchestration). Holds
 * the step-order rules the repository deliberately does not enforce.
 */
class OnboardingManager
{
    /**
     * The customer-facing step order. Index position is used for every
     * "ahead of / behind" comparison. Legacy steps from the earlier eight-step
     * wizard are never in this list: they are resolved onto one of these by
     * currentStep()/canonicalStep().
     */
    private const STEP_ORDER = [
        OnboardingStep::Business,
        OnboardingStep::Location,
        OnboardingStep::Services,
        OnboardingStep::Website,
        OnboardingStep::Finish,
    ];

    /** The three answers to "How would you like to handle your website?". */
    public const WEBSITE_CHOICES = ['build', 'existing', 'later'];

    public function __construct(
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessManager $businessManager,
        private readonly ?WorkspaceRepository $workspaceRepository = null,
        private readonly ?AgencyClientWorkspaceRelationshipRepository $agencyClientRelationshipRepository = null,
    ) {
    }

    /**
     * Start onboarding for a customer, or return their existing row
     * unchanged (startForCustomer() is already idempotent — RFC-001 §10.4).
     * Dispatches CustomerOnboardingStarted only on genuine first creation.
     */
    /**
     * Whether this customer's account is an Agency. An Agency's Workspace features
     * do not depend on an individual local Business, so no individual-Business
     * setup is ever REQUIRED of it (it can still add its own Business explicitly).
     */
    public function isAgencyAccount(Customer $customer): bool
    {
        $workspaces = $this->workspaceRepository ?? app(WorkspaceRepository::class);
        $entitlements = app(\App\Library\Entitlement\EntitlementManager::class);

        foreach (\App\Models\Workspace::query()->where('owner_user_id', $customer->user_id)->get() as $workspace) {
            try {
                if ($entitlements->getWorkspaceEntitlementSummary($workspace)->tier === \App\Enums\Entitlement\WorkspacePlanTier::Agency) {
                    return true;
                }
            } catch (\Throwable) {
                // A Workspace with no readable plan is not an Agency.
            }
        }

        return false;
    }

    public function start(Customer $customer, bool $required = false): CustomerOnboarding
    {
        $onboarding = $this->onboardingRepository->startForCustomer($customer, $required);

        if ($onboarding->wasRecentlyCreated) {
            CustomerOnboardingStarted::dispatch($onboarding->id, $customer->user_id);
        }

        return $onboarding;
    }

    /**
     * Hand the Business a paid V1 signup provisioned (Workspace, one Draft
     * Business, its initial Location) to onboarding: start the customer's
     * onboarding row if there is none and attach THAT Business to it, so the
     * wizard continues it instead of creating another.
     *
     * Called by V1SignupManager's one activation seam once the provider has
     * confirmed payment, so a cancelled or unpaid signup never has an
     * onboarding row. Idempotent and race-safe: the browser return and the
     * webhook both reach it, customer_id is unique on customer_onboardings,
     * and an existing row (and its attachment) is left exactly as it is.
     *
     * @throws AuthorizationException if $business does not belong to $customer.
     */
    public function startForProvisionedBusiness(Customer $customer, Business $business, bool $required = true): CustomerOnboarding
    {
        if ((int) $business->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This business does not belong to the given customer.');
        }

        try {
            $onboarding = $this->start($customer, $required);
        } catch (UniqueConstraintViolationException $e) {
            // The other confirmation path won the insert. Converge on its row.
            $onboarding = $this->onboardingRepository->findByCustomer($customer) ?? throw $e;
        }

        if ($onboarding->business_id === null) {
            $onboarding = $this->onboardingRepository->attachBusiness($onboarding, $business);
        }

        return $onboarding;
    }

    /**
     * The one Draft Business a signup (or an earlier visit) already
     * provisioned for this customer, or null when onboarding is legitimately
     * responsible for creating it. Never an Agency-managed Client Business:
     * that one is activated only by its owner's own confirmation
     * (BusinessManager::activateClientBusiness()). Ambiguity (several Drafts)
     * adopts nothing rather than guessing.
     */
    private function provisionedDraftBusinessFor(Customer $customer): ?Business
    {
        $workspaces = $this->workspaceRepository ?? app(WorkspaceRepository::class);
        $relationships = $this->agencyClientRelationshipRepository ?? app(AgencyClientWorkspaceRelationshipRepository::class);

        $candidates = $this->businessRepository->draftBusinessesForCustomer($customer->user_id)
            ->filter(function (Business $business) use ($customer, $workspaces, $relationships): bool {
                if ($business->workspace_id === null) {
                    return false;
                }

                $workspace = $workspaces->findById((int) $business->workspace_id);

                return $workspace !== null
                    && (int) $workspace->owner_user_id === (int) $customer->user_id
                    && $relationships->findActiveForClientWorkspace((int) $workspace->id) === null;
            })
            ->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * The step the customer is actually on. The stored bookmark is trusted
     * for the current steps; a row written by the earlier eight-step wizard
     * (goals / assets / analysis / results / complete) is resolved from the
     * PERSISTED Business, Location, Services and Website facts onto the first
     * step that still needs the customer, so an old partial onboarding resumes
     * correctly under the new order without skipping a prerequisite.
     */
    public function currentStep(CustomerOnboarding $onboarding): OnboardingStep
    {
        if ($onboarding->status === OnboardingStatus::Completed) {
            return OnboardingStep::Finish;
        }

        return match ($onboarding->current_step) {
            OnboardingStep::Goals => OnboardingStep::Business,
            OnboardingStep::Assets,
            OnboardingStep::Analysis,
            OnboardingStep::Results,
            OnboardingStep::Complete => $this->firstUnmetStep($onboarding),
            default => $onboarding->current_step,
        };
    }

    /**
     * Resolve the step that should actually be shown. A null $requested
     * returns the onboarding's current step. An explicit $requested step
     * behind or at the current step is honored (revisiting); one ahead of it
     * is clamped back — this is what prevents step skipping. A removed legacy
     * step URL is mapped onto its current equivalent first and then clamped
     * the same way.
     */
    public function resolveStep(CustomerOnboarding $onboarding, ?OnboardingStep $requested = null): OnboardingStep
    {
        $current = $this->currentStep($onboarding);

        if ($requested === null) {
            return $current;
        }

        $requested = $this->canonicalStep($requested);

        return $this->stepIndex($requested) <= $this->stepIndex($current) ? $requested : $current;
    }

    /** The customer-facing step that follows $step (Finish is last). */
    public function nextStep(OnboardingStep $step): OnboardingStep
    {
        $index = min($this->stepIndex($step) + 1, count(self::STEP_ORDER) - 1);

        return self::STEP_ORDER[$index];
    }

    /**
     * Mark $completed done and advance to $next. Idempotent: re-completing
     * an already-completed step re-saves its data but never regresses
     * the current step, and no event fires unless something genuinely changed.
     *
     * @throws InvalidArgumentException if $completed is beyond the current
     *                                   step, or $next skips more than one step ahead of $completed.
     */
    public function completeStep(CustomerOnboarding $onboarding, OnboardingStep $completed, OnboardingStep $next): CustomerOnboarding
    {
        $this->assertValidStepCompletion($onboarding, $completed, $next);

        $currentStep = $this->currentStep($onboarding);
        $alreadyCompleted = in_array($completed->value, $onboarding->completed_steps ?? [], true);

        $effectiveNext = $this->stepIndex($next) > $this->stepIndex($currentStep)
            ? $next
            : $currentStep;
        $advancesCurrentStep = $effectiveNext !== $onboarding->current_step;

        $updated = $this->onboardingRepository->markStepComplete($onboarding, $completed, $effectiveNext);

        if (! $alreadyCompleted || $advancesCurrentStep) {
            CustomerOnboardingStepCompleted::dispatch($updated->id, $completed->value, $effectiveNext->value);
        }

        return $updated;
    }

    /**
     * Save the business-identity step: create or update the customer's
     * business through BusinessManager, attach it to the onboarding row if
     * not already attached, and advance the step — all in one transaction,
     * so a BusinessManager failure rolls back both the business write and
     * the onboarding advance, and dispatches neither's events.
     *
     * @throws AuthorizationException if the onboarding row, or the business
     *                                 it already references, does not belong to $customer.
     */
    public function saveBusinessStep(CustomerOnboarding $onboarding, Customer $customer, array $attributes): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $attributes) {
            $existingBusiness = null;

            if ($onboarding->business_id !== null) {
                $existingBusiness = $this->businessRepository->findOwnedByCustomer($onboarding->business_id, $customer->user_id);

                if ($existingBusiness === null) {
                    throw new AuthorizationException('The onboarding record references a business that does not belong to this customer.');
                }
            }

            // A paid V1 signup has already provisioned this customer's one
            // Draft Business (and its Location). That Business is the
            // canonical one, so the wizard continues it. Creation is only for
            // a customer who genuinely has no Business to continue.
            $existingBusiness ??= $this->provisionedDraftBusinessFor($customer);

            // The website address is the Website step's question; this step never touches it.
            $business = $this->businessManager->createOrUpdateOnboardingBusiness(
                $customer,
                $existingBusiness,
                Arr::except($attributes, ['website_url']),
            );

            if ($onboarding->business_id === null) {
                $onboarding = $this->onboardingRepository->attachBusiness($onboarding, $business);
            }

            return $this->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);
        });
    }

    /**
     * Save the location step through BusinessManager and advance to
     * Services, atomically. Requires the business step to already be done.
     */
    public function saveLocationStep(CustomerOnboarding $onboarding, Customer $customer, array $attributes): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $attributes) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);

            $this->businessManager->upsertPrimaryLocation($customer, $business, $attributes);

            return $this->completeStep($onboarding, OnboardingStep::Location, OnboardingStep::Services);
        });
    }

    /**
     * Save the services step through BusinessManager::syncServices() and
     * advance to Website, atomically. At least one service is required, with
     * exactly one primary.
     *
     * Services the customer already has are never duplicated or dropped here:
     * a submitted name that matches an existing service (even an inactive
     * one) is that service, and an existing active service the submission did
     * not mention stays as it is. Managing the full list is the Business
     * services screen's job, not the wizard's.
     *
     * @param  array<int, array<string, mixed>>  $services
     *
     * @throws InvalidArgumentException if no service is submitted, or the
     *                                   result would not have exactly one primary active service.
     */
    public function saveServicesStep(CustomerOnboarding $onboarding, Customer $customer, array $services): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $services) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);

            $rows = $this->reconcileServices($business, $services);

            if ($rows === []) {
                throw new InvalidArgumentException('At least one service is required.');
            }

            $this->businessManager->syncServices($customer, $business, $rows);

            if (! $this->servicesAreComplete($business)) {
                throw new InvalidArgumentException('Exactly one primary active service is required.');
            }

            return $this->completeStep($onboarding, OnboardingStep::Services, OnboardingStep::Website);
        });
    }

    /**
     * Record the customer's answer to "How would you like to handle your
     * website?" and advance to Finish. Nothing is generated, published,
     * connected or audited here:
     *
     *  - build:    intention recorded; the Business's primary website source
     *              becomes "hosted" so the Website module opens its own
     *              creation journey. No site is created.
     *  - existing: the address is saved in businesses.website_url through
     *              BusinessManager; the source becomes "external". It is only
     *              a saved address until the Website module checks it.
     *  - later:    website setup stays pending; a previously saved address is
     *              kept.
     *
     * A Business that already owns a MotionGrove-hosted Website is never
     * re-pointed or detached: its website source and address are left alone
     * and the step simply records that it is built with MotionGrove.
     *
     * @throws InvalidArgumentException for an unknown choice or an unusable address.
     */
    public function saveWebsiteStep(CustomerOnboarding $onboarding, Customer $customer, string $choice, ?string $websiteUrl = null): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        if (! in_array($choice, self::WEBSITE_CHOICES, true)) {
            throw new InvalidArgumentException('Choose how you would like to handle your website.');
        }

        return DB::transaction(function () use ($onboarding, $customer, $choice, $websiteUrl) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);
            $modes = app(WebsiteModeManager::class);

            if (Website::query()->where('business_id', $business->id)->exists()) {
                $choice = 'build';
            } elseif ($choice === 'existing') {
                try {
                    $address = $modes->normalizeAddress((string) $websiteUrl);
                } catch (ExternalSiteException) {
                    throw new InvalidArgumentException('Enter a valid public website address.');
                }

                // Saved BEFORE the source becomes "external", so the Website
                // module's address-change listener does not start a crawl:
                // checking the site is the Website module's own step.
                if ($this->sameAddress($business->website_url, $address) === false) {
                    $business = $this->businessManager->updateBusiness($customer, $business, ['website_url' => $address]);
                }

                $modes->set($business, WebsiteMode::External);
            } elseif ($choice === 'build') {
                $modes->set($business, WebsiteMode::Hosted);
            } else {
                $modes->set($business, WebsiteMode::None);
            }

            $onboarding = $this->onboardingRepository->recordWebsiteChoice($onboarding, $choice);

            return $this->completeStep($onboarding, OnboardingStep::Website, OnboardingStep::Finish);
        });
    }

    /**
     * The saved answer to the Website step: the onboarding's own record, or,
     * for an account that already has a website source (including a managed
     * Website), what that source says. Null when the question was never answered.
     */
    public function websiteChoice(CustomerOnboarding $onboarding, ?Business $business = null): ?string
    {
        $saved = $onboarding->metadata['website']['choice'] ?? null;

        if (is_string($saved) && in_array($saved, self::WEBSITE_CHOICES, true)) {
            return $saved;
        }

        $business ??= $onboarding->business;

        if ($business === null) {
            return null;
        }

        return match (app(WebsiteModeManager::class)->resolve($business)) {
            WebsiteMode::Hosted => 'build',
            WebsiteMode::External => 'existing',
            WebsiteMode::None => 'later',
            null => null,
        };
    }

    /**
     * The facts the Finish screen shows and completion enforces, read from
     * the persisted Business, Location, Services and Website records — never
     * from completed-step flags. `missing` maps each step that still needs
     * the customer to a plain-language reason; it is empty exactly when
     * completion would be accepted.
     *
     * @return array{
     *   business: ?Business,
     *   location: ?BusinessLocation,
     *   services: Collection,
     *   primaryService: ?BusinessService,
     *   website: array{choice: ?string, url: ?string, status: string},
     *   missing: array<string, string>
     * }
     */
    public function review(CustomerOnboarding $onboarding): array
    {
        // Read fresh: the cached relation may predate a save made in this request.
        $business = $onboarding->business_id !== null ? $onboarding->business()->first() : null;
        $location = null;
        $services = collect();
        $missing = [];

        if ($business === null || blank($business->name) || $business->industry === null) {
            $missing[OnboardingStep::Business->value] = 'Add your business name and what kind of business it is.';
        }

        if ($business !== null) {
            $primaries = $business->locations()->where('is_primary', true)->get();
            $location = $primaries->count() === 1 ? $primaries->first() : null;

            $services = $business->services()
                ->where('status', BusinessServiceStatus::Active)
                ->orderBy('sort_order')->orderBy('id')
                ->get();
        }

        if ($location === null || ! $this->locationIsComplete($location)) {
            $missing[OnboardingStep::Location->value] = $location === null
                ? 'Tell us where you work.'
                : 'Finish the details of where you work.';
        }

        $primaryService = $services->firstWhere('is_primary', true);

        if ($services->isEmpty() || $services->where('is_primary', true)->count() !== 1) {
            $missing[OnboardingStep::Services->value] = $services->isEmpty()
                ? 'Add at least one service you offer.'
                : 'Choose one primary service.';
        }

        $choice = $business === null ? null : $this->websiteChoice($onboarding, $business);

        if ($choice === null) {
            $missing[OnboardingStep::Website->value] = 'Tell us how you would like to handle your website.';
        }

        return [
            'business' => $business,
            'location' => $location,
            'services' => $services,
            'primaryService' => $primaryService,
            'website' => [
                'choice' => $choice,
                'url' => $business?->website_url,
                'status' => $this->websiteStatusText($business, $choice),
            ],
            'missing' => $missing,
        ];
    }

    /**
     * Thin persistence step for the retained OnboardingActionExecutor (the
     * historical analysis-recommendation flow, no longer reachable from the
     * wizard): it decides whether an action counts; this only records it.
     */
    public function recordFirstValueAction(CustomerOnboarding $onboarding, Customer $customer, string $actionKey): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return $this->onboardingRepository->recordFirstValueAction($onboarding, $actionKey);
    }

    /**
     * Idempotent: a repeat call on an already-completed onboarding returns it
     * unchanged and dispatches nothing, so resubmission, a double-click or a
     * concurrent request can never double-fire CustomerOnboardingCompleted.
     * The row is re-read under a lock, so of two simultaneous requests the
     * second one finds the first one's completion.
     *
     * @throws InvalidArgumentException if any completion prerequisite isn't met.
     */
    public function complete(CustomerOnboarding $onboarding, Customer $customer): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        if ($onboarding->status === OnboardingStatus::Completed) {
            return $onboarding;
        }

        return DB::transaction(function () use ($onboarding, $customer) {
            $locked = $this->onboardingRepository->findForUpdate($onboarding->id) ?? $onboarding;

            if ($locked->status === OnboardingStatus::Completed) {
                return $locked;
            }

            $this->assertCompletionPrerequisites($locked, $customer);

            $updated = $this->onboardingRepository->complete($locked);

            // A finished onboarding activates the Draft Business it was
            // completing, through the one activation seam.
            $this->businessManager->activateForCompletedOnboarding(
                $customer,
                $this->resolveOwnedBusiness($updated, $customer),
            );

            CustomerOnboardingCompleted::dispatch($updated->id, $customer->user_id);

            return $updated;
        });
    }

    /**
     * Completion needs the Business, its one primary Location and at least one
     * active Service with exactly one primary, all proven against the
     * persisted records (see review()). A website is optional: "later" is a
     * valid answer, but the question must have been answered.
     *
     * @throws InvalidArgumentException if a prerequisite is missing.
     * @throws AuthorizationException if the attached business doesn't belong to $customer.
     */
    private function assertCompletionPrerequisites(CustomerOnboarding $onboarding, Customer $customer): void
    {
        $this->resolveOwnedBusiness($onboarding, $customer);

        $missing = $this->review($onboarding)['missing'];

        if ($missing !== []) {
            throw new InvalidArgumentException('Onboarding cannot be completed yet: '.implode(', ', array_keys($missing)).' still need attention.');
        }
    }

    /** The first step whose persisted facts are not yet in place, or Finish. */
    private function firstUnmetStep(CustomerOnboarding $onboarding): OnboardingStep
    {
        $missing = $this->review($onboarding)['missing'];

        foreach (self::STEP_ORDER as $step) {
            if (isset($missing[$step->value])) {
                return $step;
            }
        }

        return OnboardingStep::Finish;
    }

    /** A removed legacy step mapped onto the customer-facing step that now covers it. */
    private function canonicalStep(OnboardingStep $step): OnboardingStep
    {
        return match ($step) {
            OnboardingStep::Goals => OnboardingStep::Business,
            OnboardingStep::Assets => OnboardingStep::Website,
            OnboardingStep::Analysis,
            OnboardingStep::Results,
            OnboardingStep::Complete => OnboardingStep::Finish,
            default => $step,
        };
    }

    /** Whether the location has what its service mode needs for local SEO. */
    private function locationIsComplete(BusinessLocation $location): bool
    {
        if (! $location->isActive() || blank($location->country_code)) {
            return false;
        }

        return match ($location->service_mode) {
            BusinessServiceMode::ServiceArea => filled($location->city) && filled($location->region),
            BusinessServiceMode::Storefront,
            BusinessServiceMode::Hybrid => filled($location->address_line_1) && filled($location->city) && filled($location->region),
            default => true,
        };
    }

    private function servicesAreComplete(Business $business): bool
    {
        $active = $business->services()->where('status', BusinessServiceStatus::Active);

        return (clone $active)->count() > 0
            && (clone $active)->where('is_primary', true)->count() === 1;
    }

    /**
     * Turn the submitted rows into the full list syncServices() expects:
     * submitted names are matched to existing services (so a retry or a stale
     * form never duplicates one), repeated names collapse, and existing active
     * services that were not submitted are carried along untouched.
     *
     * @param  array<int, array<string, mixed>>  $submitted
     * @return array<int, array<string, mixed>>
     */
    private function reconcileServices(Business $business, array $submitted): array
    {
        $existing = $business->services()->get();
        $byName = $existing->keyBy(fn (BusinessService $service) => $this->serviceKey($service->name));
        $rows = [];
        $seen = [];

        foreach ($submitted as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $this->serviceKey($name);

            if (isset($row['id']) && $row['id'] !== null) {
                $match = $existing->firstWhere('id', (int) $row['id']);

                if ($match === null) {
                    throw new InvalidArgumentException('That service does not belong to this business.');
                }

                $key = $this->serviceKey($match->name);
            } elseif ($byName->has($key)) {
                $row['id'] = $byName->get($key)->id;
                $row['status'] = BusinessServiceStatus::Active->value;
            }

            if (isset($seen[$key])) {
                $rows[$seen[$key]]['is_primary'] = ! empty($rows[$seen[$key]]['is_primary']) || ! empty($row['is_primary']);

                continue;
            }

            $seen[$key] = count($rows);
            $rows[] = $row;
        }

        if ($rows === []) {
            return [];
        }

        $requestedPrimary = collect($rows)->contains(fn (array $row) => ! empty($row['is_primary']));

        foreach ($existing->where('status', BusinessServiceStatus::Active) as $service) {
            $key = $this->serviceKey($service->name);

            if (isset($seen[$key])) {
                continue;
            }

            $rows[] = [
                'id' => $service->id,
                'name' => $service->name,
                'is_primary' => ! $requestedPrimary && $service->is_primary,
            ];
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['sort_order'] = $index;
        }

        // A single service is the primary one, whatever was ticked.
        if (count($rows) === 1) {
            $rows[0]['is_primary'] = true;
        }

        if (count(array_filter($rows, fn (array $row) => ! empty($row['is_primary']))) !== 1) {
            throw new InvalidArgumentException('Exactly one primary service is required.');
        }

        return $rows;
    }

    private function serviceKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function sameAddress(?string $current, string $address): bool
    {
        return $current !== null
            && rtrim(mb_strtolower($current), '/') === rtrim(mb_strtolower($address), '/');
    }

    /** What is actually true of the website today — never what the customer merely intends. */
    private function websiteStatusText(?Business $business, ?string $choice): string
    {
        $website = $business === null ? null : Website::query()->where('business_id', $business->id)->first();

        if ($website !== null) {
            return $website->status === WebsiteStatus::Published
                ? 'Your MotionGrove website is published.'
                : 'Your MotionGrove website is not published yet.';
        }

        return match ($choice) {
            'build' => 'Not built yet. You will create it with MotionGrove after setup.',
            'existing' => 'Address saved. We have not connected or checked it yet.',
            'later' => 'Not set up yet. You can do this any time from your home page.',
            default => 'Not answered yet.',
        };
    }

    /**
     * @throws InvalidArgumentException if the business step hasn't been completed yet.
     * @throws AuthorizationException if the attached business doesn't belong to $customer.
     */
    private function resolveOwnedBusiness(CustomerOnboarding $onboarding, Customer $customer): Business
    {
        if ($onboarding->business_id === null) {
            throw new InvalidArgumentException('The business step must be completed before this step.');
        }

        $business = $this->businessRepository->findOwnedByCustomer($onboarding->business_id, $customer->user_id);

        if ($business === null) {
            throw new AuthorizationException('The onboarding record references a business that does not belong to this customer.');
        }

        return $business;
    }

    private function assertValidStepCompletion(CustomerOnboarding $onboarding, OnboardingStep $completed, OnboardingStep $next): void
    {
        if ($this->stepIndex($completed) > $this->stepIndex($this->currentStep($onboarding))) {
            throw new InvalidArgumentException("Cannot complete step [{$completed->value}] before its prerequisites.");
        }

        if ($this->stepIndex($next) > $this->stepIndex($completed) + 1) {
            throw new InvalidArgumentException("Cannot advance to step [{$next->value}] from [{$completed->value}].");
        }
    }

    private function stepIndex(OnboardingStep $step): int
    {
        return array_search($this->canonicalStep($step), self::STEP_ORDER, true);
    }

    private function assertOwnership(Customer $customer, CustomerOnboarding $onboarding): void
    {
        if ((int) $onboarding->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This onboarding record does not belong to the given customer.');
        }
    }
}
