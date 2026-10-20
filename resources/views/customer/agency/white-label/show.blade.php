@extends('layouts/contentLayoutMaster')

{{--
    White Label — one module, two tabs (Branding | Landing Page) sharing one brand.

    Whoever may open this page (any active Agency team member) can SEE the
    identity; only the Agency Workspace owner is given the forms, and the
    manager re-checks ownership from the locked Workspace row on save, so
    hiding the forms here is presentation, not the authority.

    The Landing Page tab reports real readiness only. The website editor is not
    part of this release and the tab says so; it never implies a published site.
    The app domain can be ownership-verified here, but the platform does not yet
    answer on that host, and the page says that too.
--}}

@section('title', 'White label')

@php
    $route = fn (string $name, array $query = []) => route('customer.workspaces.agency.white-label.' . $name, [$agencyWorkspace->uid]) . ($query ? '?' . http_build_query($query) : '');
    $canEdit = $isOwner && $entitled;
    $pv = [
        'name' => old('display_name', $setting?->display_name ?? $agencyWorkspace->name ?? 'Your brand'),
        'primary' => old('accent_color', $setting?->accent_color) ?: '#1a73e8',
        'secondary' => old('secondary_color', $setting?->secondary_color) ?: '#0f172a',
        'tagline' => old('tagline', $setting?->tagline),
        'logo' => $setting?->logo_path ? asset($setting->logo_path) : null,
        'favicon' => $setting?->favicon_path ? asset($setting->favicon_path) : null,
    ];
    $social = old('social', $setting?->social_links ?? []);
@endphp

@section('content')
    <section id="agency-white-label">
        <h2 class="mb-2">White label</h2>

        <ul class="nav nav-tabs mb-3" role="tablist" data-role="white-label-tabs">
            <li class="nav-item"><a class="nav-link {{ $tab === 'branding' ? 'active' : '' }}" href="{{ $route('show') }}" role="tab" aria-selected="{{ $tab === 'branding' ? 'true' : 'false' }}" data-role="tab-branding">Branding</a></li>
            <li class="nav-item"><a class="nav-link {{ $tab === 'landing' ? 'active' : '' }}" href="{{ $route('show', ['tab' => 'landing']) }}" role="tab" aria-selected="{{ $tab === 'landing' ? 'true' : 'false' }}" data-role="tab-landing">Landing Page</a></li>
        </ul>

        @if (session('message'))
            <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="status" data-role="white-label-flash">{{ session('message') }}</div>
        @endif

        @if (! $entitled)
            <x-card title="Not included in your plan" class="mb-2" data-role="white-label-not-entitled">
                <p class="mb-0">White label is not included in your current plan, so your clients keep seeing the platform's own name and logo.</p>
            </x-card>
        @endif

        @if ($tab === 'landing' && $editor !== null)
            @include('customer.agency.white-label._landing', ['editor' => $editor, 'canEdit' => $canEdit])
        @elseif ($tab === 'landing')
            <x-card title="Landing page status" class="mb-2" data-role="landing-status">
                <p class="mb-2">
                    <x-badge variant="neutral">Not published</x-badge>
                    The marketing-website editor is not available in this release, so no landing page exists yet and nothing is public.
                    The items below are the real state of what it will need.
                </p>
                <ul class="mb-0">
                    <li data-role="landing-brand">Brand identity: {{ $landing['brandSaved'] ? 'saved' : 'not set up — start on the Branding tab' }}</li>
                    <li data-role="landing-domain">App domain: {{ $landing['domainVerified'] ? 'ownership verified' : 'not verified' }}</li>
                    <li data-role="landing-stripe">Stripe account for selling to customers: {{ $landing['stripe'] ? 'connected' : 'not connected' }}
                        @if (! $landing['stripe']) — <a href="{{ route('customer.workspaces.agency.saas.stripe', [$agencyWorkspace->uid]) }}">connect Stripe</a>@endif</li>
                    <li data-role="landing-plans">Customer plans: {{ $landing['plans'] }}</li>
                </ul>
            </x-card>
        @else
        <p class="text-caption mb-3" data-role="white-label-intro">
            Show your clients your own name, logo and colours instead of the platform's. It appears for every
            client you manage, once you switch it on, and goes away for a client the moment you stop
            managing them.
        </p>

        <div class="row">
            <div class="col-12 col-xl-7">
        <x-card title="Your brand" class="mb-2">
            @if ($setting !== null)
                <p class="mb-2" data-role="white-label-status">
                    @if ($setting->is_enabled)
                        <x-badge variant="success">On — your clients see this</x-badge>
                    @else
                        <x-badge variant="neutral">Off — saved, not shown to clients</x-badge>
                    @endif
                </p>
            @endif

            @if ($canEdit)
                <form method="POST" action="{{ $route('update') }}" enctype="multipart/form-data" data-role="white-label-form" id="white-label-form">
                    @csrf

                    @if ($errors->any())
                        <div class="text-danger mb-2" role="alert" data-role="white-label-errors">{{ $errors->first() }}</div>
                    @endif

                    <div class="row">
                        <div class="col-12 col-md-6">
                            <x-input name="display_name" label="Platform name" :value="old('display_name', $setting?->display_name)" maxlength="80" required />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-input name="legal_name" label="Legal business name (optional)" :value="old('legal_name', $setting?->legal_name)" maxlength="120" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-input name="support_email" type="email" label="Support email (optional)" :value="old('support_email', $setting?->support_email)" maxlength="191" />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-input name="support_url" type="url" label="Support website (https://, optional)" :value="old('support_url', $setting?->support_url)" maxlength="255" />
                        </div>
                        <div class="col-12">
                            <x-input name="tagline" label="Tagline (optional)" :value="old('tagline', $setting?->tagline)" maxlength="160" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="wl-primary" class="form-label">Primary colour</label>
                            <input id="wl-primary" type="color" class="form-control form-control-color" value="{{ $pv['primary'] }}" data-role="primary-picker" aria-label="Primary colour picker" />
                            <input name="accent_color" class="form-control mt-1" value="{{ old('accent_color', $setting?->accent_color) }}" placeholder="#1a73e8" maxlength="7" data-role="primary-hex" aria-label="Primary colour hex" />
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="wl-secondary" class="form-label">Accent colour (optional)</label>
                            <input id="wl-secondary" type="color" class="form-control form-control-color" value="{{ $pv['secondary'] }}" data-role="secondary-picker" aria-label="Accent colour picker" />
                            <input name="secondary_color" class="form-control mt-1" value="{{ old('secondary_color', $setting?->secondary_color) }}" placeholder="#0f172a" maxlength="7" data-role="secondary-hex" aria-label="Accent colour hex" />
                        </div>
                    </div>

                    <h3 class="h6 mt-3">Images</h3>
                    <p class="text-caption">PNG, JPG, WebP or GIF, up to 2 MB. SVG is not accepted. Images are shown as uploaded and fitted, never stretched.</p>
                    <div class="row">
                        @foreach ([
                            'logo' => ['Primary logo (up to 800 × 200 px)', $setting?->logo_path, 'remove_logo'],
                            'logo_dark' => ['Logo for dark backgrounds (up to 800 × 200 px)', $setting?->logo_dark_path, 'remove_logo_dark'],
                            'logo_compact' => ['Square logo / icon (up to 200 × 200 px)', $setting?->logo_compact_path, 'remove_logo_compact'],
                            'favicon' => ['Favicon (up to 512 × 512 px)', $setting?->favicon_path, 'remove_favicon'],
                        ] as $field => [$label, $current, $removeField])
                            <div class="col-12 col-md-6 mb-2">
                                <label for="wl-{{ $field }}" class="form-label">{{ $label }}</label>
                                <input id="wl-{{ $field }}" name="{{ $field }}" type="file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif" data-role="{{ $field === 'logo' ? 'white-label-logo-input' : $field . '-input' }}" />
                                @error($field)<div class="text-danger text-caption mt-1">{{ $message }}</div>@enderror
                                @if ($current)
                                    <div class="mt-1" data-role="{{ $field === 'logo' ? 'white-label-current-logo' : 'current-' . $field }}">
                                        <img src="{{ asset($current) }}" alt="Current {{ str_replace('_', ' ', $field) }}" style="max-height:40px;max-width:200px;object-fit:contain;background:#e5e7eb;" />
                                        <div class="form-check mt-1">
                                            <input id="wl-{{ $removeField }}" name="{{ $removeField }}" type="checkbox" value="1" class="form-check-input" />
                                            <label for="wl-{{ $removeField }}" class="form-check-label">Remove this image</label>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <h3 class="h6 mt-3">Social links (optional)</h3>
                    <div class="row">
                        @foreach (\App\Library\Branding\AgencyWhiteLabelManager::SOCIAL_NETWORKS as $network => $hosts)
                            <div class="col-12 col-md-6">
                                <x-input name="social[{{ $network }}]" :label="ucfirst($network) . ' (https://' . $hosts[0] . '/…)'" :value="$social[$network] ?? ''" maxlength="255" />
                            </div>
                        @endforeach
                    </div>

                    <div class="form-check my-3">
                        <input id="white-label-enabled" name="is_enabled" type="checkbox" value="1" class="form-check-input" @checked(old('is_enabled', $setting?->is_enabled)) data-role="white-label-enabled" />
                        <label for="white-label-enabled" class="form-check-label">Show this to my clients</label>
                    </div>

                    <x-button type="submit" variant="primary" data-role="white-label-save">Save white label</x-button>
                    <button type="reset" class="btn btn-outline-secondary ms-1" data-role="white-label-reset">Reset changes</button>
                </form>
            @elseif ($setting !== null)
                <dl class="mb-0" data-role="white-label-readonly">
                    <dt>Name</dt>
                    <dd>{{ $setting->display_name }}</dd>
                    @if ($setting->tagline)
                        <dt>Tagline</dt>
                        <dd>{{ $setting->tagline }}</dd>
                    @endif
                    @if ($setting->support_email)
                        <dt>Support email</dt>
                        <dd>{{ $setting->support_email }}</dd>
                    @endif
                </dl>
                @unless ($isOwner)
                    <p class="text-caption mt-2 mb-0">Only the agency owner can change these settings.</p>
                @endunless
            @elseif (! $isOwner)
                <p class="mb-0" data-role="white-label-empty">No white label has been set up yet. Only the agency owner can set it up.</p>
            @endif
        </x-card>

        <x-card title="App domain" class="mb-2" data-role="white-label-domain">
            @php $status = $setting?->app_domain_status ?? 'none'; @endphp
            @if ($setting?->app_domain)
                <p class="mb-2">
                    <strong data-role="domain-name">{{ $setting->app_domain }}</strong>
                    @if ($status === 'verified')
                        <x-badge variant="success">Ownership verified</x-badge>
                    @else
                        <x-badge variant="neutral">Waiting for DNS</x-badge>
                    @endif
                </p>
                @if ($domainInstructions)
                    <p class="text-caption mb-1">At your DNS provider, add this record to prove you own the domain:</p>
                    <pre class="mb-2" data-role="domain-txt">TXT  {{ $domainInstructions['ownership']['host'] }}  =  {{ $domainInstructions['ownership']['value'] }}</pre>
                @endif
                <p class="text-caption mb-2" data-role="domain-not-live">
                    Verifying ownership does not switch the domain on. Your clients cannot sign in at this address yet:
                    pointing traffic and issuing the HTTPS certificate are a separate platform step. Your clients keep using the usual address.
                </p>
                @if ($canEdit)
                    <div class="d-flex gap-1">
                        @if ($status !== 'verified')
                            <form method="POST" action="{{ $route('domain.check') }}">@csrf<x-button type="submit" variant="primary" data-role="domain-check">Check DNS</x-button></form>
                        @endif
                        <form method="POST" action="{{ $route('domain.remove') }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary" data-role="domain-remove">Remove domain</x-button></form>
                    </div>
                @endif
            @elseif ($canEdit && $setting !== null)
                <form method="POST" action="{{ $route('domain.attach') }}" class="d-flex gap-1 align-items-end">
                    @csrf
                    <div class="flex-grow-1"><x-input name="app_domain" label="Hostname, e.g. app.youragency.com" maxlength="191" required /></div>
                    <x-button type="submit" variant="primary" data-role="domain-add">Add domain</x-button>
                </form>
            @else
                <p class="mb-0">Save your brand first, then you can add a branded app domain.</p>
            @endif
        </x-card>
            </div>

            <div class="col-12 col-xl-5">
                <x-card title="Live preview" class="mb-2" data-role="white-label-preview">
                    <div id="wl-pv" style="--pv-primary:{{ $pv['primary'] }};--pv-secondary:{{ $pv['secondary'] }};">
                        <div class="small text-muted mb-1">Browser tab</div>
                        <div class="d-flex align-items-center gap-1 border rounded px-2 py-1 mb-3" style="background:#f3f4f6">
                            <img id="wl-pv-favicon" src="{{ $pv['favicon'] ?? '' }}" alt="" width="16" height="16" style="{{ $pv['favicon'] ? '' : 'display:none' }}">
                            <span id="wl-pv-tab" class="text-truncate">{{ $pv['name'] }}</span>
                        </div>
                        <div class="small text-muted mb-1">Sidebar &amp; top bar</div>
                        <div class="d-flex border rounded overflow-hidden mb-3" style="height:150px">
                            <div style="width:38%;background:var(--pv-secondary);color:#fff;padding:10px" class="d-flex flex-column gap-1">
                                <div id="wl-pv-side-name" class="fw-bold text-truncate">{{ $pv['name'] }}</div>
                                <div style="background:var(--pv-primary);color:#fff;border-radius:6px;padding:3px 8px" class="small">Dashboard</div>
                                <div class="small" style="opacity:.8;padding:3px 8px">Contacts</div>
                                <div class="small" style="opacity:.8;padding:3px 8px">Calendar</div>
                            </div>
                            <div class="flex-grow-1">
                                <div style="background:#fff;border-bottom:1px solid #e5e7eb;padding:8px" class="d-flex justify-content-between align-items-center">
                                    <img id="wl-pv-logo" src="{{ $pv['logo'] ?? '' }}" alt="" style="max-height:22px;max-width:110px;object-fit:contain;{{ $pv['logo'] ? '' : 'display:none' }}">
                                    <span id="wl-pv-logo-text" class="fw-bold" style="{{ $pv['logo'] ? 'display:none' : '' }}">{{ $pv['name'] }}</span>
                                    <span class="small text-muted">Account</span>
                                </div>
                                <div class="p-2">
                                    <button type="button" class="btn btn-sm" tabindex="-1" style="background:var(--pv-primary);color:#fff;border:0" id="wl-pv-btn">Primary button</button>
                                    <button type="button" class="btn btn-sm" tabindex="-1" style="border:1px solid var(--pv-primary);color:var(--pv-primary);background:#fff">Secondary</button>
                                </div>
                            </div>
                        </div>
                        <div class="small text-muted mb-1">Sign-in screen</div>
                        <div class="border rounded p-3 text-center">
                            <div id="wl-pv-login-name" class="fw-bold fs-5">{{ $pv['name'] }}</div>
                            <div id="wl-pv-login-tag" class="small text-muted mb-2">{{ $pv['tagline'] }}</div>
                            <div class="rounded py-1 small" style="background:var(--pv-primary);color:#fff">Sign in</div>
                        </div>
                        <p class="text-caption mt-2 mb-0">Previews update as you type. Nothing is saved until you press Save. Errors, warnings and success messages keep their standard colours.</p>
                    </div>
                </x-card>
            </div>
        </div>

        <script>
            (function () {
                var form = document.getElementById('white-label-form');
                var pv = document.getElementById('wl-pv');
                if (!form || !pv) { return; }
                var hex = /^#[0-9a-fA-F]{6}$/;
                var base = { primary: pv.style.getPropertyValue('--pv-primary'), secondary: pv.style.getPropertyValue('--pv-secondary') };
                function text(id, v) { var e = document.getElementById(id); if (e) { e.textContent = v; } }
                function bindColor(role, prop, fallback) {
                    var picker = form.querySelector('[data-role=' + role + '-picker]');
                    var field = form.querySelector('[data-role=' + role + '-hex]');
                    function apply(v) { pv.style.setProperty(prop, hex.test(v) ? v : fallback); }
                    picker.addEventListener('input', function () { field.value = picker.value; apply(picker.value); });
                    field.addEventListener('input', function () { if (hex.test(field.value)) { picker.value = field.value; } apply(field.value); });
                }
                bindColor('primary', '--pv-primary', base.primary);
                bindColor('secondary', '--pv-secondary', base.secondary);
                function name() { return (form.elements.display_name.value || '').trim() || 'Your brand'; }
                function sync() { var n = name(); ['wl-pv-tab', 'wl-pv-side-name', 'wl-pv-login-name', 'wl-pv-logo-text'].forEach(function (i) { text(i, n); }); text('wl-pv-login-tag', form.elements.tagline.value); }
                form.elements.display_name.addEventListener('input', sync);
                form.elements.tagline.addEventListener('input', sync);
                function bindFile(input, img, hideText) {
                    var f = form.elements[input];
                    f.addEventListener('change', function () {
                        var file = f.files[0], el = document.getElementById(img);
                        if (!file || !/^image\/(png|jpeg|webp|gif)$/.test(file.type)) { return; }
                        el.src = URL.createObjectURL(file); el.style.display = '';
                        if (hideText) { document.getElementById('wl-pv-logo-text').style.display = 'none'; }
                    });
                }
                bindFile('logo', 'wl-pv-logo', true);
                bindFile('favicon', 'wl-pv-favicon', false);
                form.addEventListener('reset', function () { setTimeout(function () {
                    pv.style.setProperty('--pv-primary', base.primary); pv.style.setProperty('--pv-secondary', base.secondary); sync();
                }, 0); });
            })();
        </script>

        @if ($history->isNotEmpty())
            <x-card title="Recent changes">
                <ul class="mb-0" data-role="white-label-history">
                    @foreach ($history as $change)
                        <li data-role="white-label-change">
                            {{ str_replace('_', ' ', ucfirst($change->change_type)) }}
                            <span class="text-caption">· {{ $change->created_at?->format('M j, Y g:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
        @endif
    </section>
@endsection
