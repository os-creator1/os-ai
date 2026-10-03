@extends('layouts/contentLayoutMaster')
@section('title', 'Edit document')
@section('content')
{{-- Contract 17B — placeholder shell. A later stage builds the visual editor UI
     against the JSON API; it reads everything it needs from the bootstrap below. --}}
<a href="{{ route('customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]) }}">Back to document</a>
<h4 data-role="editor-title">{{ $document->title }} <small>{{ $document->status->value }}</small></h4>
<div id="document-editor" data-role="document-editor"></div>
<script type="application/json" id="document-editor-bootstrap">{!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endsection
