@extends('layouts/contentLayoutMaster')

@section('title', 'Start your website')

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-1">Start your website</h4>
            <p class="text-caption mb-0">Choose a look. We will make an editable draft from the details you have already saved for {{ $business->name }}.</p>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">{{ $errors->first() }}</x-alert>
    @endif

    <x-card title="What we already know" class="mb-3">
        <p class="mb-2"><strong>{{ $business->name }}</strong>@if ($business->description) — {{ $business->description }}@endif</p>
        <p class="text-caption mb-2">
            @if ($services->isNotEmpty())
                Services: {{ $services->pluck('name')->implode(', ') }}.
            @else
                No services saved yet. You can add them later.
            @endif
            @if ($location && $location->city)
                Primary location: {{ $location->city }}.
            @endif
        </p>
        <p class="text-caption mb-0">
            @if ($business->email || $business->phone)
                Your saved contact details can appear on the draft.
            @else
                Add a phone number or email before publishing if you want visitors to contact you.
            @endif
            <a href="{{ route('customer.workspaces.businesses.knowledge-profile.show', [$workspaceUid, $businessUid]) }}">Review business details</a>
        </p>
    </x-card>

    <form method="POST" action="{{ route('customer.workspaces.businesses.website.store', [$workspaceUid, $businessUid]) }}">
        @csrf
        <fieldset>
            <legend class="h5 mb-2">Choose a design</legend>
            <p class="text-caption mb-3">Each option starts with your saved business information. You can edit, add, move, or remove sections afterwards.</p>
            <div class="row">
                @foreach ($designs as $key => $design)
                    <div class="col-md-4 mb-3">
                        <label class="website-starter-choice d-block h-100" for="website-design-{{ $key }}">
                            <input class="form-check-input me-1" type="radio" name="design" id="website-design-{{ $key }}" value="{{ $key }}" @checked(old('design', 'clean') === $key)>
                            <strong>{{ $design['name'] }}</strong>
                            <span class="website-starter-preview website-starter-preview-{{ $key }}" aria-hidden="true">
                                <span class="website-starter-preview-top"></span>
                                <span class="website-starter-preview-title"></span>
                                <span class="website-starter-preview-line"></span>
                                <span class="website-starter-preview-button"></span>
                                <span class="website-starter-preview-cards"><i></i><i></i><i></i></span>
                            </span>
                            <span class="text-caption d-block">{{ $design['description'] }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>
        <x-button type="submit" variant="primary">Create my draft</x-button>
    </form>

    <p class="text-caption mt-3 mb-0">Prefer to build page by page? <a href="#" id="start-blank-website">Start with a blank website</a>.</p>
    <form id="blank-website-form" method="POST" action="{{ route('customer.workspaces.businesses.website.store', [$workspaceUid, $businessUid]) }}" class="d-none">
        @csrf
        <input type="hidden" name="design" value="blank">
    </form>
@endsection

@section('page-style')
<style>
    .website-starter-choice { border: 1px solid #d8dce5; border-radius: 14px; padding: 16px; cursor: pointer; background: #fff; }
    .website-starter-choice:has(input:checked) { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, .15); }
    .website-starter-preview { display: block; position: relative; height: 160px; border-radius: 9px; margin: 12px 0; overflow: hidden; background: #f1f5f9; }
    .website-starter-preview span, .website-starter-preview i { display: block; }
    .website-starter-preview-top { height: 20px; background: #fff; }
    .website-starter-preview-title { width: 62%; height: 14px; margin: 22px auto 8px; background: #172554; border-radius: 3px; }
    .website-starter-preview-line { width: 75%; height: 5px; margin: 0 auto; background: #a3b2c8; border-radius: 3px; }
    .website-starter-preview-button { width: 25%; height: 14px; margin: 13px auto; background: #2563eb; border-radius: 4px; }
    .website-starter-preview-cards { display: flex !important; gap: 7px; margin: 13px 14px; }
    .website-starter-preview-cards i { flex: 1; height: 38px; background: #fff; border-radius: 4px; }
    .website-starter-preview-bold { background: #172033; }
    .website-starter-preview-bold .website-starter-preview-top { background: #101827; }
    .website-starter-preview-bold .website-starter-preview-title { background: #fff; width: 72%; }
    .website-starter-preview-bold .website-starter-preview-line { background: #95a0b1; }
    .website-starter-preview-bold .website-starter-preview-button { background: #e85d3f; }
    .website-starter-preview-bold .website-starter-preview-cards i { background: #2c394d; }
    .website-starter-preview-premium { background: #f7f2e9; }
    .website-starter-preview-premium .website-starter-preview-top { background: #ede5d7; }
    .website-starter-preview-premium .website-starter-preview-title { background: #28251f; width: 48%; }
    .website-starter-preview-premium .website-starter-preview-line { background: #ab9d89; }
    .website-starter-preview-premium .website-starter-preview-button { background: #8b653e; }
    .website-starter-preview-premium .website-starter-preview-cards i { background: #fffaf2; }
</style>
@endsection

@section('page-script')
<script>
    document.getElementById('start-blank-website').addEventListener('click', function (event) {
        event.preventDefault();
        document.getElementById('blank-website-form').submit();
    });
</script>
@endsection
