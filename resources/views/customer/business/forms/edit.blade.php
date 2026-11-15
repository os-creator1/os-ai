@extends('layouts/contentLayoutMaster')

@section('title', $form->name)

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $formScope = array_merge($scope, [$form->uid]);
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $config = [
            'saveUrl' => route('customer.workspaces.businesses.forms.builder.save', $formScope),
            'previewUrl' => route('customer.workspaces.businesses.forms.builder.preview', $formScope),
            'editUrl' => route('customer.workspaces.businesses.forms.edit', $formScope),
            'limits' => $limits,
            'blockLimit' => \App\Library\Forms\FormDefinitionNormalizer::MAX_BLOCKS,
        ];
    @endphp

    <link rel="stylesheet" href="{{ asset('css/forms/form-builder.css') }}?v={{ filemtime(public_path('css/forms/form-builder.css')) }}">
    @include('customer.business.forms._assets')
    @include('public.forms._style', ['design' => []])

    @include('customer.business.forms._messages')

    <div class="fb pf-scope mgf" id="fb-root" data-role="forms-builder">
        @include('customer.business.forms._builder_header', ['tab' => 'edit'])

        <div class="fb-banner" id="fb-banner" role="alert" data-role="forms-banner">
            <span id="fb-banner-text"></span>
            <a href="{{ route('customer.workspaces.businesses.forms.edit', $formScope) }}" class="btn btn-sm btn-outline-danger" id="fb-reload" hidden>Reload the latest version</a>
        </div>

        {{-- ================= EDIT ================= --}}
        <div id="fb-pane-edit" data-fb-pane="edit">
            <div class="fb-pane-toggles">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="fb-toggle-toolbox">Add elements</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="fb-toggle-inspector">Edit selected</button>
            </div>
            <div class="fb-work">
                <aside class="fb-side" id="fb-toolbox" aria-label="Elements" data-role="forms-toolbox"></aside>
                <main class="fb-canvas" id="fb-canvas" aria-label="Form canvas" data-role="forms-canvas"></main>
                <aside class="fb-inspector" id="fb-inspector" aria-label="Element settings" data-role="forms-inspector"></aside>
            </div>
        </div>

        {{-- ================= SETTINGS ================= --}}
        <div id="fb-pane-settings" data-fb-pane="settings" hidden>
            @include('customer.business.forms._settings_pane')
        </div>
    </div>

    {{-- ================= PREVIEW ================= --}}
    <div class="fb-modal" id="fb-preview-modal" role="dialog" aria-modal="true" aria-labelledby="fb-preview-title">
        <div class="fb-modal-box">
            <div class="fb-modal-head">
                <h5 id="fb-preview-title">Preview</h5>
                <div class="fb-seg" id="fb-preview-device"><button type="button" data-d="desktop" class="is-on">Desktop</button><button type="button" data-d="mobile">Mobile</button></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-fb-close>Close</button>
            </div>
            <div class="fb-frame-wrap" id="fb-frame-wrap">
                <iframe class="fb-frame" id="fb-preview-frame" title="Form preview" sandbox="" data-role="forms-preview-frame"></iframe>
            </div>
            <div class="fb-modal-body" id="fb-preview-msg" hidden></div>
        </div>
    </div>

    {{-- ================= INTEGRATE ================= --}}
    <div class="fb-modal" id="fb-integrate-modal" role="dialog" aria-modal="true" aria-labelledby="fb-integrate-title">
        <div class="fb-modal-box is-narrow">
            <div class="fb-modal-head">
                <h5 id="fb-integrate-title">Integrate</h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-fb-close>Close</button>
            </div>
            <div class="fb-modal-body" data-role="forms-integrate-body">
                @if (! $form->isActive())
                    <div class="fb-note is-warn">This form is {{ strtolower($form->lifecycle_state->label()) }}, so its links don't accept responses yet. Activate it under Settings.</div>
                @endif
                <p class="text-muted">Each location has its own link — a response is recorded at the location whose link it came through.</p>
                @php
                    $live = $deployments->filter(fn ($d) => $d->is_enabled);
                    $offered = collect($locations)->filter(fn ($l) => $live->has($l->id) || ($websiteDeployments->get($l->id)?->is_enabled ?? false));
                @endphp
                @if (count($locations) > 1)
                    <div class="fb-field">
                        <label for="fb-integrate-location">Location</label>
                        <select id="fb-integrate-location" class="form-control form-control-sm" data-role="forms-integrate-location">
                            @foreach ($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name ?: 'Unnamed location' }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @forelse ($locations as $location)
                    @php
                        $deployment = $deployments->get($location->id);
                        $web = $websiteDeployments->get($location->id);
                        $url = $deployment !== null && $deployment->is_enabled ? route('public.forms.show', [$deployment->uid]) : null;
                        $webOn = $web !== null && $web->is_enabled;
                    @endphp
                    <div class="mb-2 fb-integrate-loc" data-location="{{ $location->uid }}" @if(! $loop->first && count($locations) > 1) hidden @endif>
                        @if (count($locations) <= 1)<strong>{{ $location->name ?: 'Unnamed location' }}</strong>@endif
                        @if ($url)
                            <div class="fb-copy-row mt-50">
                                <input type="text" class="form-control form-control-sm" readonly value="{{ $url }}" data-role="forms-integrate-link">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-copy="{{ $url }}">Copy link</button>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $url }}" target="_blank" rel="noopener">Open</a>
                            </div>
                            <div class="fb-copy-row mt-50">
                                <input type="text" class="form-control form-control-sm" readonly value='<iframe src="{{ $url }}" style="width:100%;height:760px;border:0" title="{{ $form->name }}"></iframe>' data-role="forms-integrate-embed">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-copy-prev>Copy embed</button>
                            </div>
                        @else
                            <div class="fb-note mt-50" data-role="forms-integrate-empty">Not offered at this location yet — turn it on under Settings → Where it is offered.</div>
                        @endif

                        <div class="mt-1" data-role="forms-website-reference">
                            <strong class="d-block">Website pages</strong>
                            <small class="text-muted d-block mb-50">A Website page can show this form through a stable reference. The form stays one Forms form; responses arrive at this location like any other.</small>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.forms.locations.set', array_merge($formScope, [$location->uid])) }}" class="fb-nav-form d-flex align-items-center" style="gap:.5rem">
                                @csrf
                                <input type="hidden" name="source" value="website">
                                <input type="hidden" name="enabled" value="{{ $webOn ? 0 : 1 }}">
                                <button type="submit" class="btn btn-sm {{ $webOn ? 'btn-outline-danger' : 'btn-outline-primary' }}" data-role="forms-website-toggle">{{ $webOn ? 'Stop offering to Website pages' : 'Make available to Website pages' }}</button>
                            </form>
                            @if ($webOn)
                                <div class="fb-copy-row mt-50">
                                    <input type="text" class="form-control form-control-sm" readonly value="{{ $web->uid }}" data-role="forms-website-reference-uid" aria-label="Website form reference">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-copy="{{ $web->uid }}">Copy reference</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="fb-empty-note">You don't have access to any locations for this business.</p>
                @endforelse
                @if ($offered->isEmpty() && count($locations))
                    <div class="fb-note" data-role="forms-integrate-none">This form isn't offered anywhere yet. Turn on a location under Settings → Where it is offered, or make it available to Website pages here.</div>
                @endif
                <div class="fb-note mb-0">To show it on a Website page, add a "Form from the Forms module" section to that page and choose this form. The Website shows this same public form; you can also use the link or embed code above.</div>            </div>
        </div>
    </div>

    <script type="application/json" id="fb-state">{!! json_encode($builder, $jsonFlags) !!}</script>
    <script type="application/json" id="fb-config">{!! json_encode($config, $jsonFlags) !!}</script>
@endsection

@section('page-script')
    <script src="{{ asset('js/forms/form-builder.js') }}?v={{ filemtime(public_path('js/forms/form-builder.js')) }}"></script>
@endsection
