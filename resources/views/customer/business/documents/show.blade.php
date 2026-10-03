@extends('layouts/contentLayoutMaster')
@section('title', 'Document')
@section('content')
<a href="{{ route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]) }}">Documents</a>
<h4>{{ $document->title }} <small>{{ $document->status->value }}</small></h4>
<x-flash-alert />
@if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@php($base = [$workspaceUid, $businessUid, $document->uid])
@php($statusValue = $document->status->value)
@php($money = fn ($minor) => number_format(((int) $minor) / 100, 2))
<p class="text-muted" data-role="document-summary">
    {{ ucfirst($document->kind->value) }}
    @if($location) · {{ $location->name }}@endif
    @if($contact) · {{ $contact->phone }}@endif
    @if($document->recipient_email_snapshot) · {{ $document->recipient_email_snapshot }}@endif
</p>
@if($statusValue === 'paid')
    <div class="alert alert-success" data-role="paid-confirmation">Paid in full on {{ $document->paid_at?->format('j F Y') }}. This document can no longer be changed.</div>
@elseif($statusValue === 'void')
    <div class="alert alert-secondary" data-role="void-notice">Voided on {{ $document->voided_at?->format('j F Y') }}@if($document->void_reason): {{ $document->void_reason }}@endif. The customer's link no longer works.</div>
@elseif($statusValue === 'expired')
    <div class="alert alert-secondary" data-role="expired-notice">This offer expired on {{ $document->expired_at?->format('j F Y') }}.</div>
@elseif(in_array($statusValue, ['sent', 'signed']))
    <div class="alert alert-info" data-role="awaiting-notice">Sent{{ $document->sent_at ? ' on ' . $document->sent_at->format('j F Y') : '' }} — awaiting {{ $statusValue === 'sent' && $document->requires_signature ? 'signature and payment' : 'payment' }}.</div>
@endif
<div class="card p-2 mb-2" data-role="history-panel">
    <h5>History</h5>
    <ul data-role="history">
        <li>Created {{ $document->created_at?->format('j M Y H:i') }}</li>
        @if($document->sent_at)<li>Sent {{ $document->sent_at->format('j M Y H:i') }}@if($document->recipient_email_snapshot) to {{ $document->recipient_email_snapshot }}@endif</li>@endif
        @foreach($versions as $listed)
            @if($listed->issued_at)<li>Version {{ $listed->version_number }} issued {{ $listed->issued_at->format('j M Y H:i') }} ({{ $listed->state->value }})</li>@endif
        @endforeach
        @if($document->signed_at)<li>Signed {{ $document->signed_at->format('j M Y H:i') }}@if($signature) by {{ $signature->signer_name }}@endif</li>@endif
        @if($document->paid_at)<li>Paid {{ $document->paid_at->format('j M Y H:i') }}</li>@endif
        @if($document->expired_at)<li>Expired {{ $document->expired_at->format('j M Y H:i') }}</li>@endif
        @if($document->voided_at)<li>Voided {{ $document->voided_at->format('j M Y H:i') }}@if($document->void_reason): {{ $document->void_reason }}@endif</li>@endif
    </ul>
</div>
@if($issued)
<div class="card p-2 mb-2" data-role="issued-version">
    <h5>Sent version {{ $issued->version_number }} <small class="text-muted">(frozen — this is what the customer sees)</small></h5>
    @if(! empty($issued->content['body']))<div data-role="issued-terms" style="white-space:pre-wrap">{{ $issued->content['body'] }}</div>@endif
    @if(! empty($issuedBlocksHtml))<div class="mb-1" data-role="issued-blocks" style="overflow-x:auto">{{ $issuedBlocksHtml }}</div>@endif
    @foreach($issued->lineItems->sortBy('position') as $line)
        <div>{{ $line->name }} — {{ $line->quantity }} × {{ $money($line->unit_price_minor) }} = {{ $money($line->line_total_minor) }} {{ $line->currency_code }}</div>
    @endforeach
    <strong>Total: {{ $money($issued->total_minor) }} {{ $issued->currency_code }}</strong>
    @foreach($issued->paymentScheduleItems->sortBy('sequence') as $term)
        <div data-role="schedule-item">{{ ucfirst($term->kind->value) }}: {{ $money($term->amount_minor) }} {{ $term->currency_code }} — {{ $term->status->value }}@if($term->paid_at) ({{ $term->paid_at->format('j F Y') }})@endif</div>
    @endforeach
    @if($signature)
        <h6>Signature</h6>
        <p data-role="signature">
            Typed by {{ $signature->typed_name }} ({{ $signature->signer_name }}, {{ $signature->signer_email }}) on {{ $signature->signed_at->format('j M Y H:i') }}.
            This is a typed-signature record bound to this exact version (fingerprint {{ substr($signature->signed_content_hash, 0, 12) }}).
        </p>
    @endif
</div>
@endif
@if($payments->isNotEmpty())
<div class="card p-2 mb-2" data-role="payments">
    <h5>Payments and receipts</h5>
    @foreach($payments as $payment)
        <div class="mb-1" data-role="payment" data-payment-uid="{{ $payment->uid }}">
            {{ $money($payment->amount_minor) }} {{ $payment->currency_code }} — <strong>{{ str_replace('_', ' ', $payment->status->value) }}</strong>
            @if($payment->succeeded_at) · received {{ $payment->succeeded_at->format('j F Y H:i') }}@endif
            @if($payment->status->value === 'succeeded') · receipt {{ $payment->receipt_sent_at ? 'emailed' : 'pending' }}@endif
            @foreach($payment->refunds as $refund)
                <div class="text-muted" data-role="refund">Refund {{ $money($refund->amount_minor) }} {{ $payment->currency_code }} — {{ $refund->status->value }}</div>
            @endforeach
            @if(($refundable[$payment->id] ?? 0) > 0)
                <form method="post" action="{{ route('customer.workspaces.businesses.documents.payments.refund', [$workspaceUid, $businessUid, $document->uid, $payment->uid]) }}">
                    @csrf
                    <label>Refund (minor units, up to {{ $refundable[$payment->id] }}) <input type="number" name="amount_minor" min="1" max="{{ $refundable[$payment->id] }}" value="{{ $refundable[$payment->id] }}" required></label>
                    <label>Reason <input name="reason" maxlength="255"></label>
                    <label><input type="checkbox" name="confirm" value="1" required> I confirm this returns money to the customer</label>
                    <button type="submit">Refund</button>
                </form>
            @endif
        </div>
    @endforeach
</div>
@endif
@if(in_array($statusValue, ['sent', 'signed']))
<div class="card p-2 mb-2" data-role="link-actions">
    <p data-role="delivery">
        @if($document->link_delivery_failed_at)
            The email with the link could not be delivered. Re-send it below.
        @elseif($document->link_delivered_at)
            The email with the link was handed to the mail provider {{ $document->link_delivered_at->format('j M Y H:i') }}.
        @else
            The email with the link is being delivered.
        @endif
    </p>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.resend', [$workspaceUid, $businessUid, $document->uid]) }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Re-send payment link</button>
    </form>
    @if($statusValue === 'sent' && ! $version && ! $document->signature)
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.revise', [$workspaceUid, $businessUid, $document->uid]) }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Revise (new version)</button>
    </form>
    @endif
</div>
@endif
@if($version)
<div class="card p-2 mb-2">
    <h5>Draft details</h5>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.update', $base) }}">
        @csrf @method('PATCH')
        <label>Title <input name="title" value="{{ $document->title }}" required maxlength="200"></label>
        <label>Body and terms <textarea name="content[body]">{{ $version->content['body'] ?? '' }}</textarea></label>
        <button class="btn btn-primary" type="submit">Save</button>
    </form>
</div>
<div class="card p-2 mb-2">
    <h5>Lines</h5>
    @php($orderedLines = $version->lineItems->sortBy('position')->values())
    @foreach($orderedLines as $index => $line)
        <div>{{ $line->name }} — {{ $line->quantity }} × {{ $line->unit_price_minor }} = {{ $line->line_total_minor }} {{ $line->currency_code }}
            <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.destroy', [...$base, $line->uid]) }}">@csrf @method('DELETE')<button type="submit">Remove</button></form>
            @if($index > 0)
            <form method="post" action="{{ route('customer.workspaces.businesses.documents.lines.order', $base) }}">
                @csrf @method('PUT')
                @foreach($orderedLines as $orderIndex => $orderedLine)
                    <input type="hidden" name="line_uids[]" value="{{ $orderedLines[$orderIndex === $index - 1 ? $index : ($orderIndex === $index ? $index - 1 : $orderIndex)]->uid }}">
                @endforeach
                <button type="submit">Move up</button>
            </form>
            @endif
        </div>
    @endforeach
    <strong>Total: {{ $version->total_minor }} {{ $version->currency_code }} (minor units)</strong>
    <h6>Send</h6>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.update', $base) }}">
        @csrf @method('PATCH')
        <label>Recipient name <input name="recipient_name_snapshot" value="{{ $document->recipient_name_snapshot }}" maxlength="191"></label>
        <label>Recipient email <input type="email" name="recipient_email_snapshot" value="{{ $document->recipient_email_snapshot }}" maxlength="255"></label>
        <button class="btn btn-secondary" type="submit">Save recipient</button>
    </form>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.send', $base) }}">
        @csrf
        <button class="btn btn-primary" type="submit">Send document</button>
    </form>
    <h6>Add catalog package</h6>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.catalog-lines.store', $base) }}">
        @csrf
        <select name="catalog_item_uid" required>@foreach($catalogItems as $item)<option value="{{ $item->uid }}">{{ $item->name }}</option>@endforeach</select>
        <label>Quantity <input type="number" name="quantity" min="1" value="1" required></label>
        <label>Explicit price (quote-only, minor units) <input type="number" name="explicit_price_minor" min="0"></label>
        <button type="submit">Add package</button>
    </form>
    <h6>Add custom line</h6>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.custom-lines.store', $base) }}">
        @csrf
        <label>Name <input name="name" maxlength="200" required></label>
        <label>Quantity <input type="number" name="quantity" min="1" value="1" required></label>
        <label>Unit price (minor units) <input type="number" name="unit_price_minor" min="0" required></label>
        <button type="submit">Add custom line</button>
    </form>
</div>
<div class="card p-2 mb-2">
    <h5>Payment terms</h5>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
        @csrf @method('PUT')
        <input type="hidden" name="terms[0][kind]" value="full">
        <input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
        <label>Full amount (minor units) <input type="number" name="terms[0][amount_minor]" min="0" value="{{ $version->total_minor }}" required></label>
        <button type="submit">Set full payment</button>
    </form>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.schedule.update', $base) }}">
        @csrf @method('PUT')
        <input type="hidden" name="terms[0][kind]" value="deposit"><input type="hidden" name="terms[0][currency_code]" value="{{ $document->currency_code }}">
        <input type="hidden" name="terms[1][kind]" value="balance"><input type="hidden" name="terms[1][currency_code]" value="{{ $document->currency_code }}">
        <label>Deposit <input type="number" name="terms[0][amount_minor]" min="0" required></label>
        <label>Balance <input type="number" name="terms[1][amount_minor]" min="0" required></label>
        <button type="submit">Set deposit and balance</button>
    </form>
    @foreach($version->paymentScheduleItems as $term)<p>{{ $term->kind->value }}: {{ $term->amount_minor }} {{ $term->currency_code }}</p>@endforeach
</div>
@endif
@if(in_array($document->status->value, ['draft', 'sent', 'signed']))
<form method="post" action="{{ route('customer.workspaces.businesses.documents.void', [$workspaceUid, $businessUid, $document->uid]) }}">
    @csrf <label>Void reason <input name="reason" required maxlength="255"></label><button type="submit">Void document</button>
</form>
@endif
@endsection
