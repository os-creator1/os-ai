<?php

namespace App\Library\Business;

use App\DTO\GoogleBusinessProfile\GoogleLocationProfile;
use App\Enums\Business\BusinessStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Exceptions\Business\GoogleOnboardingUnavailableException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;
use App\Exceptions\GoogleBusinessProfile\GoogleLocationAlreadyClaimedException;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager;
use App\Library\Workspace\WorkspaceManagementAuthority;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCandidateTokenSigner;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileEnumerator;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOnboardingReader;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessGoogleConnectionRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

/**
 * The narrowly authorized bridge between customer onboarding (which works on a
 * DRAFT Business) and the Google Business Profile integration (which, for
 * every other purpose, requires an ACTIVE one).
 *
 * WHAT IT REUSES, UNCHANGED: the Google OAuth state signer and its actor
 * binding, the connection state machine and its token invariants, account and
 * location enumeration, the signed candidate token, the call budget and the
 * operation ledger, and the read-only provider client (which declares no
 * mutation method). Nothing here can write to Google.
 *
 * WHAT IT ADDS, and all it adds:
 *
 *  - authorizesCallback(): the single extra case in which the fixed OAuth
 *    callback accepts a Draft Business — only when it is the very Business
 *    attached to the CALLER'S OWN unfinished onboarding. The active-Business
 *    boundary for every other path is not touched.
 *  - the preview snapshot: the selected profile's importable fields, sealed
 *    with the application key (encrypted and authenticated), bound to the
 *    Business, connection and actor, and short-lived. It travels in the
 *    customer's own form, so no Google Content is persisted server-side.
 *
 * It saves nothing to the Business itself: approved details go through
 * OnboardingManager's canonical step services (see GoogleOnboardingConfirmer).
 */
final class GoogleOnboardingBridge
{
    /** A preview is a single sitting; a stale one forces a fresh import. */
    private const SNAPSHOT_TTL_SECONDS = 900;

    public function __construct(
        private readonly OnboardingManager $onboarding,
        private readonly GoogleBusinessProfileConnectionManager $connections,
        private readonly GoogleBusinessProfileEnumerator $enumerator,
        private readonly GoogleBusinessProfileOnboardingReader $reader,
        private readonly GoogleBusinessProfileCandidateTokenSigner $candidateTokens,
        private readonly GoogleBusinessProfileOAuthConfig $oauthConfig,
        private readonly BusinessGoogleConnectionRepository $connectionRepository,
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly GoogleBusinessProfileBindingManager $bindings,
        private readonly WorkspaceManagementAuthority $authority,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /** Whether this deployment can start Google authorization at all. */
    public function isConfigured(): bool
    {
        try {
            $this->oauthConfig->assertUsable();
        } catch (GoogleBusinessProfileConfigurationException) {
            return false;
        }

        return true;
    }

    /**
     * May this user connect Google for THIS onboarding?
     *
     * The global default of manage_google_business_profile stays FALSE, so a fresh signup does not hold it. The
     * Workspace OWNER of the onboarding's own Workspace is nonetheless authorized — through the same
     * WorkspaceManagementAuthority rule Google Ads and Meta Ads already use (active Workspace owner, or the explicit
     * permission) — and only here, in the onboarding bridge: the Active-Business GBP module keeps its permission gate.
     * Non-owners still need the explicit permission; a View-As session authorizes nothing; entitlement, Draft
     * ownership, OAuth actor binding and the callback chain are checked separately and unchanged.
     */
    public function mayManageGoogle(User $actor, CustomerOnboarding $onboarding): bool
    {
        $workspaceId = $onboarding->business_id !== null
            ? Business::query()->whereKey($onboarding->business_id)->value('workspace_id')
            : $this->onboarding->provisionedDraftFor($actor->customer ?? new Customer(['user_id' => $actor->id]))?->workspace_id;

        $workspace = $workspaceId !== null
            ? Workspace::query()->find($workspaceId)
            : Workspace::query()->where('owner_user_id', $actor->id)->where('is_active', true)->orderBy('id')->first();

        if ($workspace === null) {
            return Gate::forUser($actor)->allows('manage_google_business_profile');
        }

        return $this->authority->allows($actor, $workspace, 'manage_google_business_profile');
    }

    /**
     * WHY the Google quick start is, or is not, offered to this customer — as a CLOSED reason code, never a value.
     * Used by the entry point and by the operator diagnostic, so the two can never disagree. The order is the order
     * the checks matter in. Nothing here reads, returns or logs a credential, token or configured value.
     *
     * @param  bool  $mayManageGoogle  whether the customer holds manage_google_business_profile (the caller's Gate)
     * @return array{available: bool, reason: string}
     */
    public function availability(Customer $customer, CustomerOnboarding $onboarding, bool $mayManageGoogle): array
    {
        try {
            $this->oauthConfig->assertUsable();
        } catch (GoogleBusinessProfileConfigurationException $exception) {
            return ['available' => false, 'reason' => 'oauth_' . $exception->reason];
        }

        if (! $mayManageGoogle) {
            return ['available' => false, 'reason' => 'permission_manage_google_business_profile_missing'];
        }

        if ($onboarding->status === OnboardingStatus::Completed) {
            return ['available' => false, 'reason' => 'onboarding_completed'];
        }

        if ($onboarding->business_id !== null) {
            $business = Business::query()->find($onboarding->business_id);

            if ($business === null || (int) $business->customer_id !== (int) $customer->user_id) {
                return ['available' => false, 'reason' => 'business_not_owned_by_customer'];
            }

            if ($business->status !== BusinessStatus::Draft) {
                return ['available' => false, 'reason' => 'business_not_draft'];
            }

            return $this->entitlementAvailability($business, (int) $customer->user_id);
        }

        // Nothing attached yet: a Draft Business a signup provisioned is adopted, so judge THAT one.
        $draft = $this->onboarding->provisionedDraftFor($customer);

        return $draft === null ? ['available' => true, 'reason' => 'available_business_created_on_connect'] : $this->entitlementAvailability($draft, (int) $customer->user_id);
    }

    /** @return array{available: bool, reason: string} */
    private function entitlementAvailability(Business $business, int $actorUserId): array
    {
        try {
            $this->assertEntitled($business, $actorUserId);
        } catch (GoogleOnboardingUnavailableException $exception) {
            return ['available' => false, 'reason' => $exception->reasonCode];
        }

        return ['available' => true, 'reason' => 'available'];
    }

    /** The Draft Business attached to this unfinished onboarding, if any. */
    public function draftBusinessFor(CustomerOnboarding $onboarding, Customer $customer): ?Business
    {
        if ($onboarding->business_id === null || $onboarding->status === OnboardingStatus::Completed) {
            return null;
        }

        $business = Business::query()->find($onboarding->business_id);

        if ($business === null
            || (int) $business->customer_id !== (int) $customer->user_id
            || $business->status !== BusinessStatus::Draft) {
            return null;
        }

        return $business;
    }

    public function connectionFor(Business $business): ?BusinessGoogleConnection
    {
        return $this->connectionRepository->findForBusiness($business, GoogleConnectionProduct::BusinessProfile);
    }

    /**
     * Begin authorization. Returns Google's consent URL, or null when this
     * Business already has an active connection (the caller goes straight to
     * choosing a profile; there is no "reconnect a healthy connection").
     *
     * @param  array<string, string>|null  $locale  explicit country_code/timezone/currency_code, needed only when
     *                                              there is no Draft Business to continue
     *
     * @throws GoogleOnboardingUnavailableException
     * @throws GoogleBusinessProfileConfigurationException
     */
    public function begin(Customer $customer, CustomerOnboarding $onboarding, ?array $locale, bool $addressConsent): ?string
    {
        if (! $this->isConfigured()) {
            throw GoogleOnboardingUnavailableException::notAvailable();
        }

        $onboarding = $this->onboarding->attachBusinessForGoogleImport($onboarding, $customer, $locale, $addressConsent);
        $business = $this->draftBusinessFor($onboarding, $customer) ?? throw GoogleOnboardingUnavailableException::notAvailable();

        $this->assertEntitled($business, (int) $customer->user_id);

        $existing = $this->connectionFor($business);

        if ($existing !== null && $existing->isActive()) {
            return null;
        }

        return $this->connections->beginConnect($business, (int) $customer->user_id);
    }

    /**
     * The ONE additional case the fixed OAuth callback accepts: a Draft
     * Business that is attached to the actor's own unfinished onboarding.
     * Everything else about the callback (state, actor binding, tenancy,
     * entitlement, permission, nonce) is still enforced by the callback.
     */
    public function authorizesCallback(Business $business, User $actor): bool
    {
        if ($business->status !== BusinessStatus::Draft || (int) $business->customer_id !== (int) $actor->id) {
            return false;
        }

        $onboarding = $this->onboardingRepository->findByCustomerId((int) $actor->id);

        return $onboarding !== null
            && $onboarding->status !== OnboardingStatus::Completed
            && (int) $onboarding->business_id === (int) $business->id;
    }

    /**
     * @throws GoogleOnboardingUnavailableException
     */
    public function assertEntitled(Business $business, int $actorUserId): void
    {
        $workspace = $business->workspace_id === null ? null : Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            throw GoogleOnboardingUnavailableException::notAvailable();
        }

        try {
            $decision = $this->entitlements->decide($workspace, $business, PlatformFeature::GoogleBusinessProfileModule->value, $actorUserId);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            throw GoogleOnboardingUnavailableException::notAvailable();
        }

        if (! $decision->allowed) {
            throw GoogleOnboardingUnavailableException::notEntitled();
        }
    }

    /**
     * The profiles this Google account actually manages, each with a signed
     * selection token. Nothing is pre-selected and nothing is guessed.
     *
     * @return array{accounts: array, offers: array<int, array{candidate: \App\DTO\GoogleBusinessProfile\GoogleLocationCandidate, token: string}>}
     *
     * @throws \App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException
     */
    public function offers(Business $business, BusinessGoogleConnection $connection, int $actorUserId, bool $addressConsent): array
    {
        $result = $this->enumerator->enumerate($business, $connection, $actorUserId, $addressConsent);

        $offers = [];

        foreach ($result['candidates'] as $candidate) {
            $offers[] = [
                'candidate' => $candidate,
                'token' => $this->candidateTokens->issue($business, $connection, $actorUserId, $candidate),
            ];
        }

        return ['accounts' => $result['accounts'], 'offers' => $offers];
    }

    /**
     * Verify a selection token and read that one profile (read-only, nothing
     * persisted). Null when the token is not valid for this Business,
     * connection and actor — the customer is sent back to choose again.
     *
     * @return array{token: string, snapshot: array<string, mixed>}|null
     *
     * @throws \App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException
     */
    public function importSelection(string $candidateToken, Business $business, BusinessGoogleConnection $connection, int $actorUserId, bool $addressConsent): ?array
    {
        $pair = $this->candidateTokens->verify($candidateToken, $business, $connection, $actorUserId);

        if ($pair === null) {
            return null;
        }

        $profile = $this->reader->read($business, $connection, $actorUserId, $pair['location'], $addressConsent);
        $snapshot = $this->snapshotOf($profile);

        // The explicitly selected account/location pair rides inside the SAME sealed, actor-bound token, so the
        // choice the customer made is exactly what is linked at confirmation. Never a client-supplied field.
        $selection = ['a' => $pair['account'], 'l' => $pair['location']];

        return ['token' => $this->seal($snapshot, $selection, $business, $connection, $actorUserId), 'snapshot' => $snapshot];
    }

    /** @return array<string, mixed>|null the importable fields, or null when the token is invalid, foreign or stale */
    public function open(?string $token, Business $business, BusinessGoogleConnection $connection, int $actorUserId): ?array
    {
        return $this->openSelection($token, $business, $connection, $actorUserId)['snapshot'] ?? null;
    }

    /**
     * @return array{snapshot: array<string, mixed>, account: string, location: string}|null
     */
    public function openSelection(?string $token, Business $business, BusinessGoogleConnection $connection, int $actorUserId): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['b'] ?? null) !== (int) $business->id
            || ($payload['c'] ?? null) !== (int) $connection->id
            || ($payload['u'] ?? null) !== $actorUserId
            || ! is_int($payload['e'] ?? null)
            || $payload['e'] <= now()->getTimestamp()
            || ! is_array($payload['d'] ?? null)
            || ! is_string($payload['g']['a'] ?? null)
            || ! is_string($payload['g']['l'] ?? null)) {
            return null;
        }

        return ['snapshot' => $payload['d'], 'account' => $payload['g']['a'], 'location' => $payload['g']['l']];
    }

    /**
     * Save the approved card through the CANONICAL onboarding step services —
     * Business, Location, Services and Website, in the wizard's own order, each
     * with its own validation, locking and step bookkeeping. Nothing is
     * written to Google, and the Business is not activated: the customer still
     * finishes through the ordinary Finish step.
     *
     * A step whose answer the card did not supply (an unanswered website
     * choice) is simply not saved, so the wizard resumes there.
     *
     * @param  array<string, mixed>  $v  validated GoogleOnboardingConfirmRequest data
     *
     * @throws \InvalidArgumentException when a canonical step rejects the values
     */
    public function confirm(CustomerOnboarding $onboarding, Customer $customer, array $v): CustomerOnboarding
    {
        $onboarding = $this->onboarding->saveBusinessStep($onboarding, $customer, [
            'name' => $v['name'],
            'industry' => $v['industry'],
            'industry_other' => $v['industry_other'] ?? null,
            'description' => $v['description'] ?? null,
            'phone' => $v['phone'] ?? null,
            'country_code' => $v['country_code'],
            'timezone' => $v['timezone'],
            'currency_code' => $v['currency_code'],
        ]);

        $location = [
            'service_mode' => $v['service_mode'],
            'country_code' => $v['country_code'],
            'public_address' => (bool) ($v['public_address'] ?? false),
        ];

        if ($v['service_mode'] !== 'online') {
            foreach (['city', 'region', 'postal_code', 'address_line_1'] as $field) {
                if (filled($v[$field] ?? null)) {
                    $location[$field] = $v[$field];
                }
            }
        }

        $onboarding = $this->onboarding->saveLocationStep($onboarding, $customer, $location);

        $onboarding = $this->onboarding->saveServicesStep($onboarding, $customer, [
            ['name' => $v['main_service'], 'is_primary' => true],
        ]);

        if (filled($v['website_choice'] ?? null)) {
            $onboarding = $this->onboarding->saveWebsiteStep($onboarding, $customer, $v['website_choice'], $v['website_url'] ?? null);
        }

        return $onboarding;
    }

    /**
     * Remember WHICH profile the customer chose, until it is actually linked. Resource names only — identifiers
     * the binding itself stores — set solely from a verified sealed selection, never from the request.
     */
    public function rememberSelection(CustomerOnboarding $onboarding, string $account, string $location): CustomerOnboarding
    {
        return $this->onboardingRepository->recordGoogleBridge($onboarding, ['pending_binding' => ['a' => $account, 'l' => $location]]);
    }

    /**
     * Bind the customer's selected Google location to the Business's canonical primary BusinessLocation, through the
     * existing GoogleBusinessProfileBindingManager (read-only provider reads, budget, ledger, claim constraints).
     *
     * Runs only after the local records exist, is IDEMPOTENT (an existing identical binding is returned, never
     * duplicated, and a lost unique-constraint race is resolved by re-reading), never replaces a binding to a
     * DIFFERENT Google profile, and makes its provider calls outside any database transaction (so it must be called
     * from none). Nothing is ever written to Google.
     *
     * @throws GoogleOnboardingUnavailableException when there is nothing safe to link
     * @throws \App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException
     * @throws GoogleLocationAlreadyClaimedException
     */
    public function link(CustomerOnboarding $onboarding, Customer $customer): BusinessGoogleLocation
    {
        $business = $this->draftBusinessFor($onboarding, $customer) ?? throw GoogleOnboardingUnavailableException::notAvailable();
        $pending = $onboarding->metadata['google']['pending_binding'] ?? null;
        $connection = $this->connectionFor($business);

        if (! is_array($pending) || ! is_string($pending['a'] ?? null) || ! is_string($pending['l'] ?? null)) {
            // Already linked (a repeated retry): succeed without touching anything.
            $linked = BusinessGoogleLocation::query()->where('business_id', $business->id)->first();

            return $linked ?? throw GoogleOnboardingUnavailableException::nothingToLink();
        }

        if ($connection === null || ! $connection->isActive()) {
            throw GoogleOnboardingUnavailableException::reconnectToLink();
        }

        $this->assertEntitled($business, (int) $customer->user_id);

        $locations = BusinessLocation::query()->where('business_id', $business->id)->where('is_primary', true)->get();

        if ($locations->count() !== 1) {
            throw GoogleOnboardingUnavailableException::locationFirst();
        }

        /** @var BusinessLocation $location */
        $location = $locations->first();

        $found = $this->existingBinding($business, $location, $pending['l']);

        if ($found === null) {
            try {
                $found = $this->bindings->bind($business, $connection, $location, $pending['a'], $pending['l'], (int) $customer->user_id);
            } catch (GoogleLocationAlreadyClaimedException $exception) {
                // A concurrent identical request may have won the race: that is success, not a conflict.
                $found = $this->existingBinding($business, $location, $pending['l']) ?? throw $exception;
            }
        }

        $this->onboardingRepository->recordGoogleBridge($onboarding, ['pending_binding' => null, 'linked_at' => now()->toIso8601String()]);

        return $found;
    }

    /**
     * @throws GoogleOnboardingUnavailableException when the local location is already linked to a different profile
     */
    private function existingBinding(Business $business, BusinessLocation $location, string $locationResourceName): ?BusinessGoogleLocation
    {
        $binding = BusinessGoogleLocation::query()
            ->where('business_id', $business->id)
            ->where('business_location_id', $location->id)
            ->first();

        if ($binding === null) {
            return null;
        }

        if ($binding->provider_location_resource_name !== $locationResourceName) {
            throw GoogleOnboardingUnavailableException::linkedElsewhere();
        }

        return $binding;
    }

    /**
     * What the onboarding screens may say about the link: "linked" ONLY when a binding row really exists.
     *
     * @return array{linked: bool, canRetry: bool}|null null when the customer never chose a Google profile
     */
    public function linkState(CustomerOnboarding $onboarding): ?array
    {
        if ($onboarding->business_id === null) {
            return null;
        }

        if (BusinessGoogleLocation::query()->where('business_id', $onboarding->business_id)->exists()) {
            return ['linked' => true, 'canRetry' => false];
        }

        if (! is_array($onboarding->metadata['google']['pending_binding'] ?? null) || $onboarding->status === OnboardingStatus::Completed) {
            return null;
        }

        return [
            'linked' => false,
            'canRetry' => BusinessLocation::query()->where('business_id', $onboarding->business_id)->where('is_primary', true)->exists(),
        ];
    }

    /** Destroy the stored authorization so another Google account can be used. */
    public function disconnect(Business $business, int $actorUserId): void
    {
        $connection = $this->connectionFor($business);

        if ($connection !== null) {
            $this->connections->disconnect($connection, $actorUserId);
        }
    }

    /**
     * Only the fields a customer could usefully approve, already normalised by
     * the provider DTO (street, postal code and the like never reach it).
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(GoogleLocationProfile $profile): array
    {
        return [
            'title' => $profile->title,
            'phone' => $profile->phonePrimary,
            'website' => $profile->websiteUri,
            'category' => $profile->primaryCategoryName,
            'additional_categories' => array_values($profile->additionalCategoryNames),
            'service_area_type' => $profile->serviceAreaType,
            'locality' => $profile->locality,
            'country' => $profile->regionCode,
            // Only a positive confirmation from Google counts as verified.
            'verified' => $profile->hasVoiceOfMerchant === true,
            'duplicate' => $profile->duplicateOfResourceName !== null,
            'open_status' => $profile->openStatus,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{a: string, l: string} $selection
     */
    private function seal(array $snapshot, array $selection, Business $business, BusinessGoogleConnection $connection, int $actorUserId): string
    {
        return Crypt::encryptString((string) json_encode([
            'b' => (int) $business->id,
            'c' => (int) $connection->id,
            'u' => $actorUserId,
            'e' => now()->addSeconds(self::SNAPSHOT_TTL_SECONDS)->getTimestamp(),
            'd' => $snapshot,
            'g' => $selection,
        ]));
    }
}
