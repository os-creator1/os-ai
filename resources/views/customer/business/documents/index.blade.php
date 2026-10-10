@extends('layouts/contentLayoutMaster')
@section('title', 'Documents')

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/documents-editor.css')) }}">
    <style>
        .pd-title-h { font-size: 1.75rem; font-weight: 700; color: var(--color-text-primary, #262522); }
        /* ---- three action cards ---- */
        .pd-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
        .pd-action { display: flex; align-items: center; gap: .875rem; padding: 1.125rem 1.25rem; min-height: 5rem; border-radius: .75rem; border: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface, #fff); color: var(--color-text-primary, #262522); text-align: left; width: 100%; text-decoration: none; transition: box-shadow .15s ease, border-color .15s ease; }
        .pd-action:hover, .pd-action:focus-visible { text-decoration: none; box-shadow: 0 6px 18px var(--color-shadow-tint, rgba(38, 37, 34, .1)); outline: none; }
        .pd-action:focus-visible { box-shadow: 0 0 0 3px var(--color-primary-border, #E4C1BF); }
        a.pd-action, a.pd-action:hover, a.pd-action:visited { color: var(--color-text-primary, #262522); }
        .pd-action--primary { background: var(--color-primary, #B5524C); border-color: var(--color-primary, #B5524C); color: #fff; box-shadow: 0 6px 16px color-mix(in srgb, var(--color-primary, #B5524C) 28%, transparent); }
        .pd-action--primary:hover, .pd-action--primary:focus-visible { color: #fff; }
        .pd-action.is-open { border-color: var(--color-primary, #B5524C); box-shadow: 0 0 0 1px var(--color-primary, #B5524C); }
        .pd-action__icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2.5rem; height: 2.5rem; border-radius: .625rem; background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-primary, #B5524C); }
        .pd-action--primary .pd-action__icon { background: rgba(255, 255, 255, .22); color: #fff; }
        .pd-action__icon--green { background: color-mix(in srgb, var(--color-status-success, #28C76F) 14%, #fff); color: color-mix(in srgb, var(--color-status-success, #28C76F) 70%, #000); }
        .pd-action__text { flex: 1; min-width: 0; }
        .pd-action__title { font-size: 1rem; font-weight: 600; display: block; }
        .pd-action__sub { font-size: .8125rem; opacity: .8; display: block; line-height: 1.35; }
        .pd-action__end { flex: none; display: inline-flex; transition: transform .15s ease; }
        /* ---- invoice panel (above the documents) ---- */
        .pd-invoice { margin-top: 1rem; padding: 1.25rem 1.5rem 1.25rem; border: 1px solid var(--color-primary-border, #E4C1BF); border-radius: .875rem; background: var(--color-surface, #fff); }
        .pd-invoice[hidden] { display: none; }
        .pd-invoice__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
        .pd-invoice__title { font-size: 1.125rem; font-weight: 700; margin: 0; }
        .pd-invoice__sub { margin: .125rem 0 0; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
        .pd-invoice__close { flex: none; display: inline-flex; padding: .375rem; border: 0; border-radius: .5rem; background: transparent; color: var(--color-text-secondary, #676664); cursor: pointer; }
        .pd-invoice__close:hover { background: var(--color-row-hover, #F4E5E4); }
        .pd-invoice__fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; margin-top: .75rem; }
        .pd-field { display: flex; flex-direction: column; gap: .25rem; min-width: 0; font-size: .75rem; font-weight: 600; color: var(--color-text-secondary, #676664); }
        .pd-field__label { display: block; white-space: nowrap; }
        .pd-field .pd-opt { font-weight: 400; color: var(--color-text-muted, #6F6D67); }
        .pd-field input, .pd-field select { width: 100%; height: 2.5rem; padding: 0 .75rem; border: 1px solid var(--color-input-border, #E5E1DA); border-radius: .5rem; background: var(--color-input-bg, #fff); font-size: .8125rem; font-weight: 400; color: var(--color-text-primary, #262522); text-overflow: ellipsis; }
        .pd-field input:focus, .pd-field select:focus { outline: none; border-color: var(--color-focus-border, #B5524C); box-shadow: 0 0 0 3px var(--color-primary-border, #E4C1BF); }
        .pd-field--error input, .pd-field--error select { border-color: var(--color-status-danger, #EA5455); }
        .pd-field__error { font-size: .75rem; font-weight: 400; color: var(--color-status-danger, #EA5455); }
        .pd-invoice__foot { display: flex; justify-content: flex-end; gap: .625rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--color-border-subtle, #F2F0ED); }
        .pd-btn { height: 2.5rem; padding: 0 1rem; border-radius: .5rem; border: 1px solid var(--color-border-strong, #A29F9A); background: var(--color-surface, #fff); font-size: .875rem; font-weight: 600; color: var(--color-text-primary, #262522); cursor: pointer; }
        .pd-btn--primary { border-color: var(--color-primary, #B5524C); background: var(--color-primary, #B5524C); color: #fff; }
        .pd-btn--primary:hover { background: var(--color-primary-hover, #A83E38); }
        /* ---- documents ---- */
        .pd-docs { border-radius: .875rem; overflow: hidden; }
        .pd-docs__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: 1.125rem 1.25rem; }
        .pd-docs__title { font-size: 1rem; font-weight: 700; margin: 0; display: inline; }
        .pd-docs__count { margin-left: .5rem; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
        .pd-seg { display: inline-flex; gap: .125rem; padding: .1875rem; border-radius: .625rem; background: var(--color-border-subtle, #F2F0ED); }
        .pd-seg a { padding: .3125rem .875rem; border-radius: .5rem; font-size: .8125rem; color: var(--color-text-secondary, #676664); text-decoration: none; }
        .pd-seg a:hover { color: var(--color-text-primary, #262522); }
        .pd-seg a.is-active { background: var(--color-surface, #fff); color: var(--color-text-primary, #262522); font-weight: 600; box-shadow: 0 1px 2px var(--color-shadow-tint, rgba(38, 37, 34, .08)); }
        .pd-row, .pd-head { display: grid; grid-template-columns: minmax(0, 2.4fr) 6rem 6.5rem 6.5rem 7rem minmax(0, 1.4fr) 1.25rem; gap: 1rem; align-items: center; padding: .875rem 1.25rem; }
        .pd-head { font-size: .6875rem; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-muted, #6F6D67); border-top: 1px solid var(--color-border-neutral, #E5E1DA); border-bottom: 1px solid var(--color-border-neutral, #E5E1DA); background: var(--color-surface-secondary, #FBFAF7); padding-block: .625rem; }
        .pd-row { position: relative; border-bottom: 1px solid var(--color-border-subtle, #F2F0ED); transition: background .12s ease; font-size: .8125rem; }
        .pd-row:hover { background: var(--color-surface-secondary, #FBFAF7); }
        .pd-title-cell { display: flex; align-items: center; gap: .75rem; min-width: 0; }
        .pd-doc-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2.25rem; height: 2.25rem; border-radius: .5rem; background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-primary, #B5524C); }
        .pd-doc-icon--invoice { background: color-mix(in srgb, var(--color-status-success, #28C76F) 14%, #fff); color: color-mix(in srgb, var(--color-status-success, #28C76F) 70%, #000); }
        .pd-title { font-weight: 600; font-size: .875rem; color: inherit; text-decoration: none; overflow-wrap: anywhere; }
        .pd-title:hover { text-decoration: none; }
        .pd-title:focus-visible { outline: 2px solid var(--color-focus-ring, #B5524C); outline-offset: 2px; }
        .pd-type { display: inline-flex; align-items: center; padding: .1875rem .625rem; border-radius: 999px; font-size: .75rem; font-weight: 500; background: var(--color-primary-soft-bg, #F4E5E4); color: var(--color-link-hover, #A83E38); }
        .pd-type--invoice { background: color-mix(in srgb, var(--color-status-success, #28C76F) 14%, #fff); color: color-mix(in srgb, var(--color-status-success, #28C76F) 65%, #000); }
        .pd-amount { font-weight: 700; color: var(--color-text-primary, #262522); }
        .pd-muted { color: var(--color-text-muted, #6F6D67); font-weight: 400; }
        .pd-status .badge { display: inline-flex; align-items: center; gap: .3rem; }
        .pd-dot { width: .4rem; height: .4rem; border-radius: 50%; background: currentColor; }
        .pd-chev { color: var(--color-text-muted, #6F6D67); font-size: 1.25rem; }
        @media (max-width: 991.98px) {
            .pd-actions { grid-template-columns: 1fr; }
            .pd-invoice__fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 767.98px) {
            .pd-title-h { font-size: 1.5rem; }
            .pd-head { display: none; }
            .pd-row { grid-template-columns: minmax(0, 1fr) auto; row-gap: .375rem; padding: .875rem 1rem; }
            .pd-row .pd-title-cell { grid-column: 1 / -1; }
            .pd-row .pd-chev { grid-column: 2; grid-row: 4; }
            .pd-row > div[data-role] { grid-column: 1; }
            .pd-invoice { padding: 1rem; }
            .pd-invoice__fields { grid-template-columns: 1fr; }
            .pd-invoice__foot .pd-btn { flex: 1; }
            .pd-docs__head { padding-inline: 1rem; }
        }
    </style>
@endsection

@section('content')
<div class="mb-2">
    <h1 class="pd-title-h mb-25">Proposals and invoices</h1>
    <p class="text-caption mb-0" data-role="page-subtitle">Draft, send and keep track of what you've quoted and billed.</p>
</div>
<x-flash-alert />
@if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@php($invoiceOpen = old('kind') === 'invoice')

{{-- Contract 17B §7 — the focused New proposal flow: choose a Contact, then start
     blank. The modal below is server-rendered; resources/js/documents/editor/new-document.js
     drives it. "Use a template" goes to the existing template library, whose Use links come back
     here with ?use_template=. Invoices keep their existing form, unchanged. --}}
<div class="pd-actions" data-role="new-document-actions">
    <button type="button" class="pd-action pd-action--primary" data-role="new-proposal-open">
        <span class="pd-action__icon" aria-hidden="true"><x-ds-icon name="plus" size="20" /></span>
        <span class="pd-action__text"><span class="pd-action__title">New proposal</span><span class="pd-action__sub">Start from a blank proposal.</span></span>
        <span class="pd-action__end" aria-hidden="true"><x-ds-icon name="arrow-right" size="16" /></span>
    </button>
    <a class="pd-action" href="{{ route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid]) }}" data-role="templates-link">
        <span class="pd-action__icon" aria-hidden="true"><x-ds-icon name="layout-grid" size="20" /></span>
        <span class="pd-action__text"><span class="pd-action__title">Use a template</span><span class="pd-action__sub">Pick a saved template and fill in the details.</span></span>
        <span class="pd-action__end" aria-hidden="true"><x-ds-icon name="arrow-right" size="16" /></span>
    </a>
    <button type="button" class="pd-action {{ $invoiceOpen ? 'is-open' : '' }}" data-role="new-invoice-toggle" aria-expanded="{{ $invoiceOpen ? 'true' : 'false' }}" aria-controls="new-invoice-panel">
        <span class="pd-action__icon pd-action__icon--green" aria-hidden="true"><x-ds-icon name="credit-card" size="20" /></span>
        <span class="pd-action__text"><span class="pd-action__title">New invoice</span><span class="pd-action__sub">Bill a customer and get paid.</span></span>
        <span class="pd-action__end" aria-hidden="true"><x-ds-icon name="chevron-down" size="16" /></span>
    </button>
</div>

{{-- The invoice draft form: the same POST as before (kind=invoice, same fields, same CSRF),
     now an in-page panel under the cards. Server validation errors reopen it with the entered values. --}}
<section class="pd-invoice" id="new-invoice-panel" data-role="new-invoice" @unless($invoiceOpen) hidden @endunless aria-labelledby="new-invoice-title">
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.store', [$workspaceUid, $businessUid]) }}" data-role="new-invoice-form">
        @csrf
        <input type="hidden" name="kind" value="invoice">
        <div class="pd-invoice__head">
            <div>
                <h2 class="pd-invoice__title" id="new-invoice-title">New invoice</h2>
                <p class="pd-invoice__sub">Who are you billing? You'll add line items and amounts in the draft.</p>
            </div>
            <button type="button" class="pd-invoice__close" data-role="new-invoice-close" aria-label="Close"><x-ds-icon name="x" size="18" /></button>
        </div>
        @php($invoiceField = fn (string $name) => $invoiceOpen && isset($errors) && $errors->has($name))
        <div class="pd-invoice__fields">
            <label class="pd-field {{ $invoiceField('title') ? 'pd-field--error' : '' }}"><span class="pd-field__label">Title</span>
                <input name="title" required maxlength="200" placeholder="e.g. Glam Booth — Oct 17 wedding" value="{{ $invoiceOpen ? old('title') : '' }}">
                @if($invoiceField('title'))<span class="pd-field__error">{{ $errors->first('title') }}</span>@endif
            </label>
            <label class="pd-field {{ $invoiceField('contact_uid') ? 'pd-field--error' : '' }}"><span class="pd-field__label">Customer</span>
                <select name="contact_uid" required>@foreach($contacts as $contact)<option value="{{ $contact->uid }}" @selected($invoiceOpen && old('contact_uid') === $contact->uid)>{{ $contact->phone }} ({{ optional($locations->firstWhere("id", $contact->location_id))->name }})</option>@endforeach</select>
                @if($invoiceField('contact_uid'))<span class="pd-field__error">{{ $errors->first('contact_uid') }}</span>@endif
            </label>
            <label class="pd-field {{ $invoiceField('location_uid') ? 'pd-field--error' : '' }}"><span class="pd-field__label">Location</span>
                <select name="location_uid" required>@foreach($locations as $location)<option value="{{ $location->uid }}" @selected($invoiceOpen && old('location_uid') === $location->uid)>{{ $location->name }}</option>@endforeach</select>
                @if($invoiceField('location_uid'))<span class="pd-field__error">{{ $errors->first('location_uid') }}</span>@endif
            </label>
            <label class="pd-field {{ $invoiceField('opportunity_uid') ? 'pd-field--error' : '' }}"><span class="pd-field__label">Opportunity <span class="pd-opt">(optional)</span></span>
                <select name="opportunity_uid"><option value="">None</option>@foreach($opportunities as $opportunity)<option value="{{ $opportunity->uid }}" @selected($invoiceOpen && old('opportunity_uid') === $opportunity->uid)>{{ $opportunity->title }}</option>@endforeach</select>
                @if($invoiceField('opportunity_uid'))<span class="pd-field__error">{{ $errors->first('opportunity_uid') }}</span>@endif
            </label>
        </div>
        <div class="pd-invoice__foot">
            <button type="button" class="pd-btn" data-role="new-invoice-cancel">Cancel</button>
            <button type="submit" class="pd-btn pd-btn--primary">Create invoice draft</button>
        </div>
    </form>
</section>

<div class="de-newdoc" data-role="new-proposal" data-use-template="{{ $useTemplateUid }}" data-search-url="{{ route('customer.workspaces.businesses.documents.editor.contacts.search', [$workspaceUid, $businessUid]) }}">
    <div class="de-modal" data-role="new-proposal-modal" hidden>
        <form class="de-modal__dialog de-modal__dialog--md" method="post" action="{{ route('customer.workspaces.businesses.documents.store', [$workspaceUid, $businessUid]) }}" data-role="new-proposal-form" role="dialog" aria-modal="true" aria-labelledby="np-title">
            @csrf
            <input type="hidden" name="kind" value="proposal">
            <input type="hidden" name="via" value="editor">
            <input type="hidden" name="contact_uid" value="" data-role="np-contact">
            <div class="de-modal__head">
                <h2 class="de-modal__title" id="np-title">New proposal</h2>
                <span class="de-wizard__step" data-role="np-step">Step 1 of 2</span>
                <button type="button" class="de-iconbtn" data-role="np-cancel" aria-label="Close">&times;</button>
            </div>
            <div class="de-modal__body">
                <section data-step="1">
                    <label class="de-field__label" for="np-search">Who is this proposal for?</label>
                    <input id="np-search" class="de-input" type="search" autocomplete="off" placeholder="Search your contacts by name or phone" data-role="np-search">
                    <div class="de-picklist mt-1" data-role="np-results" aria-live="polite"></div>
                </section>
                <section data-step="2" hidden>
                    <div class="de-summary"><span>For</span><strong data-role="np-chosen"></strong></div>
                    <label class="de-field">
                        <span class="de-field__label">Title</span>
                        <input class="de-input" type="text" name="title" maxlength="200" value="Untitled proposal" data-role="np-title" required>
                    </label>
                    <div class="de-field__label">Start from</div>
                    {{-- Contract 17B §6 — a template brings the layout only. You chose the contact above and add the product after. --}}
                    <div data-role="template-slot">
                        <div class="de-radios">
                            <label class="de-radio is-checked"><input type="radio" name="template_uid" value="" checked data-role="np-template" data-template-start="blank"><span><strong>Blank document</strong><small>Add text, products, payment terms and a signature yourself.</small></span></label>
                        </div>
                        <div class="de-field__label" data-role="np-my-templates-label">My templates</div>
                        <div class="de-radios" data-role="np-my-templates">
                            @forelse($myTemplates as $template)
                                @php($text = $templateSnippet($template))
                                <label class="de-radio" data-role="np-template-card" data-template-uid="{{ $template->uid }}">
                                    <input type="radio" name="template_uid" value="{{ $template->uid }}" data-role="np-template" @checked($useTemplateUid === $template->uid)>
                                    <span>
                                        <strong>{{ $template->name }} <span class="de-chip">{{ $template->template_type->value === 'contract' ? 'Contract' : 'Proposal' }}</span></strong>
                                        @if($template->description)<small>{{ $template->description }}</small>@endif
                                        @if($text !== '')<small class="de-template-snippet" data-role="np-template-snippet">{{ $text }}</small>@endif
                                        <small>Use template: keeps the layout, not the product or contact.</small>
                                    </span>
                                </label>
                            @empty
                                <div class="de-muted" data-role="np-my-templates-empty">No saved templates yet. Open any proposal and choose Save as template to reuse its layout.</div>
                            @endforelse
                        </div>
                        @if($recommendedTemplates->isNotEmpty())
                            <div class="de-field__label" data-role="np-recommended-label">Recommended for your business</div>
                            <div class="de-radios" data-role="np-recommended">
                                @foreach($recommendedTemplates as $template)
                                    @php($text = $templateSnippet($template))
                                    <label class="de-radio" data-role="np-template-card" data-template-uid="{{ $template->uid }}">
                                        <input type="radio" name="template_uid" value="{{ $template->uid }}" data-role="np-template" @checked($useTemplateUid === $template->uid)>
                                        <span>
                                            <strong>{{ $template->name }} <span class="de-chip">{{ $template->template_type->value === 'contract' ? 'Contract' : 'Proposal' }}</span></strong>
                                            @if($template->description)<small>{{ $template->description }}</small>@endif
                                            @if($text !== '')<small class="de-template-snippet" data-role="np-template-snippet">{{ $text }}</small>@endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        <a class="de-link" href="{{ route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid]) }}" data-role="np-manage-templates">Manage templates</a>
                    </div>
                </section>
            </div>
            <div class="de-modal__footer">
                <button type="button" class="de-btn" data-role="np-cancel">Cancel</button>
                <button type="button" class="de-btn" data-role="np-back" hidden>Back</button>
                <button type="button" class="de-btn de-btn--primary" data-role="np-next" disabled>Continue</button>
                <button type="submit" class="de-btn de-btn--primary" data-role="np-submit" hidden>Create proposal</button>
            </div>
        </form>
    </div>
</div>

<?php
    // All / Proposals / Invoices narrow by kind; Sent / Drafts by state. Anything else means All.
    $filters = [
        '' => ['All', null],
        'proposal' => ['Proposals', 'kind'],
        'invoice' => ['Invoices', 'kind'],
        'sent' => ['Sent', 'state'],
        'draft' => ['Drafts', 'state'],
    ];
    $currentFilter = $currentFilter ?? '';
    $statusVariant = fn ($s) => match ($s) { 'paid', 'signed' => 'success', 'sent' => 'info', 'expired', 'void' => 'warning', default => 'neutral' };
    $money = function ($version) {
        if ($version === null || (int) $version->total_minor <= 0) {
            return null;
        }
        $major = $version->total_minor / 100;

        return $version->currency_code . ' ' . number_format($major, floor($major) == $major ? 0 : 2);
    };
?>
<x-card :padded="false" class="pd-docs mt-2 mb-2" data-section="documents">
    <div class="pd-docs__head">
        <div>
            <h2 class="pd-docs__title">Your documents</h2>
            <span class="pd-docs__count" data-role="documents-count">{{ $documentCounts['all'] }} {{ $documentCounts['all'] === 1 ? 'document' : 'documents' }} · {{ $documentCounts['sent'] }} sent</span>
        </div>
        <nav class="pd-seg" aria-label="Filter documents" data-role="document-filter">
            @foreach($filters as $value => [$label, $param])
                <a href="{{ $param === null ? route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]) : route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid, $param => $value]) }}" class="{{ $currentFilter === $value ? 'is-active' : '' }}" @if($currentFilter === $value) aria-current="page" @endif data-filter="{{ $value === '' ? 'all' : $value }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    @forelse($documents as $document)
        @if($loop->first)
            <div class="pd-head" aria-hidden="true"><span>Title</span><span>Type</span><span>Status</span><span>Amount</span><span>Sent</span><span>Location</span><span></span></div>
        @endif
        <?php
            $opensInEditor = in_array($document->uid, $editorUids ?? [], true);
            $status = $document->status->value;
            $kind = $document->kind->value;
            $amount = $money($document->currentVersion);
        ?>
        <div class="pd-row" data-role="document-row" data-document-uid="{{ $document->uid }}" data-kind="{{ $kind }}" data-status="{{ $status }}">
            <div class="pd-title-cell">
                <span class="pd-doc-icon {{ $kind === 'invoice' ? 'pd-doc-icon--invoice' : '' }}" aria-hidden="true"><x-ds-icon name="{{ $kind === 'invoice' ? 'credit-card' : 'file-text' }}" size="18" /></span>
                <a class="pd-title stretched-link" href="{{ route($opensInEditor ? 'customer.workspaces.businesses.documents.editor.edit' : 'customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]) }}" data-role="document-link" data-opens="{{ $opensInEditor ? 'editor' : 'page' }}">{{ $document->title }}</a>
            </div>
            <div class="pd-meta">
                <span class="pd-type {{ $kind === 'invoice' ? 'pd-type--invoice' : '' }}" data-role="document-kind">{{ ucfirst($kind) }}</span>
            </div>
            <div class="pd-status"><x-badge :variant="$statusVariant($status)" data-role="document-status">@if($status === 'draft')<span class="pd-dot" aria-hidden="true"></span>@elseif($status === 'sent')<x-ds-icon name="send" size="12" />@endif{{ ucfirst(str_replace('_', ' ', $status)) }}</x-badge></div>
            <div class="pd-amount" data-role="document-amount">@if($amount !== null){{ $amount }}@else<span class="pd-muted">—</span>@endif</div>
            <div class="pd-muted" data-role="document-sent">{{ $document->sent_at ? $document->sent_at->diffForHumans() : 'Not sent' }}</div>
            <div class="pd-loc" data-role="document-location">{{ $document->businessLocation?->name ?? '—' }}</div>
            <span class="pd-chev" aria-hidden="true">&rsaquo;</span>
        </div>
    @empty
        <div class="px-2 pb-3 pt-1 text-center" data-role="no-documents">
            <p class="mb-1 text-muted">{{ $currentFilter === '' ? 'No proposals or invoices yet.' : 'No ' . strtolower($filters[$currentFilter][0]) . ' yet.' }}</p>
            <button type="button" class="btn btn-primary" data-role="new-proposal-open">New proposal</button>
        </div>
    @endforelse

    @if($documents->hasPages())<div class="px-2 py-1">{{ $documents->links() }}</div>@endif
    <div class="px-2 py-1 border-top"><span class="text-caption">Drafts stay private until you send them.</span></div>
</x-card>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/documents/new-document.js')) }}"></script>
    <script>
        // New invoice: the card opens / closes the draft form; x, Cancel and the card itself close it.
        document.addEventListener('DOMContentLoaded', function () {
            var toggle = document.querySelector('[data-role="new-invoice-toggle"]');
            var panel = document.querySelector('[data-role="new-invoice"]');
            if (!toggle || !panel) { return; }
            function set(open) {
                panel.hidden = !open;
                toggle.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) { var first = panel.querySelector('input[name="title"]'); if (first) { first.focus({ preventScroll: true }); } }
            }
            toggle.addEventListener('click', function () { set(panel.hidden); });
            panel.querySelectorAll('[data-role="new-invoice-close"], [data-role="new-invoice-cancel"]').forEach(function (b) {
                b.addEventListener('click', function () { set(false); toggle.focus(); });
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.DocumentNewProposal) {
                window.DocumentNewProposal.init(document.querySelector('[data-role="new-proposal"]'));
            }
        });
    </script>
@endsection
