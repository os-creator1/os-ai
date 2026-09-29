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

    <x-card title="Website completeness" class="mb-3">
        <p class="text-caption mb-2">What your site can include right now, from your saved business facts. Nothing here is guessed.</p>
        <ul class="mb-0">
            <li>Services: {{ $completeness['eligibleServiceCount'] }} real service{{ $completeness['eligibleServiceCount'] === 1 ? '' : 's' }} will get its own page.</li>
            <li>Packages: {{ $completeness['eligibleCatalogCount'] > 0 ? $completeness['eligibleCatalogCount'] . ' saved package(s) will appear.' : 'none saved yet.' }}</li>
            <li>
                Additional locations: {{ $completeness['eligibleLocationCount'] }} ready for their own page.
                @if ($completeness['needsMoreInfoLocationCount'] > 0)
                    {{ $completeness['needsMoreInfoLocationCount'] }} more need a service area or travel radius before we can give them a page —
                    <a href="{{ route('customer.workspaces.businesses.locations.index', [$workspaceUid, $businessUid]) }}">add that now</a>.
                @endif
            </li>
            <li>Photos: add real photos after your site is created so Home and your Gallery page have real images.</li>
            @if (! empty($completeness['missingFieldKeys']) || ! empty($completeness['staleFieldKeys']))
                <li>
                    {{ count($completeness['missingFieldKeys']) + count($completeness['staleFieldKeys']) }} more confirmed answer(s) (story, credentials, guarantees, pricing) would make your About and FAQ pages more complete —
                    <a href="{{ route('customer.workspaces.businesses.knowledge-profile.edit', [$workspaceUid, $businessUid]) }}">answer them now</a>.
                </li>
            @endif
        </ul>
    </x-card>

    @if ($reusable)
        <x-card title="What your Photo Booth draft will reuse" class="mb-3">
            <ul class="mb-2">
                <li>
                    @if ($reusable['services']->isNotEmpty())
                        <strong>Services</strong> (become your Services page): {{ $reusable['services']->pluck('name')->implode(', ') }}.
                    @else
                        <strong>Services</strong>: none saved yet, so no Services page will be added.
                    @endif
                </li>
                <li>
                    @if ($reusable['catalog']->isNotEmpty())
                        <strong>Packages</strong> (become your Packages page): {{ $reusable['catalog']->pluck('name')->implode(', ') }}.
                    @else
                        <strong>Packages</strong>: none saved yet, so no Packages page will be added.
                        <a href="{{ route('customer.workspaces.businesses.catalog.create', [$workspaceUid, $businessUid]) }}">Add a package</a>.
                    @endif
                </li>
                <li>
                    @if ($reusable['location'])
                        <strong>Location</strong>: Your public location @if ($reusable['location']->city) in {{ $reusable['location']->city }} @endif will appear in your contact details.
                    @else
                        <strong>Location</strong>: no active public location is available.
                        <a href="{{ route('customer.workspaces.businesses.locations.index', [$workspaceUid, $businessUid]) }}">Review your locations</a>.
                    @endif
                </li>
            </ul>
            <p class="text-caption mb-0">
                Describe your booth types, backdrops, props, and extras as services or packages above. Once your site exists, upload real event photos with a description of each one from the Photos screen and pick your favorites to build a Gallery page. Extra pages start hidden from search until you review them. All public websites remain hidden from search until the platform's search launch.
            </p>
        </x-card>
    @endif

    <form method="POST" action="{{ route('customer.workspaces.businesses.website.store', [$workspaceUid, $businessUid]) }}">
        @csrf
        @if ($templates->isNotEmpty())
            <fieldset>
                <legend class="h5 mb-2">Choose a template</legend>
                <p class="text-caption mb-3">Every template builds the same complete site structure from your saved business facts — only the look changes. You can edit, add, move, or remove sections afterwards.</p>
                <div class="row">
                    @foreach ($templates as $template)
                        <div class="col-md-3 mb-3">
                            <label class="website-starter-choice d-block h-100" for="website-template-{{ $template->key }}">
                                <input class="form-check-input me-1" type="radio" name="template_key" id="website-template-{{ $template->key }}" value="{{ $template->key }}" @checked($loop->first)>
                                <strong>{{ $template->display_name }}</strong>
                                <span class="website-starter-preview website-starter-preview-{{ $template->key }}" aria-hidden="true">
                                    <span class="website-starter-preview-top"></span>
                                    <span class="website-starter-preview-title"></span>
                                    <span class="website-starter-preview-line"></span>
                                    <span class="website-starter-preview-button"></span>
                                    <span class="website-starter-preview-cards"><i></i><i></i><i></i></span>
                                </span>
                                <span class="text-caption d-block">{{ $template->description }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @else
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
        @endif
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

    /* The four operator templates (Website Generator + Local SEO
       Completion) — swatches only, echoing each template's own
       website-public.css treatment so the picker preview isn't a lie. */
    .website-starter-preview-photo_booth_modern { background: #0b1220; }
    .website-starter-preview-photo_booth_modern .website-starter-preview-top { background: #0b1220; }
    .website-starter-preview-photo_booth_modern .website-starter-preview-title { background: #fff; width: 72%; }
    .website-starter-preview-photo_booth_modern .website-starter-preview-line { background: #6b7b8c; }
    .website-starter-preview-photo_booth_modern .website-starter-preview-button { background: #0ea5b0; border-radius: 999px; }
    .website-starter-preview-photo_booth_modern .website-starter-preview-cards i { background: #10213a; border-top: 3px solid #0ea5b0; }

    .website-starter-preview-photo_booth_editorial { background: #faf6f1; }
    .website-starter-preview-photo_booth_editorial .website-starter-preview-top { background: #faf6f1; }
    .website-starter-preview-photo_booth_editorial .website-starter-preview-title { background: #2b241d; width: 50%; }
    .website-starter-preview-photo_booth_editorial .website-starter-preview-line { background: #b6562c; height: 2px; width: 30%; }
    .website-starter-preview-photo_booth_editorial .website-starter-preview-button { background: transparent; border: 2px solid #b6562c; }
    .website-starter-preview-photo_booth_editorial .website-starter-preview-cards i { background: transparent; border-left: 2px solid #b6562c; border-radius: 0; }

    .website-starter-preview-photo_booth_luxury { background: #14110c; }
    .website-starter-preview-photo_booth_luxury .website-starter-preview-top { background: #14110c; }
    .website-starter-preview-photo_booth_luxury .website-starter-preview-title { background: #f6efe3; width: 60%; }
    .website-starter-preview-photo_booth_luxury .website-starter-preview-line { background: #a8874f; }
    .website-starter-preview-photo_booth_luxury .website-starter-preview-button { background: #a8874f; border-radius: 999px; }
    .website-starter-preview-photo_booth_luxury .website-starter-preview-cards i { background: #fdfbf7; border: 1px solid #e7dcc4; border-radius: 2px; }

    .website-starter-preview-photo_booth_conversion { background: #111827; }
    .website-starter-preview-photo_booth_conversion .website-starter-preview-top { background: #111827; border-bottom: 3px solid #ef4444; }
    .website-starter-preview-photo_booth_conversion .website-starter-preview-title { background: #fff; width: 66%; }
    .website-starter-preview-photo_booth_conversion .website-starter-preview-line { background: #9ca3af; }
    .website-starter-preview-photo_booth_conversion .website-starter-preview-button { background: #ef4444; }
    .website-starter-preview-photo_booth_conversion .website-starter-preview-cards i { background: #1f2937; border: 2px solid #374151; }
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
