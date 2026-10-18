{{--
    Implementation Contract 17 §6.3 — the end customer's view of the FROZEN
    ISSUED version.

    It renders the version the guard resolved, never the open draft: a
    customer must never be shown an in-progress revision of the thing they
    are being asked to sign.

    Every value is escaped Blade output. Raw output is forbidden here: this
    page renders customer-authored document content on an unauthenticated
    surface. (The only unescaped values are the renderer's own escaped block
    HTML and the controller-built sign form, exactly as before.)

    NO LEGAL CLAIM (§6.5). The signing copy below describes a typed signature
    and nothing more — never "qualified", "advanced", "identity-verified" or
    "legally binding".

    NO PAYMENT LOGIC HERE. Payment state and the Pay action come from the
    unchanged `_payment` partial; this page makes no provider call and
    collects no card data. Presentation only: the summary beside the document
    restates persisted values and offers only actions that really exist
    (Review & sign, Pay now).

    BRANDING. The header, accents and buttons use the ISSUING Business's own
    name, logo and brand colour ($brand — see PublicDocumentController::brand),
    never the platform's.
--}}
@php
    $kind = $document->kind instanceof \BackedEnum ? $document->kind->value : (string) $document->kind;
    $kindLabel = ucfirst($kind !== '' ? $kind : 'document');
    $isPaid = $document->status === \App\Enums\Documents\DocumentStatus::Paid;
    $isSigned = $document->status === \App\Enums\Documents\DocumentStatus::Signed;
    // An attempt the provider has said is `processing` is neither "payment due"
    // nor payable again: the sidebar must agree with the main payment section.
    $payableNow = $payment['payable_item'] !== null && $payment['can_pay'] && ! $paymentProcessing;
    $receivedMinor = (int) $schedule->filter(fn ($row) => $row->status === \App\Enums\Documents\PaymentScheduleItemStatus::Paid)->sum('amount_minor');
    $totalText = $version->currency_code . ' ' . number_format($version->total_minor / 100, 2);

    // One plain sentence about how it is paid, from the persisted schedule only.
    $scheduleCount = $schedule->count();
    $firstKind = $scheduleCount > 0 ? ($schedule->first()->kind instanceof \BackedEnum ? $schedule->first()->kind->value : (string) $schedule->first()->kind) : null;
    $howPaid = match (true) {
        $scheduleCount === 0 => null,
        $scheduleCount === 1 && $firstKind === 'full' => 'Paid in full after signing',
        $scheduleCount === 1 => 'One payment',
        default => 'Paid in ' . $scheduleCount . ' payments',
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $document->title }}</title>
    <style>
        html{color-scheme:light}
        *{box-sizing:border-box}
        :root{
            --pd-accent:{{ $brand['color'] }};
            --pd-ink:{{ $brand['ink'] }};
            --pd-soft:#f1f1f1;
            --pd-soft:color-mix(in srgb,var(--pd-accent) 11%,#fff);
            --pd-line:#e7e4de;
            --pd-text:#17212b;
            --pd-muted:#5b6672;
            /* the shared block renderer reads these; point them at the Business brand */
            --color-primary:var(--pd-accent);
            --color-primary-soft-bg:var(--pd-soft);
            --color-primary-border:var(--pd-line);
            --color-link:var(--pd-accent);
        }
        body{margin:0;background:#f6f5f2;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--pd-text);line-height:1.5}
        .pd-bar{background:var(--pd-accent);color:var(--pd-ink)}
        .pd-bar-in{max-width:72rem;margin:0 auto;padding:.8rem 1rem;display:flex;align-items:center;gap:.75rem}
        .pd-logo{width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(255,255,255,.92);object-fit:contain;flex:none;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;color:var(--pd-accent)}
        .pd-bar-name{font-weight:600;overflow-wrap:anywhere;min-width:0}
        .pd-secure{margin-left:auto;display:inline-flex;align-items:center;gap:.4rem;font-size:.8rem;white-space:nowrap}
        .pd-secure svg{width:.95rem;height:.95rem;flex:none}
        .pd-wrap{max-width:72rem;margin:0 auto;padding:1.5rem 1rem 3rem;display:grid;gap:1.25rem 1.5rem;grid-template-columns:minmax(0,1fr) 17.5rem;grid-template-areas:"head head" "main summary" "main note" "main .";grid-template-rows:auto auto auto 1fr;align-items:start}
        .pd-head{grid-area:head;min-width:0}
        .pd-main{grid-area:main;min-width:0}
        .pd-summary{grid-area:summary;position:sticky;top:1rem}
        .pd-note{grid-area:note;color:var(--pd-muted);font-size:.85rem;margin:0}
        .pd-pill{display:inline-block;background:var(--pd-soft);color:var(--pd-accent);font-size:.75rem;font-weight:600;border-radius:999px;padding:.1rem .6rem}
        h1.pd-title{font-size:1.6rem;line-height:1.25;margin:.35rem 0 0;overflow-wrap:anywhere}
        .pd-card{background:#fff;border:1px solid var(--pd-line);border-radius:1rem;margin:0 0 1rem;overflow:hidden}
        .pd-issuer{display:flex;align-items:center;gap:.75rem;padding:1.25rem 2rem 0}
        .pd-issuer .pd-logo{width:2.75rem;height:2.75rem;background:var(--pd-soft)}
        .pd-issuer p{margin:0;font-weight:600;overflow-wrap:anywhere}
        .pd-issuer .pd-sub{font-weight:400;color:var(--pd-muted);font-size:.875rem}
        @if($blocksHtml !== null)
        .pd-card .doc-blocks{max-width:none;margin:0;padding:1rem 2rem 1.75rem;background:transparent}
        .pd-card .doc-blocks .doc-details{background:transparent;border:0;padding:0;margin-top:-.25rem}
        .pd-card .doc-blocks .doc-details strong{display:none}
        .pd-card .doc-blocks .doc-total td{border-bottom-color:var(--pd-text)}
        .pd-card .doc-blocks [data-role="schedule"] td{background:var(--pd-soft);border-bottom:0;padding:.7rem .6rem}
        .pd-card .doc-blocks [data-role="schedule"] tr td:first-child{border-radius:.6rem 0 0 .6rem}
        .pd-card .doc-blocks [data-role="schedule"] tr td:last-child{border-radius:0 .6rem .6rem 0}
        .pd-card .doc-blocks [data-role="schedule"]{border-collapse:separate;border-spacing:0 .35rem}
        @endif
        .pd-legacy{padding:1.5rem 2rem}
        .pd-legacy table{width:100%;border-collapse:collapse;margin:1.25rem 0}
        .pd-legacy th,.pd-legacy td{text-align:left;padding:.55rem .4rem;border-bottom:1px solid var(--pd-line);vertical-align:top}
        .pd-legacy th{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--pd-muted)}
        .pd-legacy td.num,.pd-legacy th.num{text-align:right}
        .pd-legacy .total{font-weight:600}
        .pd-legacy h2{font-size:1.1rem}
        .pd-payment{padding:1rem 2rem 1.5rem}
        .pd-notice{border-radius:.75rem;padding:.8rem 1rem;margin:0 0 1rem;background:var(--pd-soft);border:1px solid var(--pd-line)}
        .pd-notice p{margin:0}
        .pd-summary-card{background:#fff;border:1px solid var(--pd-line);border-radius:1rem;padding:1.1rem}
        .pd-eyebrow{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--pd-muted)}
        .pd-amount{font-size:1.6rem;font-weight:700;margin:.15rem 0;overflow-wrap:anywhere}
        .pd-facts{margin:1rem 0;padding:1rem 0 0;border-top:1px solid var(--pd-line);display:grid;grid-template-columns:auto 1fr;gap:.45rem 1rem;font-size:.85rem}
        .pd-facts dt{color:var(--pd-muted)}
        .pd-facts dd{margin:0;text-align:right;font-weight:600;overflow-wrap:anywhere}
        .pd-status{display:inline-block;border-radius:999px;padding:.05rem .6rem;font-size:.75rem;font-weight:600;background:#fdf1d0;color:#6b4e00}
        .pd-status.is-ok{background:#e1f3e8;color:#14603a}
        .pd-cta{display:block;width:100%;text-align:center;text-decoration:none;font-weight:600;background:var(--pd-accent);color:var(--pd-ink);border-radius:.6rem;padding:.7rem 1rem}
        fieldset.pd-sign{border:1px solid var(--pd-line);border-radius:1rem;background:#fff;padding:1.5rem 2rem;margin:0 0 1rem;min-width:0}
        fieldset.pd-sign legend{font-size:1.15rem;font-weight:700;padding:0 .35rem;margin-left:-.35rem}
        fieldset.pd-sign legend{margin-bottom:1rem}
        .pd-sign label{display:block;margin:0 0 .3rem;font-size:.8rem;font-weight:600}
        .pd-sign input[type=text],.pd-sign input[type=email]{font:inherit;padding:.65rem .75rem;border:1px solid #cfcac1;border-radius:.5rem;width:100%;background:#fff}
        .pd-sign input:focus-visible,.pd-sign button:focus-visible,.pd-cta:focus-visible{outline:3px solid var(--pd-accent);outline-offset:2px}
        .pd-sign .pd-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
        .pd-sign #typed_name{font-style:italic;font-size:1.15rem;background:#faf9f6;border-style:dashed}
        .pd-sign .consent{display:flex;gap:.6rem;align-items:flex-start;background:#faf9f6;border:1px solid var(--pd-line);border-radius:.6rem;padding:.8rem 1rem;margin:1rem 0;font-size:.85rem}
        .pd-sign .consent input{margin-top:.2rem;flex:none}
        .pd-sign .consent{font-weight:400}
        .pd-sign .consent span{margin:0}
        @if($blocksHtml !== null)
        .pd-card .doc-signature-slot{border-top:1px solid var(--pd-line);margin-top:1.25rem;padding-top:1.25rem}
        .pd-card .doc-signature-slot fieldset.pd-sign{border:0;border-radius:0;padding:0;margin:0}
        @endif
        .pd-actions{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
        button,.pd-btn{font:inherit;font-weight:600;background:var(--pd-accent);color:var(--pd-ink);border:0;border-radius:.6rem;padding:.7rem 1.2rem;cursor:pointer}
        button:disabled{opacity:.6;cursor:not-allowed}
        .error{color:#a51d24}
        .muted{color:var(--pd-muted);font-size:.9rem}
        @media (max-width:860px){
            .pd-wrap{grid-template-columns:minmax(0,1fr);grid-template-areas:"head" "summary" "main" "note";padding:1rem .75rem 2rem}
            .pd-summary{position:static}
            .pd-issuer{padding:1rem 1rem 0}
        @if($blocksHtml !== null)
            .pd-legacy,.pd-payment{padding-left:1rem;padding-right:1rem}
            @if($blocksHtml !== null).pd-card .doc-blocks{padding-left:1rem;padding-right:1rem}@endif
        @endif
            fieldset.pd-sign{padding:1.1rem 1rem}
            .pd-sign .pd-row{grid-template-columns:1fr}
            .pd-actions button{width:100%}
            h1.pd-title{font-size:1.35rem}
        }
        @media print{.pd-bar,.pd-summary,.pd-note,.pd-sign{display:none}.pd-wrap{display:block;padding:0}.pd-card{border:0}}
    </style>
</head>
<body>
<header class="pd-bar" data-role="brand-header">
    <div class="pd-bar-in">
        @if($brand['logo_url'])
            <img class="pd-logo" data-role="brand-logo" src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }} logo">
        @else
            <span class="pd-logo" data-role="brand-initial" aria-hidden="true">{{ $brand['initials'] }}</span>
        @endif
        <span class="pd-bar-name" data-role="brand-name">{{ $brand['name'] }}</span>
        <span class="pd-secure">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg>
            Secure signing
        </span>
    </div>
</header>

<div class="pd-wrap">
    <div class="pd-head">
        <span class="pd-pill" data-role="document-kind">{{ $kindLabel }}</span>
        <h1 class="pd-title">{{ $document->title }}</h1>
    </div>

    {{-- At-a-glance summary. Restates persisted values only and offers only
         actions that exist: the sign form below, or the payment section. --}}
    <aside class="pd-summary" data-role="summary" aria-label="Summary">
        <div class="pd-summary-card">
            <div class="pd-eyebrow">Total</div>
            <div class="pd-amount" data-role="summary-total">{{ $totalText }}</div>
            @if($howPaid)<div class="muted" data-role="summary-how-paid">{{ $howPaid }}</div>@endif
            <dl class="pd-facts">
                <dt>From</dt><dd data-role="summary-from">{{ $parties['business_name'] }}</dd>
                @if($parties['recipient_name'])<dt>For</dt><dd data-role="summary-for">{{ $parties['recipient_name'] }}</dd>@endif
                <dt>Status</dt>
                <dd>
                    @if($isPaid)
                        <span class="pd-status is-ok" data-role="summary-status">Paid</span>
                    @elseif($isSigned && $paymentProcessing)
                        <span class="pd-status" data-role="summary-status">Payment processing</span>
                    @elseif($isSigned && $receivedMinor > 0)
                        <span class="pd-status {{ $payableNow ? '' : 'is-ok' }}" data-role="summary-status">{{ $payableNow ? 'Deposit received — balance due' : 'Deposit received' }}</span>
                    @elseif($isSigned)
                        <span class="pd-status {{ $payableNow ? '' : 'is-ok' }}" data-role="summary-status">{{ $payableNow ? 'Signed — payment due' : 'Signed' }}</span>
                    @else
                        <span class="pd-status" data-role="summary-status">Waiting for signature</span>
                    @endif
                </dd>
            </dl>
            @if($signable)
                <a class="pd-cta" data-role="summary-action" href="#sign">Review &amp; sign</a>
            @elseif($payableNow)
                <a class="pd-cta" data-role="summary-action" href="#pay">Pay now</a>
            @endif
            {{-- Kept in the DOM (hidden) so the payment-status script can show or
                 hide the one sidebar action as the verified status changes. --}}
        </div>
    </aside>

    <main class="pd-main">
        {{-- Party names come from the FROZEN issued version, never the live
             Business row: a later rename must not rewrite what was sent or signed. --}}
        <section class="pd-card" aria-label="{{ $kindLabel }}">
            <div class="pd-issuer">
                @if($brand['logo_url'])
                    <img class="pd-logo" src="{{ $brand['logo_url'] }}" alt="">
                @else
                    <span class="pd-logo" aria-hidden="true">{{ $brand['initials'] }}</span>
                @endif
                <div>
                    <p data-role="issuer">{{ $parties['business_name'] }}</p>
                    @if($parties['business_location_name'])<p class="pd-sub">{{ $parties['business_location_name'] }}</p>@endif
                </div>
            </div>

            @if($isSigned)
                <div class="pd-legacy" style="padding-bottom:0"><div class="pd-notice"><p data-role="already-signed">This document was signed on {{ $document->signed_at?->format('j F Y') }}.</p></div></div>
            @endif

            {{-- Paid confirmation. Rendered ONLY from persisted state, which only the
                 verified provider webhook (or a verified server-side retrieval)
                 writes — coming back from the card form proves nothing here. --}}
            @if($isPaid)
                <div class="pd-legacy" style="padding-bottom:0"><div class="pd-notice"><p data-role="paid-confirmation"><strong>Payment received — thank you.</strong> This was paid in full on {{ $document->paid_at?->format('j F Y') }}.</p></div></div>
            @elseif($paymentProcessing)
                <div class="pd-legacy" style="padding-bottom:0"><div class="pd-notice"><p data-role="payment-processing">Your payment is being confirmed. This page shows it as paid as soon as your bank confirms it — you do not need to pay again.</p></div></div>
            @endif

{{-- Contract 17B: a block document (content.blocks) renders through the one
    DocumentBlockRenderer; a legacy version (content.body) takes the original
    path below, unchanged. --}}@if($blocksHtml === null)
            <div class="pd-legacy">
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
                <div id="pay">@include('public.documents._payment')</div>
            @endif
            </div>
        </section>

        @if($signable)
            @include('public.documents._sign_form')
        @endif
@else
            {{ $blocksHtml }}

            @if($schedule->isNotEmpty())
                {{-- Contract 17B §7 — the sign-then-pay landing anchor. --}}
                <div id="pay" class="pd-payment" data-role="payment-section">
                    @include('public.documents._payment')
                </div>
            @endif
        </section>

        {{-- The sign form is injected at the signature block; if the version has
             none (it cannot be sent that way, but fail safe) it follows the content. --}}
        @if($signable && ! $signatureInBlocks)
            @include('public.documents._sign_form')
        @endif
@endif
    </main>

    @if($signable)
        <p class="pd-note" data-role="summary-note">Questions or changes? Get in touch with {{ $parties['business_name'] }} before signing.</p>
    @endif
</div>
</body>
</html>
