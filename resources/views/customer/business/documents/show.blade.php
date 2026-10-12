@extends('layouts/contentLayoutMaster')
@section('title', $document->title ?: 'Document')

@section('page-style')
    <style>
        /* Internal document detail: same tokens and patterns as the Payments & Contracts list (pd-*). */
        .dd { color: var(--color-text-primary, #262522); }
        .dd-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); margin-bottom: .75rem; }
        .dd-crumbs a { color: inherit; text-decoration: none; }
        .dd-crumbs a:hover { color: var(--color-link-hover, #A83E38); text-decoration: underline; }
        .dd-crumbs [aria-current] { color: var(--color-text-secondary, #676664); }
        .dd-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
        .dd-head__main { min-width: 0; flex: 1 1 22rem; }
        .dd-title { display: flex; flex-wrap: wrap; align-items: center; gap: .625rem; margin: 0; font-size: 1.75rem; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
        .dd-title .badge { display: inline-flex; align-items: center; gap: .3rem; }
        .dd-sub { margin: .375rem 0 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
        .dd-head__actions { display: flex; flex-wrap: wrap; gap: .625rem; }
        .dd-btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; height: 2.5rem; padding: 0 1rem; border-radius: .5rem; border: 1px solid var(--color-border-strong, #A29F9A); background: var(--color-surface, #fff); font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); text-decoration: none; cursor: pointer; white-space: nowrap; }
        .dd-btn:hover { text-decoration: none; background: var(--color-surface-secondary, #FBFAF7); }
        .dd-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--color-primary-border, #E4C1BF); }
        .dd-btn--primary { border-color: var(--color-primary, #B5524C); background: var(--color-primary, #B5524C); color: #fff; }
        .dd-btn--primary:hover { background: var(--color-primary-hover, #A83E38); color: #fff; }
        .dd-btn--block { width: 100%; }
        .dd-btn--danger { border-color: var(--color-status-danger, #EA5455); color: var(--color-status-danger, #EA5455); }
        .dd-btn--danger:hover { background: color-mix(in srgb, var(--color-status-danger, #EA5455) 8%, #fff); }
        .dd-notice { display: flex; align-items: flex-start; gap: .625rem; padding: .75rem 1rem; margin-bottom: 1rem; border-radius: .625rem; border: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface-secondary, #FBFAF7); font-size: .875rem; }
        .dd-notice--success { border-color: color-mix(in srgb, var(--color-status-success, #28C76F) 40%, #fff); background: color-mix(in srgb, var(--color-status-success, #28C76F) 9%, #fff); }
        .dd-notice--info { border-color: var(--color-primary-border, #E4C1BF); background: var(--color-primary-soft-bg, #F4E5E4); }
        .dd-notice--danger { border-color: color-mix(in srgb, var(--color-status-danger, #EA5455) 40%, #fff); background: color-mix(in srgb, var(--color-status-danger, #EA5455) 8%, #fff); }
        /* ---- stat cards ---- */
        .dd-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .dd-stat { padding: 1rem 1.25rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; background: var(--color-surface, #fff); min-width: 0; }
        .dd-stat__label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .dd-stat__value { margin-top: .25rem; font-size: 1.5rem; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
        .dd-stat__note { margin-top: .125rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
        /* ---- two-column body ---- */
        .dd-grid { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; gap: 1.25rem; align-items: start; }
        .dd-main, .dd-side { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
        .dd-card { border: 1px solid var(--color-border, #E5E1DA); border-radius: .875rem; background: var(--color-surface, #fff); min-width: 0; }
        .dd-card__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border-subtle, #F2F0ED); }
        .dd-card__title { margin: 0; font-size: 1rem; font-weight: 700; }
        .dd-card__sub { margin-left: .5rem; font-size: .8125rem; font-weight: 400; color: var(--color-text-muted, #6F6D67); }
        .dd-card__body { padding: 1.25rem; }
        .dd-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .1875rem .625rem; border-radius: 999px; border: 1px solid var(--color-border, #E5E1DA); font-size: .75rem; color: var(--color-text-secondary, #676664); background: var(--color-surface-secondary, #FBFAF7); }
        /* ---- the document as the customer saw it ---- */
        .dd-paper { border: 1px solid var(--color-border, #E5E1DA); border-radius: .75rem; padding: 1.5rem; background: var(--color-surface, #fff); }
        .dd-brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; }
        .dd-brand__mark { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2.25rem; height: 2.25rem; border-radius: .5rem; background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-primary, #B5524C); font-size: .75rem; font-weight: 700; }
        .dd-brand__name { font-size: .875rem; font-weight: 600; }
        .dd-brand__meta { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
        .dd-doc-title { margin: 0 0 .75rem; font-size: 1.5rem; font-weight: 700; overflow-wrap: anywhere; }
        .dd-body { white-space: pre-wrap; font-size: .9375rem; line-height: 1.6; overflow-wrap: anywhere; }
        .dd-blocks { overflow-x: auto; }
        .dd-h { margin: 1.5rem 0 .5rem; font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .dd-lines { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .dd-lines th { text-align: left; font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); padding: .5rem 0; border-bottom: 1px solid var(--color-border, #E5E1DA); }
        .dd-lines td { padding: .625rem 0; border-bottom: 1px solid var(--color-border-subtle, #F2F0ED); vertical-align: top; overflow-wrap: anywhere; }
        .dd-lines .dd-num { text-align: right; white-space: nowrap; padding-left: 1rem; }
        .dd-lines tfoot td { border-bottom: 0; font-weight: 700; }
        .dd-terms { margin: .75rem 0 0; padding: 0; list-style: none; font-size: .8125rem; color: var(--color-text-secondary, #676664); }
        .dd-terms li { padding: .25rem 0; }
        .dd-sig { margin-top: 1.5rem; padding: 1rem 1.125rem; border-radius: .75rem; border: 1px solid color-mix(in srgb, var(--color-status-success, #28C76F) 35%, #fff); background: color-mix(in srgb, var(--color-status-success, #28C76F) 7%, #fff); }
        .dd-sig__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .6875rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .dd-sig__name { margin: .5rem 0; font-family: "Brush Script MT", "Segoe Script", cursive; font-size: 1.75rem; font-style: italic; overflow-wrap: anywhere; }
        .dd-sig__meta { font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
        /* ---- sidebar ---- */
        .dd-side-card { padding: 1.125rem 1.25rem; }
        .dd-side-card h3 { margin: 0 0 .75rem; font-size: .9375rem; font-weight: 700; }
        .dd-link-state { display: flex; align-items: flex-start; gap: .625rem; padding: .625rem .75rem; margin-bottom: .75rem; border-radius: .5rem; background: var(--color-surface-secondary, #FBFAF7); border: 1px solid var(--color-border-subtle, #F2F0ED); font-size: .8125rem; }
        .dd-link-state--bad { background: color-mix(in srgb, var(--color-status-danger, #EA5455) 8%, #fff); border-color: color-mix(in srgb, var(--color-status-danger, #EA5455) 35%, #fff); }
        .dd-hint { margin: .625rem 0 0; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
        .dd-timeline { list-style: none; margin: 0; padding: 0; }
        .dd-event { position: relative; display: flex; gap: .75rem; padding-bottom: 1rem; }
        .dd-event:last-child { padding-bottom: 0; }
        .dd-event:not(:last-child)::before { content: ""; position: absolute; left: .9375rem; top: 2rem; bottom: .125rem; width: 1px; background: var(--color-border, #E5E1DA); }
        .dd-event__dot { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 1.875rem; height: 1.875rem; border-radius: 50%; background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-primary, #B5524C); }
        .dd-event__dot--success { background: color-mix(in srgb, var(--color-status-success, #28C76F) 14%, #fff); color: color-mix(in srgb, var(--color-status-success, #28C76F) 70%, #000); }
        .dd-event__dot--muted { background: var(--color-border-subtle, #F2F0ED); color: var(--color-text-secondary, #676664); }
        .dd-event__title { font-size: .875rem; font-weight: 600; overflow-wrap: anywhere; }
        .dd-event__meta { font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
        /* ---- forms ---- */
        .dd-field { display: flex; flex-direction: column; gap: .25rem; min-width: 0; font-size: .75rem; font-weight: 600; color: var(--color-text-secondary, #676664); margin-bottom: .75rem; }
        .dd-field input:not([type="checkbox"]), .dd-field textarea, .dd-field select, .dd-input { width: 100%; padding: 0 .75rem; height: 2.5rem; border: 1px solid var(--color-input-border, #E5E1DA); border-radius: .5rem; background: var(--color-input-bg, #fff); font-size: .8125rem; font-weight: 400; color: var(--color-text-primary, #262522); }
        .dd-field textarea { height: auto; min-height: 9rem; padding: .625rem .75rem; line-height: 1.5; }
        .dd-field input:focus, .dd-field textarea:focus, .dd-field select:focus, .dd-input:focus { outline: none; border-color: var(--color-focus-border, #B5524C); box-shadow: 0 0 0 3px var(--color-primary-border, #E4C1BF); }
        .dd-check { display: flex; align-items: flex-start; gap: .5rem; margin: .5rem 0 .75rem; font-size: .8125rem; font-weight: 400; color: var(--color-text-secondary, #676664); }
        .dd-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .75rem 0; border-bottom: 1px solid var(--color-border-subtle, #F2F0ED); font-size: .875rem; }
        .dd-row:last-child { border-bottom: 0; }
        .dd-row__actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .dd-btn--sm { height: 2rem; padding: 0 .75rem; font-size: .8125rem; }
        .dd-section + .dd-section { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--color-border-subtle, #F2F0ED); }
        .dd-section h4 { margin: 0 0 .75rem; font-size: .9375rem; font-weight: 700; }
        .dd-pay { padding: .875rem 0; border-bottom: 1px solid var(--color-border-subtle, #F2F0ED); font-size: .875rem; }
        .dd-pay:last-child { border-bottom: 0; }
        .dd-refund-form { margin-top: .75rem; padding: .875rem; border-radius: .625rem; background: var(--color-surface-secondary, #FBFAF7); border: 1px solid var(--color-border-subtle, #F2F0ED); }
        .dd-muted { color: var(--color-text-muted, #6F6D67); }
        @media (max-width: 1199.98px) {
            .dd-grid { grid-template-columns: minmax(0, 1fr) 17.5rem; }
        }
        @media (max-width: 991.98px) {
            .dd-grid { grid-template-columns: minmax(0, 1fr); }
            .dd-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        }
        @media (max-width: 767.98px) {
            .dd-title { font-size: 1.5rem; }
            .dd-stats { grid-template-columns: minmax(0, 1fr); }
            .dd-head__actions { width: 100%; }
            .dd-head__actions > * { flex: 1 1 auto; }
            .dd-paper { padding: 1rem; }
            .dd-card__body { padding: 1rem; }
            .dd-lines .dd-hide-sm { display: none; }
        }
    </style>
@endsection

@section('content')
@php
    $base = [$workspaceUid, $businessUid, $document->uid];
    $statusValue = $document->status->value;
    $money = fn ($minor) => number_format(((int) $minor) / 100, 2);
    $statusVariant = fn ($s) => match ($s) { 'paid', 'signed' => 'success', 'sent' => 'accent', 'expired', 'void' => 'warning', default => 'neutral' };
    $shown = $issued ?? $version;
    $currency = $shown?->currency_code ?? $document->currency_code;
    $isLive = in_array($statusValue, ['sent', 'signed'], true);
    $awaitingPayment = $isLive;
    $indexUrl = route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]);
    $businessName = $document->business?->name;
    $recipientName = $document->recipient_name_snapshot ?: ($contact?->first_name ?? null);
    $recipientFirst = $recipientName ? trim(explode(' ', trim($recipientName))[0]) : null;
    $when = fn ($d) => $d?->format('j M Y, H:i');

    $scheduleItems = $issued ? $issued->paymentScheduleItems->sortBy('sequence')->values() : collect();
    $paymentNote = null;
    if ($scheduleItems->count() === 1 && $scheduleItems->first()->kind->value === 'full') {
        $paymentNote = 'Full amount' . ($document->requires_signature ? ', due after signing' : '');
    } elseif ($scheduleItems->count() > 1) {
        $paymentNote = $scheduleItems->map(fn ($t) => ucfirst($t->kind->value) . ' ' . $currency . ' ' . $money($t->amount_minor))->implode(' · ');
    }
    $paymentLabel = match ($statusValue) {
        'paid' => 'Paid',
        'void' => 'Void',
        'expired' => 'Expired',
        'draft' => 'Not sent',
        default => 'Pending',
    };

    $events = collect();
    if ($document->created_at) { $events->push(['at' => $document->created_at, 'icon' => 'file-text', 'tone' => 'muted', 'title' => 'Created', 'meta' => null]); }
    if ($document->sent_at) { $events->push(['at' => $document->sent_at, 'icon' => 'send', 'tone' => 'primary', 'title' => 'Sent' . ($document->recipient_email_snapshot ? ' to ' . $document->recipient_email_snapshot : ''), 'meta' => null]); }
    foreach ($versions as $listed) {
        if ($listed->issued_at) { $events->push(['at' => $listed->issued_at, 'icon' => 'hash', 'tone' => 'primary', 'title' => 'Version ' . $listed->version_number . ' issued', 'meta' => $listed->state->value]); }
    }
    if ($document->signed_at) { $events->push(['at' => $document->signed_at, 'icon' => 'check', 'tone' => 'success', 'title' => 'Signed' . ($signature ? ' by ' . $signature->signer_name : ''), 'meta' => null]); }
    if ($document->paid_at) { $events->push(['at' => $document->paid_at, 'icon' => 'credit-card', 'tone' => 'success', 'title' => 'Paid', 'meta' => null]); }
    if ($document->expired_at) { $events->push(['at' => $document->expired_at, 'icon' => 'clock', 'tone' => 'muted', 'title' => 'Expired', 'meta' => null]); }
    if ($document->voided_at) { $events->push(['at' => $document->voided_at, 'icon' => 'x', 'tone' => 'muted', 'title' => 'Voided', 'meta' => $document->void_reason]); }
    $events = $events->sortByDesc(fn ($e) => $e['at']->getTimestamp())->values();
@endphp
<div class="dd" data-role="document-detail">
    <nav class="dd-crumbs" aria-label="Breadcrumb" data-role="breadcrumb">
        <a href="{{ $indexUrl }}">Payments &amp; Contracts</a>
        <x-ds-icon name="chevron-right" size="14" />
        <span aria-current="page">{{ $document->title ?: 'Untitled ' . $document->kind->value }}</span>
    </nav>

    <div class="dd-head">
        <div class="dd-head__main">
            <h1 class="dd-title" data-role="document-title">
                <span>{{ $document->title ?: 'Untitled ' . $document->kind->value }}</span>
                <x-badge :variant="$statusVariant($statusValue)" data-role="document-status">@if($statusValue === 'signed' || $statusValue === 'paid')<x-ds-icon name="check" size="12" />@endif{{ ucfirst($statusValue) }}</x-badge>
                @if($awaitingPayment)<x-badge variant="warning" data-role="payment-status"><x-ds-icon name="clock" size="12" />Awaiting payment</x-badge>@endif
            </h1>
            <p class="dd-sub" data-role="document-summary">
                {{ ucfirst($document->kind->value) }}@if($recipientFirst) for {{ $recipientFirst }}@endif
                @if($businessName) · {{ $businessName }}@endif
                @if($location) · {{ $location->name }}@endif
                @if($contact && $contact->phone) · {{ $contact->phone }}@endif
                @if($document->recipient_email_snapshot) · {{ $document->recipient_email_snapshot }}@endif
                @if($document->sent_at) · Sent {{ $document->sent_at->format('j M Y') }}@endif
            </p>
        </div>
        <div class="dd-head__actions" data-role="header-actions">
            @if(! empty($editorUrl))<a class="dd-btn dd-btn--primary" href="{{ $editorUrl }}" data-role="open-editor"><x-ds-icon name="pencil" size="16" />Open in the visual editor</a>@endif
            @if($isLive)<button class="dd-btn dd-btn--primary" type="submit" form="dd-resend-form" data-role="header-resend"><x-ds-icon name="send" size="16" />Re-send payment link</button>@endif
        </div>
    </div>

    <x-flash-alert />
    @if(isset($errors) && $errors->any())<div class="dd-notice dd-notice--danger" role="alert">{{ $errors->first() }}</div>@endif

    @if($statusValue === 'paid')
        <div class="dd-notice dd-notice--success" data-role="paid-confirmation"><x-ds-icon name="check-circle" size="18" /><span>Paid in full on {{ $document->paid_at?->format('j F Y') }}. This document can no longer be changed.</span></div>
    @elseif($statusValue === 'void')
        <div class="dd-notice" data-role="void-notice"><x-ds-icon name="x" size="18" /><span>Voided on {{ $document->voided_at?->format('j F Y') }}@if($document->void_reason): {{ $document->void_reason }}@endif. The customer's link no longer works.</span></div>
    @elseif($statusValue === 'expired')
        <div class="dd-notice" data-role="expired-notice"><x-ds-icon name="clock" size="18" /><span>This offer expired on {{ $document->expired_at?->format('j F Y') }}.</span></div>
    @elseif($isLive)
        <div class="dd-notice dd-notice--info" data-role="awaiting-notice"><x-ds-icon name="info" size="18" /><span>Sent{{ $document->sent_at ? ' on ' . $document->sent_at->format('j F Y') : '' }} — awaiting {{ $statusValue === 'sent' && $document->requires_signature ? 'signature and payment' : 'payment' }}.</span></div>
    @endif

    <div class="dd-stats" data-role="document-stats">
        <div class="dd-stat" data-role="stat-total">
            <div class="dd-stat__label">Total</div>
            <div class="dd-stat__value" data-role="total">@if($shown && (int) $shown->total_minor > 0){{ $currency }} {{ $money($shown->total_minor) }}@else<span class="dd-muted">—</span>@endif</div>
            <div class="dd-stat__note">@if($shown){{ $shown->lineItems->count() }} {{ \Illuminate\Support\Str::plural('line item', $shown->lineItems->count()) }}@else No lines yet @endif</div>
        </div>
        <div class="dd-stat" data-role="stat-payment">
            <div class="dd-stat__label">Payment</div>
            <div class="dd-stat__value">{{ $paymentLabel }}</div>
            <div class="dd-stat__note">@if($statusValue === 'paid' && $document->paid_at){{ $document->paid_at->format('j M Y') }}@elseif($paymentNote){{ $paymentNote }}@else — @endif</div>
        </div>
        <div class="dd-stat" data-role="stat-signed">
            <div class="dd-stat__label">Signed</div>
            @if($document->signed_at)
                <div class="dd-stat__value">{{ $document->signed_at->format('j M, H:i') }}</div>
                <div class="dd-stat__note">@if($signature)Typed signature by {{ $signature->signer_name }}@else Signed @endif</div>
            @else
                <div class="dd-stat__value">{{ $document->requires_signature ? 'Not yet' : 'Not required' }}</div>
                <div class="dd-stat__note">{{ $document->requires_signature ? 'Waiting for the customer' : 'No signature needed' }}</div>
            @endif
        </div>
    </div>

    <div class="dd-grid">
        <div class="dd-main">
            @if($issued)
            <section class="dd-card" data-role="issued-version" aria-labelledby="dd-issued-h">
                <div class="dd-card__head">
                    <h2 class="dd-card__title" id="dd-issued-h">Sent version {{ $issued->version_number }}<span class="dd-card__sub">What the customer sees</span></h2>
                    <span class="dd-chip"><x-ds-icon name="lock" size="12" />Locked</span>
                </div>
                <div class="dd-card__body">
                    <div class="dd-paper">
                        <div class="dd-brand">
                            <span class="dd-brand__mark" aria-hidden="true">{{ strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) $businessName) ?: 'B', 0, 2)) }}</span>
                            <div>
                                <div class="dd-brand__name">{{ $businessName }}</div>
                                @if($location)<div class="dd-brand__meta">{{ $location->name }}</div>@endif
                            </div>
                        </div>
                        <h3 class="dd-doc-title">{{ $document->title }}</h3>
                        @if(! empty($issued->content['body']))<div class="dd-body" data-role="issued-terms">{{ $issued->content['body'] }}</div>@endif
                        @if(! empty($issuedBlocksHtml))<div class="dd-blocks" data-role="issued-blocks">{{ $issuedBlocksHtml }}</div>@endif

                        @if($issued->lineItems->isNotEmpty())
                        <div class="dd-h">Investment</div>
                        <table class="dd-lines" data-role="issued-lines">
                            <thead><tr><th>Item</th><th class="dd-num dd-hide-sm">Qty</th><th class="dd-num dd-hide-sm">Unit</th><th class="dd-num">Amount</th></tr></thead>
                            <tbody>
                            @foreach($issued->lineItems->sortBy('position') as $line)
                                <tr data-role="issued-line">
                                    <td>{{ $line->name }}<span class="dd-muted d-md-none"> · {{ $line->quantity }} × {{ $money($line->unit_price_minor) }}</span></td>
                                    <td class="dd-num dd-hide-sm">{{ $line->quantity }}</td>
                                    <td class="dd-num dd-hide-sm">{{ $money($line->unit_price_minor) }} {{ $line->currency_code }}</td>
                                    <td class="dd-num">{{ $money($line->line_total_minor) }} {{ $line->currency_code }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot><tr><td colspan="3">Total</td><td class="dd-num">{{ $money($issued->total_minor) }} {{ $issued->currency_code }}</td></tr></tfoot>
                        </table>
                        @else
                        <p class="mt-1"><strong>Total: {{ $money($issued->total_minor) }} {{ $issued->currency_code }}</strong></p>
                        @endif

                        @if($scheduleItems->isNotEmpty())
                        <ul class="dd-terms" data-role="payment-terms">
                            @foreach($scheduleItems as $term)
                                <li data-role="schedule-item">{{ ucfirst($term->kind->value) }}: {{ $money($term->amount_minor) }} {{ $term->currency_code }} — {{ $term->status->value }}@if($term->paid_at) ({{ $term->paid_at->format('j F Y') }})@endif</li>
                            @endforeach
                        </ul>
                        @endif

                        @if($signature)
                        <div class="dd-sig">
                            <div class="dd-sig__head"><span>Client signature</span><x-badge variant="success"><x-ds-icon name="check" size="12" />Signed</x-badge></div>
                            <div class="dd-sig__name">{{ $signature->typed_name }}</div>
                            <p class="dd-sig__meta" data-role="signature">
                                Typed by {{ $signature->typed_name }} ({{ $signature->signer_name }}, {{ $signature->signer_email }}) on {{ $signature->signed_at->format('j M Y H:i') }}.
                                This is a typed-signature record bound to this exact version (fingerprint {{ substr($signature->signed_content_hash, 0, 12) }}).
                            </p>
                        </div>
                        @endif
                    </div>
                </div>
            </section>
            @endif

            @if($payments->isNotEmpty())
            <section class="dd-card" data-role="payments" aria-labelledby="dd-pay-h">
                <div class="dd-card__head"><h2 class="dd-card__title" id="dd-pay-h">Payments and receipts</h2></div>
                <div class="dd-card__body">
                    @foreach($payments as $payment)
                        <div class="dd-pay" data-role="payment" data-payment-uid="{{ $payment->uid }}">
                            {{ $money($payment->amount_minor) }} {{ $payment->currency_code }} — <strong>{{ str_replace('_', ' ', $payment->status->value) }}</strong>
                            @if($payment->succeeded_at) · received {{ $payment->succeeded_at->format('j F Y H:i') }}@endif
                            @if($payment->status->value === 'succeeded') · receipt {{ $payment->receipt_sent_at ? 'emailed' : 'pending' }}@endif
                            @foreach($payment->refunds as $refund)
                                <div class="dd-muted" data-role="refund">Refund {{ $money($refund->amount_minor) }} {{ $payment->currency_code }} — {{ $refund->status->value }}</div>
                            @endforeach
                            @if(($refundable[$payment->id] ?? 0) > 0)
                                <form class="dd-refund-form" method="post" action="{{ route('customer.workspaces.businesses.documents.payments.refund', [$workspaceUid, $businessUid, $document->uid, $payment->uid]) }}">
                                    @csrf
                                    <label class="dd-field">Refund (minor units, up to {{ $refundable[$payment->id] }}) <input type="number" name="amount_minor" min="1" max="{{ $refundable[$payment->id] }}" value="{{ $refundable[$payment->id] }}" required></label>
                                    <label class="dd-field">Reason <input name="reason" maxlength="255"></label>
                                    <label class="dd-check"><input type="checkbox" name="confirm" value="1" required> I confirm this returns money to the customer</label>
                                    <button class="dd-btn dd-btn--danger dd-btn--sm" type="submit">Refund</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
            @endif

            @if($version)
            <section class="dd-card" data-role="draft-editor" aria-labelledby="dd-draft-h">
                <div class="dd-card__head"><h2 class="dd-card__title" id="dd-draft-h">Draft details</h2><span class="dd-chip">Not sent yet</span></div>
                <div class="dd-card__body">
                    <div class="dd-section">
                        <form method="post" action="{{ route('customer.workspaces.businesses.documents.update', $base) }}">
                            @csrf @method('PATCH')
                            <label class="dd-field">Title <input name="title" value="{{ $document->title }}" required maxlength="200"></label>
                            <label class="dd-field">Body and terms <textarea name="content[body]">{{ $version->content['body'] ?? '' }}</textarea></label>
                            <x-merge-field-picker :picker="$mergeFieldPicker ?? ['groups' => [], 'extra' => []]" target='[name="content[body]"]' />
                            <p class="dd-hint">Merge fields such as the contact's name or event date are filled in when the document is sent.</p>
                            <button class="dd-btn dd-btn--primary mt-1" type="submit"><x-ds-icon name="save" size="16" />Save</button>
                        </form>
                    </div>

                    <div class="dd-section" data-role="draft-lines">
                        <h4>Lines</h4>
                        @php($orderedLines = $version->lineItems->sortBy('position')->values())
                        @forelse($orderedLines as $index => $line)
                            <div class="dd-row">
                                <span>{{ $line->name }} — {{ $line->quantity }} × {{ $line->unit_price_minor }} = {{ $line->line_total_minor }} {{ $line->currency_code }}</span>
                                <span class="dd-row__actions">
                                    @if($index > 0)
                                    <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.order', $base) }}">
                                        @csrf @method('PUT')
                                        @foreach($orderedLines as $orderIndex => $orderedLine)
                                            <input type="hidden" name="line_uids[]" value="{{ $orderedLines[$orderIndex === $index - 1 ? $index : ($orderIndex === $index ? $index - 1 : $orderIndex)]->uid }}">
                                        @endforeach
                                        <button class="dd-btn dd-btn--sm" type="submit">Move up</button>
                                    </form>
                                    @endif
                                    <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.destroy', [...$base, $line->uid]) }}">@csrf @method('DELETE')<button class="dd-btn dd-btn--sm" type="submit">Remove</button></form>
                                </span>
                            </div>
                        @empty
                            <p class="dd-muted">No lines yet. Add a catalog package or a custom line below.</p>
                        @endforelse
                        <p class="mt-1"><strong>Total: {{ $version->total_minor }} {{ $version->currency_code }} (minor units)</strong></p>
                    </div>

                    <div class="dd-section">
                        <h4>Add catalog package</h4>
                        <form method="post" action="{{ route('customer.workspaces.businesses.documents.catalog-lines.store', $base) }}">
                            @csrf
                            <label class="dd-field">Package <select name="catalog_item_uid" required>@foreach($catalogItems as $item)<option value="{{ $item->uid }}">{{ $item->name }}</option>@endforeach</select></label>
                            <label class="dd-field">Quantity <input type="number" name="quantity" min="1" value="1" required></label>
                            <label class="dd-field">Explicit price (quote-only, minor units) <input type="number" name="explicit_price_minor" min="0"></label>
                            <button class="dd-btn" type="submit">Add package</button>
                        </form>
                    </div>

                    <div class="dd-section">
                        <h4>Add custom line</h4>
                        <form method="post" action="{{ route('customer.workspaces.businesses.documents.custom-lines.store', $base) }}">
                            @csrf
                            <label class="dd-field">Name <input name="name" maxlength="200" required></label>
                            <label class="dd-field">Quantity <input type="number" name="quantity" min="1" value="1" required></label>
                            <label class="dd-field">Unit price (minor units) <input type="number" name="unit_price_minor" min="0" required></label>
                            <button class="dd-btn" type="submit">Add custom line</button>
                        </form>
                    </div>

                    <div class="dd-section" data-role="draft-terms">
                        <h4>Payment terms</h4>
                        <form method="post" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="terms[0][kind]" value="full">
                            <input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
                            <label class="dd-field">Full amount (minor units) <input type="number" name="terms[0][amount_minor]" min="0" value="{{ $version->total_minor }}" required></label>
                            <button class="dd-btn" type="submit">Set full payment</button>
                        </form>
                        <form method="post" class="mt-1" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="terms[0][kind]" value="deposit"><input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
                            <input type="hidden" name="terms[1][kind]" value="balance"><input type="hidden" name="terms[1][currency_code]" value="{{ $document->currency_code }}">
                            <label class="dd-field">Deposit <input type="number" name="terms[0][amount_minor]" min="0" required></label>
                            <label class="dd-field">Balance <input type="number" name="terms[1][amount_minor]" min="0" required></label>
                            <button class="dd-btn" type="submit">Set deposit and balance</button>
                        </form>
                        @foreach($version->paymentScheduleItems as $term)<p class="dd-muted mt-1 mb-0">{{ $term->kind->value }}: {{ $term->amount_minor }} {{ $term->currency_code }}</p>@endforeach
                    </div>

                    <div class="dd-section" data-role="draft-send">
                        <h4>Send</h4>
                        <form method="post" action="{{ route('customer.workspaces.businesses.documents.update', $base) }}">
                            @csrf @method('PATCH')
                            <label class="dd-field">Recipient name <input name="recipient_name_snapshot" value="{{ $document->recipient_name_snapshot }}" maxlength="191"></label>
                            <label class="dd-field">Recipient email <input type="email" name="recipient_email_snapshot" value="{{ $document->recipient_email_snapshot }}" maxlength="255"></label>
                            <button class="dd-btn" type="submit">Save recipient</button>
                        </form>
                        <form method="post" class="mt-1" action="{{ route('customer.workspaces.businesses.documents.send', $base) }}">
                            @csrf
                            <button class="dd-btn dd-btn--primary" type="submit"><x-ds-icon name="send" size="16" />Send document</button>
                        </form>
                    </div>
                </div>
            </section>
            @endif
        </div>

        <aside class="dd-side" aria-label="Document actions and history">
            @if($isLive)
            <section class="dd-card dd-side-card" data-role="link-actions" id="payment-link">
                <h3>Payment link</h3>
                <div class="dd-link-state {{ $document->link_delivery_failed_at ? 'dd-link-state--bad' : '' }}" data-role="delivery">
                    <x-ds-icon name="{{ $document->link_delivery_failed_at ? 'alert-triangle' : 'mail' }}" size="16" />
                    <span>
                        @if($document->link_delivery_failed_at)
                            The email with the link could not be delivered. Re-send it below.
                        @elseif($document->link_delivered_at)
                            The email with the link was handed to the mail provider {{ $document->link_delivered_at->format('j M Y H:i') }}.
                        @else
                            The email with the link is being delivered.
                        @endif
                    </span>
                </div>
                <form id="dd-resend-form" method="post" action="{{ route('customer.workspaces.businesses.documents.resend', [$workspaceUid, $businessUid, $document->uid]) }}">
                    @csrf
                    <button class="dd-btn dd-btn--primary dd-btn--block" type="submit">Re-send payment link</button>
                </form>
                <p class="dd-hint">Re-sending issues a fresh secure link; earlier links stop working.</p>
                @if($statusValue === 'sent' && ! $version && ! $document->signature)
                <form class="mt-1" method="post" action="{{ route('customer.workspaces.businesses.documents.revise', [$workspaceUid, $businessUid, $document->uid]) }}">
                    @csrf
                    <button class="dd-btn dd-btn--block" type="submit">Revise (new version)</button>
                </form>
                @endif
            </section>
            @endif

            <section class="dd-card dd-side-card" data-role="history-panel">
                <h3>History</h3>
                <ul class="dd-timeline" data-role="history">
                    @foreach($events as $event)
                        <li class="dd-event">
                            <span class="dd-event__dot {{ $event['tone'] === 'success' ? 'dd-event__dot--success' : ($event['tone'] === 'muted' ? 'dd-event__dot--muted' : '') }}" aria-hidden="true"><x-ds-icon name="{{ $event['icon'] }}" size="14" /></span>
                            <div>
                                <div class="dd-event__title">{{ $event['title'] }}</div>
                                <div class="dd-event__meta">{{ $when($event['at']) }}@if($event['meta']) · {{ $event['meta'] }}@endif</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            @if(in_array($statusValue, ['draft', 'sent', 'signed'], true))
            <section class="dd-card dd-side-card" data-role="void-card">
                <h3>Void document</h3>
                <p class="dd-hint" style="margin-top:-.25rem">The signature and payment link stop working. This can't be undone.</p>
                <form method="post" action="{{ route('customer.workspaces.businesses.documents.void', [$workspaceUid, $businessUid, $document->uid]) }}">
                    @csrf
                    <label class="dd-field mt-1">Void reason <input name="reason" required maxlength="255" placeholder="e.g. Event cancelled"></label>
                    <button class="dd-btn dd-btn--danger dd-btn--block" type="submit">Void document</button>
                </form>
            </section>
            @endif
        </aside>
    </div>
</div>
@endsection

@section('page-script')
    <script src="{{ asset('js/merge-fields/insert-field.js') }}"></script>
@endsection
