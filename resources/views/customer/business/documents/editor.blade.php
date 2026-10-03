@php
    // The editor draws its own focused header; the layout's title row would only repeat it.
    $pageConfigs = ['pageHeader' => false];
    $status = $bootstrap['document']['status'];
    $statusLabel = ['draft' => 'Draft', 'sent' => 'Sent', 'signed' => 'Signed', 'void' => 'Voided', 'paid' => 'Paid', 'expired' => 'Expired'][$status] ?? ucfirst($status);
    $editable = (bool) $bootstrap['editable'];
    $isLegacy = (bool) $bootstrap['is_legacy'];
    $readonlyNotice = match (true) {
        $status === 'sent' => 'This document has been sent, so it can no longer be edited here.',
        $status === 'signed' => 'This document has been signed and is locked.',
        $status === 'paid' => 'This document has been paid and is locked.',
        $status === 'void' => 'This document was voided and is locked.',
        $status === 'expired' => 'This document has expired and is locked.',
        default => 'This document can no longer be edited.',
    };
@endphp
@extends('layouts/contentLayoutMaster')
@section('title', 'Edit document')

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/documents-editor.css')) }}">
@endsection

@section('content')
{{-- Contract 17B — the visual editor shell. Everything dynamic is drawn by
     resources/js/documents/editor from the bootstrap JSON below; the toolbox,
     icons, status chip, legacy and read-only notices are server-rendered so
     they exist before (and without) the script. --}}
@include('documents.blocks._styles')
<div class="de" id="document-editor" data-role="document-editor" data-editable="{{ $editable ? '1' : '0' }}" data-legacy="{{ $isLegacy ? '1' : '0' }}">
    <script type="application/json" id="document-editor-bootstrap">{!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @foreach ($icons as $iconName)
        <template id="de-icon-{{ $iconName }}"><x-ds-icon :name="$iconName" size="16" /></template>
    @endforeach

    <header class="de-header" data-role="editor-header">
        <div class="de-header__start">
            <a class="de-iconbtn" href="{{ $bootstrap['urls']['index'] }}" data-role="editor-back" title="Back to documents" aria-label="Back to documents"><x-ds-icon name="arrow-left" size="18" /></a>
            <button type="button" class="de-iconbtn de-only-narrow" data-role="toolbox-toggle" aria-controls="de-toolbox" aria-expanded="false" title="Blocks" aria-label="Show blocks"><x-ds-icon name="panel-left" size="18" /></button>
            <div class="de-titlewrap">
                <input class="de-title" type="text" maxlength="200" value="{{ $document->title }}" aria-label="Document title" data-role="editor-title" @disabled(! $editable)>
                <div class="de-subline">
                    <span class="de-chip de-chip--{{ $status }}" data-role="editor-status">{{ $statusLabel }}</span>
                    @if(($bootstrap['contact']['name'] ?? '') !== '')<span class="de-contact" data-role="editor-contact" title="This document is for this contact">For {{ $bootstrap['contact']['name'] }}</span>@endif
                    <span class="de-save" data-role="save-indicator" data-state="saved" role="status" aria-live="polite"></span>
                </div>
            </div>
        </div>
        <div class="de-header__end">
            @unless($isLegacy)
            <button type="button" class="de-btn" data-role="action-preview"><x-ds-icon name="eye" size="15" /><span>Preview</span></button>
            @endunless
            @if($editable && ! $isLegacy)
                <button type="button" class="de-btn" data-role="action-save"><x-ds-icon name="save" size="15" /><span>Save</span></button>
                <button type="button" class="de-btn de-hide-narrow" data-role="action-save-template" disabled title="Coming with templates" aria-disabled="true"><x-ds-icon name="file-plus" size="15" /><span>Save as template</span></button>
                <button type="button" class="de-btn de-btn--primary" data-role="action-send"><x-ds-icon name="send" size="15" /><span>Send</span></button>
            @endif
            <div class="de-more">
                <button type="button" class="de-iconbtn" data-role="action-more" aria-haspopup="true" aria-expanded="false" title="More" aria-label="More actions"><x-ds-icon name="ellipsis" size="18" /></button>
                <div class="de-menu" data-role="more-menu" hidden>
                    <a href="{{ $bootstrap['urls']['show'] }}" data-role="more-classic">Open classic page</a>
                    <a href="{{ $bootstrap['urls']['index'] }}">All documents</a>
                </div>
            </div>
        </div>
    </header>

    <div class="de-banners" data-role="editor-banners" role="alert">
        @unless($editable)
            <div class="de-banner de-banner--info" data-role="readonly-banner">{{ $readonlyNotice }} <a href="{{ $bootstrap['urls']['show'] }}">Open the document page</a></div>
        @endunless
    </div>

    @if($isLegacy)
        <section class="de-legacy" data-role="legacy-upgrade">
            <h2>Upgrade to the visual editor</h2>
            @if($bootstrap['can_upgrade'])
                <p>This document was written in the classic text editor. Upgrade it to edit it block by block, with products, payment terms and a signature you can place yourself. Your original text is kept.</p>
                <div class="de-legacy__actions">
                    <button type="button" class="de-btn de-btn--primary" data-role="legacy-upgrade-button">Upgrade to the visual editor</button>
                    <a class="de-btn" href="{{ $bootstrap['urls']['show'] }}" data-role="legacy-classic-link">Keep using the classic page</a>
                </div>
                <p class="de-legacy__error" data-role="legacy-error" hidden></p>
            @else
                <p>This document was written in the classic text editor and can no longer be changed.</p>
                <div class="de-legacy__actions"><a class="de-btn de-btn--primary" href="{{ $bootstrap['urls']['show'] }}" data-role="legacy-classic-link">Open the document page</a></div>
            @endif
        </section>
    @else
        <div class="de-body" data-role="editor-body">
            <aside class="de-toolbox" id="de-toolbox" data-role="toolbox" aria-label="Blocks">
                <div class="de-toolbox__head de-only-narrow"><strong>Blocks</strong><button type="button" class="de-iconbtn" data-role="toolbox-close" aria-label="Close blocks"><x-ds-icon name="x" size="16" /></button></div>
                @foreach($toolbox as $category)
                    <section class="de-toolbox__group" data-category="{{ $category['id'] }}">
                        <h3>{{ $category['label'] }}</h3>
                        <div class="de-toolbox__items">
                            @foreach($category['items'] as $item)
                                <button type="button" class="de-tool" draggable="{{ $editable ? 'true' : 'false' }}" data-tool="{{ $item['id'] }}" data-block-type="{{ $item['type'] }}" @disabled(! $editable) title="{{ $item['label'] }}">
                                    <x-ds-icon :name="$item['icon']" size="16" /><span>{{ $item['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </aside>
            <div class="de-scrim" data-role="toolbox-scrim" hidden></div>
            <main class="de-stage" data-role="editor-stage">
                <div class="de-canvas-scroll">
                    <div class="de-canvas doc-blocks" id="de-canvas" data-role="editor-canvas" data-mode="editor" tabindex="-1" aria-label="Document"></div>
                </div>
            </main>
            <aside class="de-inspector" data-role="inspector" aria-label="Block settings" hidden></aside>
        </div>
    @endif
    <div class="de-modal-root" data-role="modal-root"></div>
</div>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/documents/editor.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.DocumentEditor && window.DocumentEditor.init) {
                window.DocumentEditor.init(document.getElementById('document-editor'));
            }
        });
    </script>
@endsection
