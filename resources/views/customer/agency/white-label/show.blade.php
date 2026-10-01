@extends('layouts/contentLayoutMaster')

{{--
    Agency V1 completion — the Agency's White Label settings (Blueprint §28).

    Whoever may open this page (any active Agency team member) can SEE the
    identity; only the Agency Workspace owner is given the form, and the
    manager re-checks ownership from the locked Workspace row on save, so
    hiding the form here is presentation, not the authority.

    Scope is deliberately small and honest: the name, logo, accent colour and
    support address that this Agency's CLIENTS see in the signed-in product in
    place of the platform's. A custom branded domain and a branded login
    screen are NOT part of this surface (they need domain verification that
    does not exist yet) and the page says so rather than implying otherwise.
--}}

@section('title', 'White label')

@section('content')
    <section id="agency-white-label">
        <h2 class="mb-2">White label</h2>

        <p class="text-caption mb-3" data-role="white-label-intro">
            Show your clients your own name and logo instead of the platform's. It appears for every
            client you manage, once you switch it on, and goes away for a client the moment you stop
            managing them.
        </p>

        @if (! $entitled)
            <x-card title="Not included in your plan" class="mb-2" data-role="white-label-not-entitled">
                <p class="mb-0">White label is not included in your current plan, so your clients keep seeing the platform's own name and logo.</p>
            </x-card>
        @endif

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

            @if ($isOwner && $entitled)
                <form method="POST" action="{{ route('customer.workspaces.agency.white-label.update', $agencyWorkspace->uid) }}" enctype="multipart/form-data" data-role="white-label-form">
                    @csrf

                    @if ($errors->any())
                        <div class="text-danger mb-2" role="alert" data-role="white-label-errors">{{ $errors->first() }}</div>
                    @endif

                    <div class="row">
                        <div class="col-12 col-md-6">
                            <x-input name="display_name" label="Name your clients see" :value="old('display_name', $setting?->display_name)" maxlength="80" required />
                        </div>
                        <div class="col-12 col-md-6">
                            <x-input name="support_email" type="email" label="Support email (optional)" :value="old('support_email', $setting?->support_email)" maxlength="191" />
                        </div>
                        <div class="col-12 col-md-8">
                            <x-input name="tagline" label="Tagline (optional)" :value="old('tagline', $setting?->tagline)" maxlength="160" />
                        </div>
                        <div class="col-12 col-md-4">
                            <x-input name="accent_color" label="Accent colour (optional)" :value="old('accent_color', $setting?->accent_color)" placeholder="#1a73e8" maxlength="7" />
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="white-label-logo" class="form-label">Logo (PNG, JPG, WebP or GIF — up to 2 MB, 800 × 200 px)</label>
                        <input id="white-label-logo" name="logo" type="file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif" data-role="white-label-logo-input" />
                        @error('logo')
                            <div class="text-danger text-caption mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($setting?->logo_path)
                        <div class="mb-2" data-role="white-label-current-logo">
                            <img src="{{ asset($setting->logo_path) }}" alt="Current logo" style="max-height:48px;max-width:240px;" />
                            <div class="form-check mt-1">
                                <input id="white-label-remove-logo" name="remove_logo" type="checkbox" value="1" class="form-check-input" />
                                <label for="white-label-remove-logo" class="form-check-label">Remove this logo</label>
                            </div>
                        </div>
                    @endif

                    <div class="form-check mb-3">
                        <input id="white-label-enabled" name="is_enabled" type="checkbox" value="1" class="form-check-input" @checked(old('is_enabled', $setting?->is_enabled)) data-role="white-label-enabled" />
                        <label for="white-label-enabled" class="form-check-label">Show this to my clients</label>
                    </div>

                    <x-button type="submit" variant="primary" data-role="white-label-save">Save white label</x-button>
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

        <x-card title="Not included yet" class="mb-2" data-role="white-label-deferred">
            <p class="mb-0">
                A custom branded domain and a branded sign-in page are not available yet. Your clients
                sign in at the usual address and see your name and logo once they are in.
            </p>
        </x-card>

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
    </section>
@endsection
