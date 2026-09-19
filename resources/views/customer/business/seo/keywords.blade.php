{{--
    Contract 18, Sub-slice D — SEO keywords and Core on-page coverage.

    These are the phrases a Business wants customers to find it with — NOT the
    legacy "text-in" keywords. Coverage is a content fact only: whether the
    published website uses the phrase. There is no ranking, position, traffic,
    score, grade or chart here, and no section for anything not built yet.

    Every value is rendered with escaped Blade output only. Raw, unescaped
    output is forbidden in this view.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Search keywords')

@php
    use App\Enums\Seo\SeoKeywordCoverageStatus;
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Search keywords</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->any())
        <x-alert variant="danger" class="mb-2" data-role="keyword-errors">{{ $errors->first() }}</x-alert>
    @endif

    <p class="text-caption mb-2">
        The phrases you want customers to find your business with. We check whether your published website uses each one. This does not show search rankings.
    </p>

    @can('manage_seo')
        <x-card :padded="true" class="mb-2" data-section="add-keyword">
            <p class="text-section-heading mb-1">Add a keyword</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.store', [$workspaceUid, $businessUid]) }}" data-role="keyword-add-form">
                @csrf
                <div class="row g-1 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="keyword-phrase">Keyword</label>
                        <input class="form-control" type="text" id="keyword-phrase" name="phrase" maxlength="120" value="{{ old('phrase') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="keyword-location">Location</label>
                        <select class="form-select" id="keyword-location" name="location_uid">
                            <option value="">Whole business</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" type="submit">Add keyword</button>
                    </div>
                </div>
            </form>
        </x-card>
    @endcan

    <x-card :padded="true" data-section="keywords">
        @if($keywords->isEmpty())
            <p class="mb-0" data-role="no-keywords">You have no keywords yet.</p>
        @else
            <ul class="list-unstyled mb-0">
                @foreach($keywords as $keyword)
                    @php
                        $isActive = $keyword->isActive();
                        $result = $coverage[$keyword->id] ?? null;
                        $locationOpen = $keyword->location === null || $keyword->location->isActive();
                    @endphp
                    <li class="py-1 @unless($loop->last) border-bottom @endunless" data-role="keyword" data-uid="{{ $keyword->uid }}" data-state="{{ $keyword->lifecycle_state->value }}">
                        <div class="d-flex justify-content-between flex-wrap gap-1">
                            <div>
                                <strong data-role="keyword-phrase">{{ $keyword->phrase }}</strong>
                                <span class="badge bg-light text-dark ms-1" data-role="keyword-state">{{ $isActive ? 'Active' : 'Archived' }}</span>
                                <span class="text-caption d-block" data-role="keyword-location">{{ $keyword->location?->name ?? 'Whole business' }}</span>
                            </div>
                            @can('manage_seo')
                                @if($locationOpen)
                                    <div class="d-flex gap-1 align-items-start">
                                        @if($isActive)
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.archive', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" data-role="keyword-archive">Archive</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('customer.workspaces.businesses.seo.keywords.reactivate', [$workspaceUid, $businessUid, $keyword->uid]) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-secondary" type="submit" data-role="keyword-reactivate">Reactivate</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            @endcan
                        </div>

                        @if($isActive && $result !== null)
                            <p class="mb-0 mt-50" data-role="keyword-coverage" data-status="{{ $result->status->value }}">
                                {{ $result->status->label() }}
                            </p>
                            @if($result->status === SeoKeywordCoverageStatus::Covered)
                                <p class="text-caption mb-0" data-role="keyword-coverage-detail">
                                    In {{ $result->titlePages }} {{ $result->titlePages === 1 ? 'page title' : 'page titles' }},
                                    {{ $result->descriptionPages }} {{ $result->descriptionPages === 1 ? 'description' : 'descriptions' }} and
                                    {{ $result->bodyPages }} {{ $result->bodyPages === 1 ? 'page' : 'pages' }} of text
                                    (of {{ $result->pagesTotal }} published {{ $result->pagesTotal === 1 ? 'page' : 'pages' }}).
                                </p>
                            @endif
                        @endif

                        @can('manage_seo')
                            @if($isActive && $locationOpen)
                                <details class="mt-50">
                                    <summary class="text-caption">Edit</summary>
                                    <form method="POST" class="mt-50" action="{{ route('customer.workspaces.businesses.seo.keywords.update', [$workspaceUid, $businessUid, $keyword->uid]) }}" data-role="keyword-edit-form">
                                        @csrf
                                        <div class="row g-1 align-items-end">
                                            <div class="col-md-6">
                                                <label class="form-label" for="phrase-{{ $keyword->uid }}">Keyword</label>
                                                <input class="form-control" type="text" id="phrase-{{ $keyword->uid }}" name="phrase" maxlength="120" value="{{ $keyword->phrase }}" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="location-{{ $keyword->uid }}">Location</label>
                                                <select class="form-select" id="location-{{ $keyword->uid }}" name="location_uid">
                                                    <option value="">Whole business</option>
                                                    @foreach($locations as $location)
                                                        <option value="{{ $location->uid }}" @selected((int) $keyword->business_location_id === (int) $location->id)>{{ $location->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button class="btn btn-sm btn-primary w-100" type="submit">Save</button>
                                            </div>
                                        </div>
                                    </form>
                                </details>
                            @endif
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
@endsection
