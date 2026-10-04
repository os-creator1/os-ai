<?php

namespace App\Library\Automation\Workflow;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\BusinessEmail\BusinessEmailSenderResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Payments\StripeConnectManager;
use App\Models\Business;
use App\Models\CustomerBasedSendingServer;
use App\Models\Workspace;
use Throwable;

/**
 * What a Business can actually USE from Automations today — so the builder offers
 * only what will run, publish refuses what the account cannot execute, and a step
 * run late fails closed with a plain reason instead of reaching for a product the
 * account has lost.
 *
 * It is a READER over the canonical authorities, never a second one:
 *
 *   crm, calendar, forms, documents, catalog
 *        EntitlementManager's own decision for the matching PlatformFeature
 *        (Crm, Calendar, Forms, PaymentsContracts, PackagesProducts). No plan
 *        name is hard-coded anywhere: a plan that gains or loses a feature changes
 *        the answer here with no edit.
 *   payments  the Payments & contracts entitlement AND a connected Stripe account
 *        that can take a charge (StripeConnectManager::isChargeReady) — an invoice
 *        nobody can pay is never requested.
 *   sms   a usable sending path: an active managed messaging identity, or the
 *        Business's active BYO channel (the sender AutomationSmsDispatcher resolves).
 *   email a connected, active Business Email account (BusinessEmailSenderResolver).
 *
 * Each answer carries a customer-readable reason when it is a no. One entitlement
 * snapshot (six constant reads, which the repository layer caches per request) serves
 * every feature asked about, so the builder pays for it once. Nothing is remembered
 * between calls: a worker that outlives a plan change must see the change.
 */
class WorkflowCapabilities
{
    public const SMS = 'sms';

    public const EMAIL = 'email';

    public const CRM = 'crm';

    public const CALENDAR = 'calendar';

    public const FORMS = 'forms';

    public const DOCUMENTS = 'documents';

    public const CATALOG = 'catalog';

    public const PAYMENTS = 'payments';

    /** The PlatformFeature behind each entitlement-backed capability. */
    private const FEATURES = [
        self::CRM => PlatformFeature::Crm,
        self::CALENDAR => PlatformFeature::Calendar,
        self::FORMS => PlatformFeature::Forms,
        self::DOCUMENTS => PlatformFeature::PaymentsContracts,
        self::CATALOG => PlatformFeature::PackagesProducts,
    ];

    private const REASONS = [
        self::SMS => 'Text messages need a phone number. Set one up in Settings > Text messaging.',
        self::EMAIL => 'Email needs a connected mailbox. Connect one in Settings > Email.',
        self::CRM => 'Your plan does not include the sales pipeline.',
        self::CALENDAR => 'Your plan does not include the calendar.',
        self::FORMS => 'Your plan does not include forms.',
        self::DOCUMENTS => 'Your plan does not include proposals and payments.',
        self::CATALOG => 'Your plan does not include products and packages.',
        self::PAYMENTS => 'Requesting payment needs a connected Stripe account that can take payments. Connect one in Settings > Payments.',
    ];


    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly BusinessMessagingIdentityResolver $messaging,
        private readonly BusinessEmailSenderResolver $emailSenders,
        private readonly StripeConnectManager $stripe,
    ) {
    }

    /**
     * @return array<string, array{available: bool, reason: string|null}> keyed by capability
     */
    public function forBusiness(Business $business): array
    {
        // The entitlement snapshot and the account's readiness are the PLATFORM'S
        // authority — the same one tenancy resolution reads — not workflow-feature SQL,
        // so they are filed as shared (see WorkflowFeatureQueryScope).
        return \App\Http\Controllers\Customer\Business\Concerns\WorkflowFeatureQueryScope::shared(
            fn (): array => $this->resolve($business),
        );
    }

    /** @return array<string, array{available: bool, reason: string|null}> */
    private function resolve(Business $business): array
    {
        $allowed = $this->entitled($business);

        $answers = [];

        foreach (self::FEATURES as $capability => $feature) {
            $answers[$capability] = $this->answer($capability, $allowed[$feature->value] ?? false);
        }

        $answers[self::PAYMENTS] = $this->answer(
            self::PAYMENTS,
            $answers[self::DOCUMENTS]['available'] && $this->stripeReady($business),
        );
        $answers[self::SMS] = $this->answer(self::SMS, $this->hasTextingPath($business));
        $answers[self::EMAIL] = $this->answer(self::EMAIL, $this->emailSenders->defaultFor($business) !== null);

        return $answers;
    }

    public function isAvailable(Business $business, string $capability): bool
    {
        return $this->forBusiness($business)[$capability]['available'] ?? false;
    }

    /** The plain reason a capability is unavailable, or null when it is available. */
    public function reason(Business $business, string $capability): ?string
    {
        $answer = $this->forBusiness($business)[$capability] ?? null;

        return $answer === null || $answer['available'] ? null : $answer['reason'];
    }


    /** @return array{available: bool, reason: string|null} */
    private function answer(string $capability, bool $available): array
    {
        return ['available' => $available, 'reason' => $available ? null : self::REASONS[$capability]];
    }

    /** @return array<string, bool> PlatformFeature value => entitled */
    private function entitled(Business $business): array
    {
        $workspace = Workspace::query()->find((int) $business->workspace_id);

        if ($workspace === null) {
            return [];
        }

        $keys = array_map(fn (PlatformFeature $feature): string => $feature->value, array_values(self::FEATURES));

        try {
            $decisions = $this->entitlements->snapshotBusinessFeatureDecisions($workspace, $business, $keys, 0);
        } catch (Throwable) {
            // A Business and Workspace that do not agree are nobody's entitlement.
            return [];
        }

        return array_map(fn ($decision): bool => $decision->allowed, $decisions);
    }

    private function stripeReady(Business $business): bool
    {
        try {
            return $this->stripe->isChargeReady($business);
        } catch (Throwable) {
            return false;
        }
    }

    /** A managed identity, or an active BYO channel — the two paths the SMS dispatcher can resolve. */
    private function hasTextingPath(Business $business): bool
    {
        if ($this->messaging->resolveForBusiness($business) !== null) {
            return true;
        }

        return CustomerBasedSendingServer::query()
            ->where('business_id', (int) $business->id)
            ->where('status', 1)
            ->with('sendingServer')
            ->get()
            ->contains(fn ($row): bool => $row->sendingServer !== null && (bool) $row->sendingServer->status);
    }
}
