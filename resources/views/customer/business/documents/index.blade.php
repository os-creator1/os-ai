@extends('layouts/contentLayoutMaster')
@section('title', 'Documents')

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/documents-editor.css')) }}">
    <style>
        .pd-action { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.5rem; border-radius: .75rem; border: 1px solid var(--bs-border-color, #e5e5e5); background: var(--bs-body-bg, #fff); color: inherit; text-align: left; width: 100%; text-decoration: none; transition: box-shadow .15s ease, transform .15s ease, border-color .15s ease; }
        .pd-action:hover, .pd-action:focus-visible { text-decoration: none; box-shadow: 0 6px 18px rgba(34, 41, 47, .12); transform: translateY(-1px); outline: none; }
        .pd-action:focus-visible { box-shadow: 0 0 0 3px var(--bs-primary-border-subtle, rgba(115, 103, 240, .35)); }
        .pd-action--primary { background: var(--bs-primary, #7367f0); border-color: var(--bs-primary, #7367f0); color: #fff; }
        .pd-action--primary:hover, .pd-action--primary:focus-visible { color: #fff; }
        .pd-action__title { font-size: 1.125rem; font-weight: 600; display: block; }
        .pd-action__sub { font-size: .875rem; opacity: .8; display: block; }
        .pd-action__arrow { font-size: 1.5rem; line-height: 1; flex: none; }
        .pd-seg { display: inline-flex; border: 1px solid var(--bs-border-color, #e5e5e5); border-radius: .5rem; overflow: hidden; }
        .pd-seg a { padding: .35rem .85rem; font-size: .875rem; color: inherit; text-decoration: none; }
        .pd-seg a + a { border-left: 1px solid var(--bs-border-color, #e5e5e5); }
        .pd-seg a.is-active { background: var(--bs-primary-bg-subtle, #f1eefe); color: var(--bs-primary, #7367f0); font-weight: 600; }
        .pd-row, .pd-head { display: grid; grid-template-columns: minmax(0, 1fr) 7rem 7rem 11rem 1.5rem; gap: 1rem; align-items: center; padding: .9rem 1.5rem; }
        .pd-head { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color, #6e6b7b); border-bottom: 1px solid var(--bs-border-color, #e5e5e5); background: var(--bs-tertiary-bg, #f8f8f8); }
        .pd-row { position: relative; border-bottom: 1px solid var(--bs-border-color, #eee); transition: background .12s ease; }
        .pd-row:last-child { border-bottom: 0; }
        .pd-row:hover { background: var(--bs-tertiary-bg, #f8f8f8); }
        .pd-title { font-weight: 600; font-size: 1rem; color: inherit; text-decoration: none; overflow-wrap: anywhere; }
        .pd-title:hover { text-decoration: none; }
        .pd-title:focus-visible { outline: 2px solid var(--bs-primary, #7367f0); outline-offset: 2px; }
        .pd-sub { display: block; font-size: .8125rem; color: var(--bs-secondary-color, #6e6b7b); }
        .pd-chev { color: var(--bs-secondary-color, #6e6b7b); font-size: 1.25rem; }
        @media (max-width: 767.98px) {
            .pd-head { display: none; }
            .pd-row { grid-template-columns: minmax(0, 1fr) auto; row-gap: .35rem; padding: .9rem 1rem; }
            .pd-row .pd-title-cell { grid-column: 1 / -1; }
            .pd-row .pd-meta { grid-column: 1 / 2; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
            .pd-row .pd-chev { grid-column: 2; grid-row: 2; }
            .pd-row .pd-loc { grid-column: 1 / -1; }
        }
    </style>
@endsection

@section('content')
<div class="mb-2">
    <h4 class="mb-25">Proposals and invoices</h4>
    <p class="text-caption mb-0" data-role="page-subtitle">Draft, send and keep track of what you've quoted and billed.</p>
</div>
<x-flash-alert />
@if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

{{-- Contract 17B §7 — the focused New proposal flow: choose a Contact, then start
     blank. The modal below is server-rendered; resources/js/documents/editor/new-document.js
     drives it. "Use a template" goes to the existing template library, whose Use links come back
     here with ?use_template=. Invoices keep their existing form, unchanged. --}}
<div class="row g-1 mb-2" data-role="new-document-actions">
    <div class="col-md-6">
        <button type="button" class="pd-action pd-action--primary" data-role="new-proposal-open">
            <span><span class="pd-action__title">New proposal</span><span class="pd-action__sub">Start from a blank proposal.</span></span>
            <span class="pd-action__arrow" aria-hidden="true">&rarr;</span>
        </button>
    </div>
    <div class="col-md-6">
        <a class="pd-action" href="{{ route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid]) }}" data-role="templates-link">
            <span><span class="pd-action__title">Use a template</span><span class="pd-action__sub">Pick a saved template and fill in the details.</span></span>
            <span class="pd-action__arrow" aria-hidden="true">&rarr;</span>
        </a>
    </div>
</div>

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
    $filters = ['' => 'All', 'proposal' => 'Proposals', 'invoice' => 'Invoices'];
    $currentKind = $kindFilter ?? '';
    $statusVariant = fn ($s) => match ($s) { 'paid', 'signed' => 'success', 'sent' => 'accent', 'expired', 'void' => 'warning', default => 'neutral' };
?>
<x-card :padded="false" class="mb-2" data-section="documents">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 px-2 pt-2 pb-1">
        <div>
            <p class="text-section-heading mb-0">Your documents</p>
            <span class="text-caption" data-role="documents-count">{{ $documents->total() }} {{ $documents->total() === 1 ? 'document' : 'documents' }}</span>
        </div>
        <nav class="pd-seg" aria-label="Filter documents" data-role="document-filter">
            @foreach($filters as $value => $label)
                <a href="{{ $value === '' ? route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]) : route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid, 'kind' => $value]) }}" class="{{ $currentKind === $value ? 'is-active' : '' }}" @if($currentKind === $value) aria-current="page" @endif data-filter="{{ $value === '' ? 'all' : $value }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    @forelse($documents as $document)
        @if($loop->first)
            <div class="pd-head" aria-hidden="true"><span>Title</span><span>Type</span><span>Status</span><span>Location</span><span></span></div>
        @endif
        <?php
            $opensInEditor = in_array($document->uid, $editorUids ?? [], true);
            $status = $document->status->value;
            $kind = $document->kind->value;
            $subline = collect([
                $document->currentVersion ? number_format($document->currentVersion->total_minor / 100, 2) . ' ' . $document->currentVersion->currency_code : null,
                $document->paid_at ? 'Paid ' . $document->paid_at->format('j M Y') : ($document->signed_at ? 'Signed ' . $document->signed_at->format('j M Y') : ($document->sent_at ? 'Sent ' . $document->sent_at->format('j M Y') : null)),
            ])->filter()->implode(' · ');
        ?>
        <div class="pd-row" data-role="document-row" data-document-uid="{{ $document->uid }}" data-kind="{{ $kind }}" data-status="{{ $status }}">
            <div class="pd-title-cell">
                <a class="pd-title stretched-link" href="{{ route($opensInEditor ? 'customer.workspaces.businesses.documents.editor.edit' : 'customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]) }}" data-role="document-link" data-opens="{{ $opensInEditor ? 'editor' : 'page' }}">{{ $document->title }}</a>
                @if($subline !== '')<span class="pd-sub">{{ $subline }}</span>@endif
            </div>
            <div class="pd-meta">
                <span data-role="document-kind">{{ ucfirst($kind) }}</span>
            </div>
            <div><x-badge :variant="$statusVariant($status)" data-role="document-status">{{ ucfirst(str_replace('_', ' ', $status)) }}</x-badge></div>
            <div class="pd-loc text-caption" data-role="document-location">{{ $document->businessLocation?->name ?? '—' }}</div>
            <span class="pd-chev" aria-hidden="true">&rsaquo;</span>
        </div>
    @empty
        <div class="px-2 pb-3 pt-1 text-center" data-role="no-documents">
            <p class="mb-1 text-muted">{{ $currentKind === '' ? 'No proposals or invoices yet.' : 'No ' . strtolower($filters[$currentKind]) . ' yet.' }}</p>
            <button type="button" class="btn btn-primary" data-role="new-proposal-open">New proposal</button>
        </div>
    @endforelse

    @if($documents->hasPages())<div class="px-2 py-1">{{ $documents->links() }}</div>@endif
    <div class="px-2 py-1 border-top"><span class="text-caption">Drafts stay private until you send them.</span></div>
</x-card>

<details class="card p-2 mb-2" data-role="new-invoice">
    <summary class="h5 mb-0">New invoice</summary>
    <form method="post" action="{{ route('customer.workspaces.businesses.documents.store', [$workspaceUid, $businessUid]) }}" class="mt-1">
        @csrf
        <input type="hidden" name="kind" value="invoice">
        <label>Title <input name="title" required maxlength="200"></label>
        <label>Location <select name="location_uid" required>@foreach($locations as $location)<option value="{{ $location->uid }}">{{ $location->name }}</option>@endforeach</select></label>
        <label>Customer <select name="contact_uid" required>@foreach($contacts as $contact)<option value="{{ $contact->uid }}">{{ $contact->phone }} ({{ optional($locations->firstWhere("id", $contact->location_id))->name }})</option>@endforeach</select></label>
        <label>Opportunity (optional) <select name="opportunity_uid"><option value="">None</option>@foreach($opportunities as $opportunity)<option value="{{ $opportunity->uid }}">{{ $opportunity->title }}</option>@endforeach</select></label>
        <button class="btn btn-primary" type="submit">Create draft</button>
    </form>
</details>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/documents/new-document.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.DocumentNewProposal) {
                window.DocumentNewProposal.init(document.querySelector('[data-role="new-proposal"]'));
            }
        });
    </script>
@endsection
