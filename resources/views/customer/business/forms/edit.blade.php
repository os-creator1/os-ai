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
    @include('public.forms._style', ['design' => []])

    @include('customer.business.forms._messages')

    <div class="fb pf-scope" id="fb-root" data-role="forms-builder">
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
            <div class="fb-settings">
                <section>
                    <h5>Basics</h5>
                    <div class="fb-field">
                        <label for="fb-s-name">Form name</label>
                        <input type="text" id="fb-s-name" class="form-control" maxlength="120" data-bind="name">
                    </div>
                    <div class="fb-field">
                        <label for="fb-s-intro">Introduction <span class="text-muted">(optional, shown above the first page)</span></label>
                        <textarea id="fb-s-intro" class="form-control" rows="3" maxlength="1000" data-bind="intro"></textarea>
                    </div>
                    <div class="fb-field mb-0">
                        <label>Status</label>
                        <div class="d-flex align-items-center" style="gap:.6rem">
                            <span class="fb-badge {{ $form->isActive() ? 'is-active' : '' }}" data-role="forms-status-badge">{{ $form->lifecycle_state->label() }}</span>
                            @if ($form->isActive())
                                <form method="POST" action="{{ route('customer.workspaces.businesses.forms.deactivate', $formScope) }}" class="d-inline fb-nav-form">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-role="forms-deactivate">Switch off</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('customer.workspaces.businesses.forms.activate', $formScope) }}" class="d-inline fb-nav-form">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" data-role="forms-activate">{{ $form->lifecycle_state->value === 'inactive' ? 'Switch on again' : 'Activate' }}</button>
                                </form>
                            @endif
                        </div>
                        <small class="text-muted">Only an active form accepts responses.</small>
                    </div>
                </section>

                <section>
                    <h5>After the visitor submits</h5>
                    <div class="fb-field mb-0">
                        <label for="fb-s-success">Confirmation message</label>
                        <input type="text" id="fb-s-success" class="form-control" maxlength="300" data-bind="success_message">
                        <small>Shown on the thank-you page. Redirecting to another URL isn't supported yet.</small>
                    </div>
                </section>

                <section data-role="forms-style">
                    <h5>Form style</h5>
                    <div class="fb-field">
                        <label for="fb-s-button">Button label</label>
                        <input type="text" id="fb-s-button" class="form-control" maxlength="40" data-bind="submit_label">
                    </div>
                    <div class="fb-field">
                        <label>Accent color</label>
                        <div class="fb-swatches" id="fb-swatches"></div>
                    </div>
                    <div class="fb-field">
                        <label>Button alignment</label>
                        <div class="fb-seg" data-design-seg="button_align">
                            <button type="button" data-v="left">Left</button><button type="button" data-v="center">Center</button><button type="button" data-v="right">Right</button><button type="button" data-v="full">Full width</button>
                        </div>
                    </div>
                    <div class="fb-field">
                        <label>Corners</label>
                        <div class="fb-seg" data-design-seg="radius">
                            <button type="button" data-v="none">Square</button><button type="button" data-v="sm">Slight</button><button type="button" data-v="md">Rounded</button><button type="button" data-v="lg">Very round</button>
                        </div>
                    </div>
                    <div class="fb-field">
                        <label>Form width</label>
                        <div class="fb-seg" data-design-seg="width">
                            <button type="button" data-v="narrow">Narrow</button><button type="button" data-v="medium">Medium</button><button type="button" data-v="wide">Wide</button>
                        </div>
                    </div>
                    <div class="fb-field mb-0">
                        <label for="fb-s-bg">Page background</label>
                        <div class="d-flex align-items-center" style="gap:.5rem">
                            <input type="color" id="fb-s-bg" value="#f3f5f9" aria-label="Page background color">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="fb-s-bg-reset">Reset</button>
                        </div>
                    </div>
                </section>

                <section>
                    <h5>Opportunities</h5>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="fb-s-opp" data-bind="create_opportunity">
                        <label class="custom-control-label" for="fb-s-opp">Create an opportunity for each response</label>
                    </div>
                    <small class="text-muted d-block mb-1">Needs a phone number question, so the response can be tied to a person.</small>
                    <div class="fb-field mb-0">
                        <label for="fb-s-pipeline">Pipeline for new opportunities</label>
                        <select id="fb-s-pipeline" class="form-control" data-bind="opportunity_pipeline_id"></select>
                    </div>
                </section>

                <section>
                    <h5>Where it is offered</h5>
                    <p class="text-muted mb-1">
                        The form is one Business-wide definition. Each location gets its own link, so every response is tied to the location it came through. Only locations you can access are listed.
                    </p>
                    @include('customer.business.forms._deployments', ['formScope' => $formScope])
                </section>
            </div>
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
