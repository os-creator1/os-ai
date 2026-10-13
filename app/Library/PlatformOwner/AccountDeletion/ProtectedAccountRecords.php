<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use Illuminate\Database\Query\Builder;

/**
 * The rows that must never be hard-deleted along with a customer account.
 *
 * WHY A LIST AND NOT A HEURISTIC. Financial, provider and audit history is
 * defined by the business and by the law, not by what a foreign key happens to
 * permit. The foreign keys are NOT weakened to make a deletion pass (and none
 * of the cascades below is relied on): if any account-owned row sits in one of
 * these tables, the account cannot be erased, and the lifecycle ends in
 * CLOSED — records retained — instead.
 *
 * Entries are `table => [label, predicate|null]`. A null predicate protects
 * every row; a predicate narrows it (a never-confirmed checkout attempt with no
 * provider identity is not a provider commitment, for example).
 *
 * A table that is absent from the account's reachable graph is ignored, and
 * AccountDeletionSchemaTest pins that every name here still exists, so a
 * rename cannot silently turn protection into a no-op.
 */
final class ProtectedAccountRecords
{
    /**
     * @return array<string, array{0: string, 1: (callable(Builder): void)|null}>
     */
    public static function definitions(): array
    {
        $providerLinked = fn (Builder $q) => $q->where(function (Builder $w) {
            $w->whereNotNull('provider_subscription_id')->orWhereNotNull('provider_customer_id');
        });

        return [
            // Lane A — the Workspace owner's subscription to the platform.
            'platform_subscriptions' => ['Platform subscription with provider history', $providerLinked],
            'platform_subscription_events' => ['Platform subscription events', null],
            'platform_trial_claims' => ['Trial claims (retained to prevent repeat trials)', null],
            'platform_private_offers' => ['Private offers', null],
            'platform_private_offer_redemptions' => ['Private offer redemptions', null],

            // Lane C — a client's subscription to an Agency, on the Agency's Stripe account.
            'agency_client_subscriptions' => ['Agency client subscription with provider history', $providerLinked],
            'agency_client_subscription_events' => ['Agency client subscription events', null],
            'agency_stripe_connections' => ['Agency Stripe connected account', null],

            // Lane B — a Business's own connected Stripe account and its payments.
            'business_stripe_connections' => ['Business Stripe connected account', null],
            'business_payment_events' => ['Business payment events', null],
            'business_document_payments' => ['Document payments', null],
            'business_document_refunds' => ['Document refunds', null],

            // Lane D — usage wallets, ledgers, funding and receipts.
            'payment_provider_customers' => ['Payment provider customer links', null],
            'business_payment_instruments' => ['Stored payment instruments', null],
            'business_usage_ledger_entries' => ['Usage ledger entries', null],
            'business_usage_reservations' => ['Usage reservations', null],
            'business_usage_measurements' => ['Billable usage measurements', null],
            'business_funding_attempts' => ['Wallet funding attempts', null],
            'business_usage_addon_purchases' => ['Add-on purchases', null],
            'business_billing_receipts' => ['Billing receipts', null],
            'additional_business_slot_agreements' => ['Additional-Business slot agreements', null],
            'additional_business_slot_renewal_charges' => ['Slot renewal charges', null],
            'seo_rank_provider_ledger' => ['Provider cost ledger', null],
            'business_usage_wallets' => ['Usage wallet holding a balance or debt', fn (Builder $q) => $q->where(function (Builder $w) {
                $w->where('available_balance_micro', '!=', 0)
                    ->orWhere('refundable_paid_available_micro', '!=', 0)
                    ->orWhere('reserved_balance_micro', '!=', 0)
                    ->orWhere('debt_balance_micro', '!=', 0);
            })],

            // Carrier resources and their history.
            'business_messaging_numbers' => ['Messaging number not yet released to the carrier', fn (Builder $q) => $q->whereNull('released_at')],
            'business_messaging_number_port_out_requests' => ['Number port-out requests', null],
            'business_messaging_number_lifecycle_events' => ['Number lifecycle events', null],

            // Legacy SMS-era billing.
            'invoices' => ['Legacy invoices', null],
            'subscriptions' => ['Legacy subscriptions', null],
            'subscription_transactions' => ['Legacy subscription transactions', null],

            // Audit.
            'platform_admin_actions' => ['Platform Owner audit trail', null],
        ];
    }

    /**
     * Records that name a Workspace or Business by a plain column with NO
     * foreign key (so the database neither blocks nor cascades them), and that
     * must be retained. If any exist for the account, it is closed, not erased.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> table => [label, column => root table]
     */
    public static function retainedByReference(): array
    {
        return [
            'payment_provider_events' => ['Payment provider events', ['business_id' => 'businesses']],
            'ai_usage_ledger' => ['AI usage ledger', ['workspace_id' => 'workspaces', 'business_id' => 'businesses']],
            'ai_usage_periods' => ['AI usage periods', ['workspace_id' => 'workspaces']],
            'seo_rank_provider_ledger' => ['Provider cost ledger', ['workspace_id' => 'workspaces']],
            'platform_automation_runs' => ['Platform automation runs', ['workspace_id' => 'workspaces', 'business_id' => 'businesses']],
            'platform_account_notes' => ['Platform Owner account notes', ['workspace_id' => 'workspaces', 'business_id' => 'businesses']],
        ];
    }

    /**
     * Operational rows that name a Business by a plain column with no foreign
     * key. They belong to the account and go with it.
     *
     * @return array<string, array<string, string>> table => [column => root table]
     */
    public static function ownedByReference(): array
    {
        return [
            'business_google_operations' => ['business_id' => 'businesses'],
            'business_meta_operations' => ['business_id' => 'businesses'],
            'seo_audit_runs' => ['business_id' => 'businesses'],
        ];
    }
}
