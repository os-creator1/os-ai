{{--
    Implementation Contract 17 §6.3 — the end customer's view of the FROZEN
    ISSUED version.

    It renders the version the guard resolved, never the open draft: a
    customer must never be shown an in-progress revision of the thing they
    are being asked to sign.

    Every value is escaped Blade output. Raw output is forbidden here: this
    page renders customer-authored document content on an unauthenticated
    surface.

    NO LEGAL CLAIM (§6.5). The signing copy below describes a typed signature
    and nothing more — never "qualified", "advanced", "identity-verified" or
    "legally binding".

    NO PAYMENT SURFACE. §6.3.2's Pay action is Sub-slice E; this page makes no
    provider call and collects no card data.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $document->title }}</title>
    <style>
        html{color-scheme:light}
        body{background:#fff;font-family:system-ui,sans-serif;max-width:46rem;margin:3rem auto;padding:0 1rem;color:#17212b;line-height:1.5}
        table{width:100%;border-collapse:collapse;margin:1.5rem 0}
        th,td{text-align:left;padding:.55rem .4rem;border-bottom:1px solid #d8dee5}
        td.num,th.num{text-align:right}
        .total{font-weight:600}
        fieldset{border:1px solid #d8dee5;border-radius:.4rem;padding:1rem 1.2rem;margin-top:2rem}
        label{display:block;margin:.9rem 0 .25rem}
        input{font:inherit;padding:.6rem;border:1px solid #9ba7b4;border-radius:.4rem;width:100%;max-width:26rem}
        button{font:inherit;background:#125a9c;color:#fff;border:0;border-radius:.4rem;padding:.7rem 1.1rem;cursor:pointer;margin-top:1.2rem}
        .consent{background:#f4f6f8;border-radius:.4rem;padding:.8rem 1rem;margin-top:1rem}
        .error{color:#a51d24}
        .muted{color:#5a6673;font-size:.92rem}
    </style>
</head>
<body>
<main>
    {{-- Party names come from the FROZEN issued version, never the live
         Business row: a later rename must not rewrite what was sent or signed. --}}
    <p class="muted" data-role="issuer">{{ $parties['business_name'] }}@if($parties['business_location_name']) &middot; {{ $parties['business_location_name'] }}@endif</p>
    <h1>{{ $document->title }}</h1>

    @if($document->status === \App\Enums\Documents\DocumentStatus::Signed)
        <p data-role="already-signed">This document was signed on {{ $document->signed_at?->format('j F Y') }}.</p>
    @endif

    {{-- Paid confirmation. Rendered ONLY from persisted state, which only the
         verified provider webhook (or a verified server-side retrieval)
         writes — coming back from the card form proves nothing here. --}}
    @if($document->status === \App\Enums\Documents\DocumentStatus::Paid)
        <p data-role="paid-confirmation"><strong>Payment received — thank you.</strong> This was paid in full on {{ $document->paid_at?->format('j F Y') }}.</p>
    @elseif($paymentProcessing)
        <p data-role="payment-processing">Your payment is being confirmed. This page shows it as paid as soon as your bank confirms it — you do not need to pay again.</p>
    @endif

{{-- Contract 17B: a block document (content.blocks) renders through the one
    DocumentBlockRenderer; a legacy version (content.body) takes the original
    path below, unchanged. --}}@if($blocksHtml === null)
    {{-- The frozen body and terms the signer is agreeing to. Escaped, with line
         breaks preserved by CSS; never raw HTML. --}}
    @if($body !== null && trim($body) !== '')
        <div data-role="terms" style="white-space:pre-wrap">{{ $body }}</div>
    @endif

    <table data-role="lines">
        <thead>
        <tr><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
        @foreach($lines as $line)
            <tr data-role="line">
                <td>
                    {{ $line->name }}
                    @if($line->description)<span class="muted d-block">{{ $line->description }}</span>@endif
                </td>
                <td class="num">{{ $line->quantity }}</td>
                <td class="num">{{ number_format($line->unit_price_minor / 100, 2) }}</td>
                <td class="num">{{ number_format($line->line_total_minor / 100, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="total"><td colspan="3">Total</td><td class="num" data-role="total">{{ number_format($version->total_minor / 100, 2) }} {{ $version->currency_code }}</td></tr>
        </tfoot>
    </table>

    @if($schedule->isNotEmpty())
        <h2>Payment schedule</h2>
        <table data-role="schedule">
            <tbody>
            @foreach($schedule as $item)
                <tr data-role="schedule-item" data-kind="{{ $item->kind instanceof \BackedEnum ? $item->kind->value : $item->kind }}">
                    <td>{{ ucfirst($item->kind instanceof \BackedEnum ? $item->kind->value : $item->kind) }}</td>
                    <td>@if($item->due_at)Due {{ $item->due_at->format('j F Y') }}@endif</td>
                    <td class="num">{{ number_format($item->amount_minor / 100, 2) }} {{ $item->currency_code }}</td>
                    <td data-role="schedule-status">
                        @if($item->status === \App\Enums\Documents\PaymentScheduleItemStatus::Paid)
                            Paid
                        @elseif($item->status === \App\Enums\Documents\PaymentScheduleItemStatus::Refunded)
                            Refunded
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{-- §6.3.2 — payment STATE, rendered from persisted data only. This
             block creates no payment row, no PaymentIntent and no provider
             call; the Pay action below is a separate POST. --}}
                    @include('public.documents._payment')
    @endif

    @if($signable)
        @include('public.documents._sign_form')
    @endif
@else
    <style>body{max-width:860px;background:#eceae5}@media (max-width:640px){body{margin:1rem auto;padding:0 .5rem}}</style>
    {{ $blocksHtml }}

    @if($schedule->isNotEmpty())
        {{-- Contract 17B §7 — the sign-then-pay landing anchor. --}}
        <div id="pay" data-role="payment-section">
            @include('public.documents._payment')
        </div>
    @endif

    {{-- The sign form is injected at the signature block; if the version has
         none (it cannot be sent that way, but fail safe) it follows the content. --}}
    @if($signable && ! $signatureInBlocks)
        @include('public.documents._sign_form')
    @endif
@endif
</main>
</body>
</html>
