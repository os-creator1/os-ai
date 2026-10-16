@extends('layouts/contentLayoutMaster')

@section('title', 'Services')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        // The canonical Business Service records, in the order the page has
        // always used them; the primary service is always first, then the
        // services that are on, then the ones that are turned off.
        $isOn = fn ($service) => $service->status->value === 'active';
        $ordered = $services->sortBy(fn ($service) => ($service->is_primary ? 0 : ($isOn($service) ? 1 : 2)))->values();
        $onCount = $services->filter($isOn)->count();
        $editingUid = old('editing_service');
    @endphp

    <div class="sr-page sr-page--narrow" style="max-width:820px">
        @include('customer.settings._sr-header', [
            'backUrl' => route('customer.workspaces.businesses.settings.show', $scope),
            'title' => 'Services',
            'description' => 'What this business offers. Your primary service comes first on your website and in your search plan.',
        ])

        @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
            @if (session($key))
                <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
            @endif
        @endforeach

        @if ($errors->any())
            <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
        @endif

        @if ($canManage)
            <div class="sr-card" data-section="service-add">
                <form method="POST" action="{{ route('customer.workspaces.businesses.services.store', $scope) }}" class="sr-card__body d-flex align-items-center gap-3 flex-wrap" style="padding-block:var(--space-3)">
                    @csrf
                    <x-ds-icon name="plus" size="18" class="text-muted" aria-hidden="true" />
                    <label for="service-name" class="visually-hidden">Service name</label>
                    <input id="service-name" type="text" name="name" maxlength="255" required value="{{ old('name') }}"
                           placeholder="Add a service, e.g. Wedding photo booth"
                           class="form-control border-0 shadow-none" style="flex:1 1 12rem;width:auto;padding-left:0">
                    <button type="submit" class="btn btn-primary" data-action="add-service">Add service</button>
                </form>
            </div>
        @endif

        <div class="sr-card" data-section="services-active">
            <div class="sr-card__head">
                <h2 class="sr-card__title">Your services</h2>
                <span class="sr-card__meta" data-role="services-on-count" style="color:var(--color-primary)">{{ $onCount }} on</span>
            </div>

            @if ($ordered->isEmpty())
                <div class="sr-card__body">
                    <p class="mb-0" data-role="no-active-services">This business has no active service yet.{{ $canManage ? ' Add one above.' : '' }}</p>
                </div>
            @else
                <ul class="sr-list">
                    @foreach ($ordered as $service)
                        @php($on = $isOn($service))
                        <li class="sr-list__row {{ $service->is_primary ? 'is-highlight' : '' }}" data-service="{{ $service->uid }}" @unless ($on) data-state="off" @endunless>
                            <span class="sr-tile {{ $service->is_primary ? 'sr-tile--solid' : 'sr-tile--neutral' }}" style="width:36px;height:36px">
                                <x-ds-icon name="{{ $service->is_primary ? 'star' : 'camera' }}" size="16" aria-hidden="true" />
                            </span>

                            {{-- Reading state --}}
                            <div class="sr-list__main" data-role="service-view" @if ($editingUid === $service->uid) hidden @endif>
                                <div class="sr-list__name" @unless ($on) style="color:var(--color-text-muted)" @endunless>
                                    {{ $service->name }}
                                    @if ($service->is_primary)
                                        <span class="sr-chip sr-chip--accent" data-role="primary-badge" aria-label="Primary service">Primary</span>
                                    @endif
                                </div>
                            </div>

                            @if ($canManage)
                                <div class="sr-list__actions" data-role="service-view-actions" @if ($editingUid === $service->uid) hidden @endif>
                                    @if ($on && ! $service->is_primary)
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.services.primary', [...$scope, $service->uid]) }}">
                                            @csrf
                                            <button type="submit" class="sr-linkbtn" style="color:var(--color-text-muted)" data-action="make-primary">Make primary</button>
                                        </form>
                                    @endif
                                    <button type="button" class="sr-linkbtn" style="color:var(--color-text-muted)" data-action="edit-service" aria-label="Rename {{ $service->name }}">
                                        <x-ds-icon name="pencil" size="14" aria-hidden="true" />
                                    </button>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.services.' . ($on ? 'deactivate' : 'activate'), [...$scope, $service->uid]) }}">
                                        @csrf
                                        <label class="sr-switch" title="{{ $on ? 'Turn off' : 'Turn on' }} {{ $service->name }}">
                                            <input type="checkbox" role="switch" @checked($on) aria-label="{{ $on ? 'Turn off' : 'Turn on' }} {{ $service->name }}"
                                                   data-action="{{ $on ? 'deactivate' : 'activate' }}" onchange="this.form.submit()">
                                            <span class="sr-switch__text">{{ $on ? 'On' : 'Off' }}</span>
                                        </label>
                                        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">{{ $on ? 'Turn off' : 'Turn on' }}</button></noscript>
                                    </form>
                                </div>

                                {{-- Editing state: rename in place, then Save name or Cancel --}}
                                <form method="POST" action="{{ route('customer.workspaces.businesses.services.rename', [...$scope, $service->uid]) }}"
                                      class="sr-edit" data-role="service-edit" @unless ($editingUid === $service->uid) hidden @endunless>
                                    @csrf
                                    <input type="hidden" name="editing_service" value="{{ $service->uid }}">
                                    <input type="text" name="name" class="form-control" maxlength="255" required value="{{ $editingUid === $service->uid ? old('name', $service->name) : $service->name }}" aria-label="Rename {{ $service->name }}">
                                    <button type="button" class="sr-linkbtn" style="color:var(--color-text-muted)" data-action="cancel-edit">Cancel</button>
                                    <button type="submit" class="btn btn-primary btn-sm text-nowrap">Save name</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="sr-card__foot sr-card__foot--note">Turned-off services are hidden from your website and search plan. You can turn them back on any time.</div>
            @endif
        </div>
    </div>

    @if ($canManage)
        <script>
            (function () {
                // Rename in place: swap a row between its reading and editing states.
                var setEditing = function (row, editing) {
                    row.querySelectorAll('[data-role="service-view"], [data-role="service-view-actions"]').forEach(function (el) { el.hidden = editing; });
                    var form = row.querySelector('[data-role="service-edit"]');
                    if (form) {
                        form.hidden = ! editing;
                        if (editing) {
                            var input = form.querySelector('input[name="name"]');
                            input.focus();
                            input.setSelectionRange(input.value.length, input.value.length);
                        }
                    }
                };

                document.querySelectorAll('[data-service]').forEach(function (row) {
                    var edit = row.querySelector('[data-action="edit-service"]');
                    var cancel = row.querySelector('[data-action="cancel-edit"]');
                    if (edit) { edit.addEventListener('click', function () { setEditing(row, true); }); }
                    if (cancel) { cancel.addEventListener('click', function () { setEditing(row, false); }); }
                });
            })();
        </script>
    @endif
@endsection
