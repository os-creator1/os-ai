{{--
    "What will this cost" shown BEFORE a paid action. Figures come from the
    same meters/rates the wallet reservation applies (MessagingChargePreview);
    nothing is invented. One-time and monthly charges are listed separately.
    Inputs: $preview, $oneTimeLabel, $recurringLabel, $unconfiguredText.
--}}
<div class="sr-callout {{ $preview['configured'] ? '' : 'sr-callout--warn' }}" data-role="charge-summary" data-configured="{{ $preview['configured'] ? '1' : '0' }}" data-sufficient="{{ $preview['sufficient'] ? '1' : '0' }}">
    <x-ds-icon name="credit-card" size="16" />
    <div>
        @if(! $preview['configured'])
            <p class="mb-0">{{ $unconfiguredText }}</p>
        @else
            @if(array_key_exists('brand_already_paid', $preview))
                @if($preview['brand_already_paid'])
                    <p class="mb-1" data-role="fee-already-paid"><strong>Already paid:</strong> carrier brand fee. It was charged when your business was submitted for verification and is not due again.</p>
                @else
                    <p class="mb-1" data-role="fee-due-now"><strong>Due now, when you submit your business for verification:</strong> carrier brand fee — {{ $preview['brand_fee']['text'] }}</p>
                @endif
                <p class="mb-1" data-role="fee-due-at-campaign"><strong>Due when you authorize your campaign, after your business is verified:</strong> {{ $oneTimeLabel }} — {{ $preview['campaign_prepay']['text'] }}</p>
            @else
                <p class="mb-1"><strong>One-time:</strong> {{ $oneTimeLabel }} — {{ $preview['one_time']['text'] }}@if(isset($preview['one_time']['detail'])) <span class="text-caption text-muted">({{ $preview['one_time']['detail'] }})</span>@endif</p>
            @endif
            @if(isset($preview['possible_review_fee']) && $preview['possible_review_fee']['micro'] > 0)
                <p class="mb-1" data-role="possible-review-fee"><strong>Carrier review fee:</strong> {{ $preview['possible_review_fee']['text'] }} per carrier review, if sent for downstream review; additional review fees may apply on resubmission. It is not part of the amount charged when you submit; any review fee is billed separately, at the carrier's cost, and never without telling you.</p>
            @endif
            <p class="mb-1"><strong>Then monthly:</strong> {{ $recurringLabel }} — {{ $preview['recurring']['text'] ?? 'not priced yet; we will tell you before anything recurring is charged' }}</p>
            <p class="mb-1">Text messages you send are billed separately, per message. Your balance: {{ $preview['balance_text'] }}.</p>
            @unless($preview['sufficient'])
                <p class="mb-0" data-role="add-funds">
                    Your balance doesn't cover the next charge yet.
                    <a href="{{ route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]) }}">Add funds</a> to continue.
                </p>
            @endunless
        @endif
    </div>
</div>
