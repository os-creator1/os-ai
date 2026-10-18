<?php

namespace App\Library\Documents\Editor;

use App\Enums\Documents\DocumentStatus;
use App\Enums\Documents\DocumentVersionState;
use App\Library\Catalog\CatalogMoney;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\DocumentPaymentPlanCompiler;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentVersion;
use App\Models\Contacts;
use App\Library\Documents\Delivery\DocumentLinkSmsSender;
use Illuminate\Support\Carbon;

/**
 * Implementation Contract 17B — the read model the visual editor (and its JSON
 * API) works from. It reads the canonical tables and never owns financial
 * truth: lines, totals and the schedule come from the version's own rows, the
 * plan from `content.payment_plan`.
 *
 * Money is returned as BOTH `*_minor` (for the JS to do arithmetic on) and
 * `*_formatted` (for display); the API never asks a person for minor units.
 */
final class DocumentEditorState
{
    public function __construct(private readonly DocumentPaymentPlanCompiler $plans)
    {
    }

    /**
     * The version the editor shows: the open draft when there is one, else the
     * current issued version (read-only).
     */
    public function version(BusinessDocument $document): ?BusinessDocumentVersion
    {
        return BusinessDocumentVersion::query()
            ->where('business_document_id', $document->id)
            ->where('state', DocumentVersionState::Draft->value)
            ->first()
            ?? ($document->current_version_id === null ? null : BusinessDocumentVersion::query()->whereKey($document->current_version_id)->first());
    }

    /**
     * Lines, totals, schedule and plan of the document's draft — the payload
     * every line / plan endpoint answers with.
     *
     * @return array<string, mixed>
     */
    public function commerce(BusinessDocument $document, ?int $lockVersion = null): array
    {
        $version = $this->version($document);
        $currency = (string) $document->currency_code;
        $timezone = $this->timezone($document);
        $total = (int) ($version?->total_minor ?? 0);
        $content = is_array($version?->content) ? $version->content : [];

        $lines = $version === null ? [] : $version->lineItems()->orderBy('position')->orderBy('id')->get()->map(fn ($line) => [
            'uid' => (string) $line->uid,
            'source' => $line->source instanceof \BackedEnum ? $line->source->value : (string) $line->source,
            'name' => (string) $line->name,
            'description' => $line->description,
            'quantity' => (int) $line->quantity,
            'unit_price_minor' => (int) $line->unit_price_minor,
            'unit_price_formatted' => CatalogMoney::format((int) $line->unit_price_minor, $currency),
            'line_total_minor' => (int) $line->line_total_minor,
            'line_total_formatted' => CatalogMoney::format((int) $line->line_total_minor, $currency),
        ])->all();

        $schedule = $version === null ? [] : $version->paymentScheduleItems()->orderBy('sequence')->get()->map(function ($item) use ($currency, $timezone) {
            $due = $item->due_at === null ? null : Carbon::instance($item->due_at)->setTimezone($timezone);
            $kind = $item->kind instanceof \BackedEnum ? $item->kind->value : (string) $item->kind;

            return [
                'kind' => $kind,
                'amount_minor' => (int) $item->amount_minor,
                'amount_formatted' => CatalogMoney::format((int) $item->amount_minor, $currency),
                'due_at' => $due?->toIso8601String(),
                'due_date' => $due?->format('Y-m-d'),
                'due_label' => $due !== null ? 'Due ' . $due->format('j F Y') : ($kind === 'balance' ? 'Due after the deposit is paid' : 'Due after signing'),
            ];
        })->all();

        $plan = is_array($content['payment_plan'] ?? null) ? $content['payment_plan'] : null;
        $problem = $plan === null ? null : $this->plans->problem($plan, $total);

        return [
            'lock_version' => $lockVersion ?? ($version === null ? null : (int) $version->lock_version),
            'currency_code' => $currency,
            'lines' => $lines,
            'totals' => [
                'subtotal_minor' => (int) ($version?->subtotal_minor ?? 0),
                'subtotal_formatted' => CatalogMoney::format((int) ($version?->subtotal_minor ?? 0), $currency),
                'total_minor' => $total,
                'total_formatted' => CatalogMoney::format($total, $currency),
            ],
            'schedule' => $schedule,
            'plan' => $plan === null ? null : $this->presentPlan($plan, $total, $currency),
            'plan_invalid' => $problem !== null,
            'plan_error' => $problem,
        ];
    }

    /**
     * Everything the editor needs to open a document.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(BusinessDocument $document): array
    {
        $version = $this->version($document);
        $content = is_array($version?->content) ? $version->content : [];
        $isBlocks = BlockSchema::hasBlocks($content);
        $hasBody = isset($content['body']) && is_string($content['body']) && trim($content['body']) !== '';
        $editable = $version !== null && $version->state === DocumentVersionState::Draft
            && in_array($document->status, [DocumentStatus::Draft, DocumentStatus::Sent], true);

        return [
            'document' => [
                'uid' => (string) $document->uid,
                'title' => (string) $document->title,
                'kind' => $document->kind->value,
                'status' => $document->status->value,
                'requires_signature' => (bool) $document->requires_signature,
                'currency_code' => (string) $document->currency_code,
                'recipient' => [
                    'name' => $document->recipient_name_snapshot,
                    'email' => $document->recipient_email_snapshot,
                    'phone' => $document->recipient_phone_snapshot,
                ],
            ],
            // Contract 17B §7 — what the send dialog needs to offer its channels.
            'delivery' => $this->delivery($document),
            'editable' => $editable,
            'is_block_document' => $isBlocks,
            'is_legacy' => ! $isBlocks && $hasBody,
            'can_upgrade' => $editable && ! $isBlocks && $document->status === DocumentStatus::Draft,
            'blocks' => $isBlocks ? $content['blocks'] : [],
        ] + $this->commerce($document);
    }

    /**
     * Recipient prefill and channel availability for the send dialog. Additive:
     * the sender can still type an email / phone into an empty snapshot.
     *
     * @return array<string, mixed>
     */
    private function delivery(BusinessDocument $document): array
    {
        $contact = $document->contact_id === null ? null : Contacts::query()
            ->whereKey($document->contact_id)->where('business_id', $document->business_id)->first();
        $reason = app(DocumentLinkSmsSender::class)->preflight($document);

        return [
            'email' => $document->recipient_email_snapshot,
            'phone' => $document->recipient_phone_snapshot,
            'contact_subscribed' => $contact !== null && $contact->status === Contacts::STATUS_SUBSCRIBE,
            // Null = a text could be sent right now; otherwise the reason code.
            'sms_unavailable_reason' => $reason,
            'sms_default_message' => app(DocumentLinkSmsSender::class)->defaultPrefix($document),
            'sms_max_message' => DocumentLinkSmsSender::MAX_CUSTOM_MESSAGE,
            'link_delivered_at' => $document->link_delivered_at?->toIso8601String(),
            'link_delivery_failed_at' => $document->link_delivery_failed_at?->toIso8601String(),
            'sms_link_delivered_at' => $document->sms_link_delivered_at?->toIso8601String(),
            'sms_link_delivery_failed_at' => $document->sms_link_delivery_failed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function presentPlan(array $plan, int $total, string $currency): array
    {
        $out = $plan;

        if (isset($plan['deposit_minor']) && is_int($plan['deposit_minor'])) {
            $deposit = $plan['deposit_minor'];
            $out['deposit_input'] = CatalogMoney::toInput($deposit, $currency);
            $out['deposit_formatted'] = CatalogMoney::format($deposit, $currency);
            $out['balance_minor'] = max(0, $total - $deposit);
            $out['balance_formatted'] = CatalogMoney::format(max(0, $total - $deposit), $currency);
        }

        return $out;
    }

    private function timezone(BusinessDocument $document): string
    {
        $business = Business::query()->find($document->business_id);
        $zone = is_string($business?->timezone) && $business->timezone !== '' ? $business->timezone : (string) config('app.timezone', 'UTC');

        try {
            new \DateTimeZone($zone);

            return $zone;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }
}
