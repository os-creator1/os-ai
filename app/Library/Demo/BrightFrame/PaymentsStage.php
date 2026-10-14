<?php

namespace App\Library\Demo\BrightFrame;

use App\Library\Documents\DocumentManager;
use App\Models\BusinessDocument;
use App\Models\CatalogItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payments & Contracts: proposals and invoices built through the real
 * DocumentManager (lines, payment schedule, send, sign), with paid history.
 *
 * SAFE BY CONSTRUCTION. The Stripe connection is a clearly simulated row
 * marked "restricted" with charges disabled, so no payment can be started and
 * no Stripe call is made. Payments shown as received are inserted history.
 * Documents are "sent" only while the command has faked mail and queues; the
 * reminder and balance-request sweeps are then switched off per document so
 * they can never message these made-up contacts later.
 */
final class PaymentsStage implements DemoStage
{
    /**
     * [contact index, kind, title, package, final state, deposit pct, deal title]
     * final state: draft | sent | signed_deposit_paid | paid
     */
    private const DOCUMENTS = [
        [0, 'proposal', 'Thompson Wedding: Signature Package Proposal', 'Signature', 'sent', 30, 'Thompson Wedding: Signature Package'],
        [5, 'proposal', 'Piedmont Health Gala: Luxe Package Proposal', 'Luxe', 'sent', 50, 'Piedmont Health Gala: Branded Activation'],
        [8, 'proposal', 'Whitfield Wedding: Signature Package Proposal', 'Signature', 'draft', 30, 'Whitfield Wedding: Signature Package'],
        [12, 'proposal', 'Rahman Wedding: Signature Package Proposal', 'Signature', 'signed_deposit_paid', 30, 'Rahman Wedding: Signature Package'],
        [15, 'invoice', 'Matthews Wedding: Essential Package Invoice', 'Essential', 'paid', 100, 'Matthews Wedding: Essential Package'],
        [13, 'invoice', 'Southline Logistics Holiday Party Invoice', 'Signature', 'paid', 100, 'Southline Logistics Holiday Party: Glam Mirror Booth'],
        [14, 'invoice', 'Liu Sweet Sixteen: Signature Package Invoice', 'Signature', 'paid', 100, 'Liu Sweet Sixteen: Classic Booth'],
    ];

    public function __construct(private readonly DocumentManager $documents)
    {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $contacts = array_values($context->refs['contacts']);
        $connectionId = $this->connection($context);

        foreach (self::DOCUMENTS as $n => [$index, $kind, $title, $package, $state, $depositPct, $dealTitle]) {
            if (BusinessDocument::query()->where('business_id', $business->id)->where('title', $title)->exists()) {
                continue;
            }

            $contact = $contacts[$index];
            $deal = $context->refs['opportunities'][$dealTitle] ?? null;
            $document = $this->documents->create($business, $context->location, $contact, $deal, $kind, $title, $context->user);
            $item = CatalogItem::query()->where('business_id', $business->id)->where('name', $package)->firstOrFail();

            $this->documents->addCatalogLine($document, $item, 1, $context->user);
            $document = $document->fresh();
            $total = (int) $item->price_minor;

            $terms = $depositPct === 100
                ? [['kind' => 'full', 'amount_minor' => $total, 'currency_code' => 'USD']]
                : [
                    ['kind' => 'deposit', 'amount_minor' => (int) round($total * $depositPct / 100), 'currency_code' => 'USD'],
                    ['kind' => 'balance', 'amount_minor' => $total - (int) round($total * $depositPct / 100), 'currency_code' => 'USD'],
                ];
            $this->documents->setSchedule($document, $terms);
            $person = array_values($context->refs['people'])[$index];
            $this->documents->edit($document->fresh(), [
                'recipient_name_snapshot' => $person['first'] . ' ' . $person['last'],
                'recipient_email_snapshot' => $person['email'],
                'recipient_phone_snapshot' => '+' . $person['phone'],
            ]);

            if ($state === 'draft') {
                continue;
            }

            $sent = $this->documents->send($document->fresh(), ['email']);
            $when = $context->daysFromNow(-(4 + $n * 3), 10);
            $document = $sent->fresh();

            if (in_array($state, ['signed_deposit_paid', 'paid'], true)) {
                if ($kind === 'proposal') {
                    $version = DB::table('business_document_versions')->where('id', $document->current_version_id)->first();
                    $this->documents->sign($document, [
                        'displayed_version_uid' => $version->uid, 'signer_name' => $contact->uid ? $this->nameOf($context, $index) : 'Client',
                        'signer_email' => $context->refs['people'][array_keys($context->refs['people'])[$index]]['email'],
                        'typed_name' => $this->nameOf($context, $index), 'ip_address' => '203.0.113.10', 'user_agent' => 'Simulated demo signer',
                    ]);
                }

                $items = DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $document->current_version_id)->orderBy('sequence')->get();
                $paidItems = $state === 'paid' ? $items : $items->take(1);

                foreach ($paidItems as $scheduleItem) {
                    DB::table('business_document_payment_schedule_items')->where('id', $scheduleItem->id)->update(['status' => 'paid', 'paid_at' => $when]);
                    DB::table('business_document_payments')->insert([
                        'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'business_document_id' => $document->id,
                        'schedule_item_id' => $scheduleItem->id, 'business_stripe_connection_id' => $connectionId,
                        'local_idempotency_key' => 'demo-brightframe-' . $document->id . '-' . $scheduleItem->id,
                        'provider_payment_intent_id' => 'pi_SIMULATED_DEMO_' . $scheduleItem->id, 'provider_charge_id' => 'ch_SIMULATED_DEMO_' . $scheduleItem->id,
                        'amount_minor' => $scheduleItem->amount_minor, 'currency_code' => 'USD', 'status' => 'succeeded', 'succeeded_at' => $when,
                        'created_at' => $when, 'updated_at' => $when,
                    ]);
                }

                if ($state === 'paid') {
                    DB::table('business_documents')->where('id', $document->id)->update(['status' => 'paid', 'paid_at' => $when, 'signed_at' => $kind === 'proposal' ? $when : null]);
                }
            }

            // Switch the document sweeps off for this document: nothing may ever be re-sent.
            DB::table('business_documents')->where('id', $document->id)->update([
                'sent_at' => $when, 'link_delivered_at' => $when, 'expires_at' => null, 'expiry_reminder_count' => 9, 'expiry_reminder_last_sent_at' => $when,
            ]);
            DB::table('business_document_payment_schedule_items')->where('business_document_version_id', $document->current_version_id)->update([
                'payment_request_claimed_at' => $when, 'payment_request_sent_at' => $when, 'payment_request_attempts' => 1,
                'reminder_count' => 9, 'reminder_last_sent_at' => $when,
            ]);
        }

        $context->note('documents', BusinessDocument::query()->where('business_id', $business->id)->count());
        $context->note('payments_recorded', DB::table('business_document_payments')->where('business_id', $business->id)->count());
    }

    private function nameOf(DemoContext $context, int $index): string
    {
        $person = array_values($context->refs['people'])[$index];

        return $person['first'] . ' ' . $person['last'];
    }

    private function connection(DemoContext $context): int
    {
        $existing = DB::table('business_stripe_connections')->where('business_id', $context->business->id)->value('id');

        return $existing ?? DB::table('business_stripe_connections')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $context->business->id, 'stripe_account_id' => 'acct_SIMULATED_DEMO',
            'status' => 'restricted', 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
            'requirements_disabled_reason' => 'simulated_demo_account', 'default_currency' => 'USD',
            'connected_at' => now()->subDays(30), 'last_synced_at' => now()->subDays(1), 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
