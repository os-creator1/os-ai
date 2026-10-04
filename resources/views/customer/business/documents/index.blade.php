@extends('layouts/contentLayoutMaster')
@section('title', 'Documents')

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/documents-editor.css')) }}">
@endsection

@section('content')
<h4>Proposals and invoices</h4>
<x-flash-alert />
@if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

{{-- Contract 17B §7 — the focused New proposal flow: choose a Contact, then start
     blank. The modal below is server-rendered; resources/js/documents/editor/new-document.js
     drives it. Invoices keep their existing form, unchanged. --}}
<div class="mb-2 d-flex flex-wrap gap-1" data-role="new-document-actions">
    <button type="button" class="btn btn-primary" data-role="new-proposal-open">New proposal</button>
    <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid]) }}" data-role="templates-link">Templates</a>
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
<div class="card p-2">
    @forelse($documents as $document)
        @php($opensInEditor = in_array($document->uid, $editorUids ?? [], true))
        <p data-role="document-row" data-document-uid="{{ $document->uid }}"><a href="{{ route($opensInEditor ? 'customer.workspaces.businesses.documents.editor.edit' : 'customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]) }}" data-role="document-link" data-opens="{{ $opensInEditor ? 'editor' : 'page' }}">{{ $document->title }}</a> — {{ $document->kind->value }} — {{ $document->status->value }}@if($document->businessLocation) — {{ $document->businessLocation->name }}@endif
            @if($document->currentVersion) — {{ number_format($document->currentVersion->total_minor / 100, 2) }} {{ $document->currentVersion->currency_code }} @endif
            @if($document->sent_at) — sent {{ $document->sent_at->format('j M Y') }} @endif
            @if($document->signed_at) — signed {{ $document->signed_at->format('j M Y') }} @endif
            @if($document->paid_at) — paid {{ $document->paid_at->format('j M Y') }} @endif</p>
    @empty<p>No documents yet.</p>@endforelse
    {{ $documents->links() }}
</div>
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
