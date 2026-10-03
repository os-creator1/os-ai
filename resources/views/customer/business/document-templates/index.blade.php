@extends('layouts/contentLayoutMaster')
@section('title', 'Templates')

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/documents-editor.css')) }}">
@endsection

@section('content')
{{--
    Contract 17B §6 — the template library. A template is a LAYOUT: text, headings,
    your images, merge fields, where the signature goes and a generic product area.
    It never holds a Contact, a product, a price or payment terms. Everything below
    is server-rendered; there is no script on this page.
--}}
@php
    $base = [$workspaceUid, $businessUid];
    $typeLabel = ['proposal' => 'Proposal', 'contract' => 'Contract'];
    $rows = $showArchived ? $templates->concat($archived) : $templates;
@endphp
<div class="de-newdoc dt-library" data-role="template-library">
    <div class="dt-head">
        <div>
            <h4 class="mb-0">Proposal &amp; contract templates</h4>
            <p class="de-muted mb-0">A template saves the layout, not the product or contact. When you use one, you pick the contact and add the product again.</p>
        </div>
        <div class="dt-head__actions">
            <a class="de-btn" href="{{ $documentsUrl }}" data-role="templates-back">Back to documents</a>
            <form method="post" action="{{ route('customer.workspaces.businesses.document-templates.create', $base) }}" class="dt-new" data-role="template-create-form">
                @csrf
                <input class="de-input" type="text" name="name" maxlength="191" placeholder="New template name" aria-label="New template name">
                <select class="de-input" name="template_type" aria-label="Template type">
                    <option value="proposal">Proposal</option>
                    <option value="contract">Contract</option>
                </select>
                <button type="submit" class="de-btn de-btn--primary" data-role="template-create">New blank template</button>
            </form>
        </div>
    </div>

    <x-flash-alert />
    @if(isset($errors) && $errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <section class="dt-section" data-role="my-templates" aria-labelledby="dt-mine">
        <div class="dt-section__head">
            <h5 id="dt-mine">My templates</h5>
            @if($archived->count() > 0 || $showArchived)
                <a class="de-link" data-role="toggle-archived" href="{{ route('customer.workspaces.businesses.document-templates.index', $base) }}{{ $showArchived ? '' : '?archived=1' }}">{{ $showArchived ? 'Hide archived' : 'Show archived (' . $archived->count() . ')' }}</a>
            @endif
        </div>
        @if($rows->isEmpty())
            <div class="dt-empty" data-role="my-templates-empty">
                <strong>No templates yet.</strong>
                <span class="de-muted">Open any proposal and choose Save as template, or start a blank template above.</span>
            </div>
        @else
            <div class="dt-grid">
                @foreach($rows as $template)
                    @php($isArchived = $template->status->value === 'archived')
                    @php($text = $snippet($template))
                    <article class="dt-card{{ $isArchived ? ' is-archived' : '' }}" data-role="template-card" data-template-uid="{{ $template->uid }}" data-status="{{ $template->status->value }}">
                        <div class="dt-card__top">
                            <span class="de-chip dt-badge dt-badge--{{ $template->template_type->value }}" data-role="template-type">{{ $typeLabel[$template->template_type->value] }}</span>
                            @if($isArchived)<span class="de-chip de-chip--void" data-role="template-archived">Archived</span>@endif
                        </div>
                        <h6 class="dt-card__name" data-role="template-name">{{ $template->name }}</h6>
                        @if($template->description)<p class="dt-card__desc">{{ $template->description }}</p>@endif
                        @if($text !== '')<p class="dt-card__snippet de-muted" data-role="template-snippet">{{ $text }}</p>@endif
                        <div class="dt-card__meta de-muted">Updated {{ $template->updated_at?->format('j M Y') }}</div>
                        <div class="dt-card__actions">
                            @unless($isArchived)
                                <a class="de-btn de-btn--primary de-btn--sm" data-role="template-use" href="{{ $documentsUrl }}?use_template={{ $template->uid }}">Use</a>
                                <a class="de-btn de-btn--sm" data-role="template-edit" href="{{ route('customer.workspaces.businesses.document-templates.edit', [...$base, $template->uid]) }}">Edit</a>
                            @endunless
                            <a class="de-btn de-btn--sm" data-role="template-preview" target="_blank" rel="noopener" href="{{ route('customer.workspaces.businesses.document-templates.preview', [...$base, $template->uid]) }}">Preview</a>
                            <form method="post" action="{{ route('customer.workspaces.businesses.document-templates.duplicate', [...$base, $template->uid]) }}">@csrf<button type="submit" class="de-btn de-btn--sm" data-role="template-duplicate">Duplicate</button></form>
                            @if($isArchived)
                                <form method="post" action="{{ route('customer.workspaces.businesses.document-templates.restore', [...$base, $template->uid]) }}">@csrf<button type="submit" class="de-btn de-btn--sm" data-role="template-restore">Restore</button></form>
                            @else
                                <form method="post" action="{{ route('customer.workspaces.businesses.document-templates.archive', [...$base, $template->uid]) }}">@csrf<button type="submit" class="de-btn de-btn--sm" data-role="template-archive">Archive</button></form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="dt-section" data-role="recommended-templates" aria-labelledby="dt-rec">
        <div class="dt-section__head"><h5 id="dt-rec">Recommended for your business</h5></div>
        @if($recommended->isEmpty())
            <div class="dt-empty" data-role="recommended-empty">
                <span class="de-muted">No recommended templates for your business yet.</span>
            </div>
        @else
            <div class="dt-grid">
                @foreach($recommended as $template)
                    <article class="dt-card" data-role="recommended-card" data-template-uid="{{ $template->uid }}">
                        <div class="dt-card__top"><span class="de-chip dt-badge dt-badge--{{ $template->template_type->value }}">{{ $typeLabel[$template->template_type->value] }}</span></div>
                        <h6 class="dt-card__name">{{ $template->name }}</h6>
                        @if($template->description)<p class="dt-card__desc">{{ $template->description }}</p>@endif
                        <div class="dt-card__actions">
                            <a class="de-btn de-btn--primary de-btn--sm" data-role="template-use" href="{{ $documentsUrl }}?use_template={{ $template->uid }}">Use</a>
                            <a class="de-btn de-btn--sm" target="_blank" rel="noopener" href="{{ route('customer.workspaces.businesses.document-templates.preview', [...$base, $template->uid]) }}">Preview</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
