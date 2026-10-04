@extends('layouts/contentLayoutMaster')

{{--
    Settings → Custom fields. Presentation only: every rule (stable keys,
    types, options, archive, order) lives in CustomFieldDefinitionManager and
    a refusal comes back through the `custom_field` error bag as it worded it.
    The merge field shown for each row is the canonical {{contact.key}} token
    and never changes when the name is edited.
--}}

@section('title', 'Custom fields')

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.custom-fields.' . $name, [...$scope, ...$extra]);
        $canManage = auth()->user()?->can('update_contact');
        $optionTypes = collect($types)->filter(fn ($type) => $type->hasOptions())->map(fn ($type) => $type->value)->values();
    @endphp

    @include('customer.settings._module-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', $scope),
        'title' => 'Custom fields',
        'description' => 'The extra details you keep about contacts — like an event date or venue. Define them once here and use them everywhere: on a contact, in forms, in messages and in automations.',
    ])

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    @error('custom_field')
        <x-alert variant="danger" class="mb-2" role="alert" data-role="custom-field-error">{{ $message }}</x-alert>
    @enderror

    <section id="custom-fields" data-role="custom-fields">
        <x-card title="Custom fields" :padded="false">
            <x-slot name="actions">
                @if ($canManage)
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#custom-field-create" data-role="custom-field-add">+ Add field</button>
                @endif
            </x-slot>

            @if ($fields->isEmpty())
                <p class="p-2 mb-0 text-muted" data-role="custom-fields-empty">No custom fields yet. Add one — for example "Event date" — to start collecting it.</p>
            @else
                <div class="table-responsive">
                    <table class="table mb-0" data-role="custom-field-list">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Merge field</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fields as $field)
                                <tr data-role="custom-field" data-key="{{ $field->key }}">
                                    <td>{{ $field->label }}</td>
                                    <td>{{ \App\Enums\CustomFields\CustomFieldType::from($field->type)->label() }}</td>
                                    <td>
                                        <code data-role="custom-field-token">{{ $field->token() }}</code>
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-50" data-copy="{{ $field->token() }}" data-role="custom-field-copy">Copy</button>
                                    </td>
                                    <td>
                                        @if ($field->isArchived())
                                            <x-badge variant="neutral">Archived</x-badge>
                                        @else
                                            <x-badge variant="success">Active</x-badge>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($canManage)
                                            <form method="POST" action="{{ $route('move', [$field->uid]) }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="btn btn-link btn-sm p-0" aria-label="Move {{ $field->label }} up" @disabled($loop->first)>↑</button>
                                            </form>
                                            <form method="POST" action="{{ $route('move', [$field->uid]) }}" class="d-inline">
                                                @csrf
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="btn btn-link btn-sm p-0 me-50" aria-label="Move {{ $field->label }} down" @disabled($loop->last)>↓</button>
                                            </form>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#custom-field-edit-{{ $field->uid }}" data-role="custom-field-edit">Edit</button>
                                            @if ($field->isArchived())
                                                <form method="POST" action="{{ $route('restore', [$field->uid]) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" data-role="custom-field-restore">Restore</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ $route('archive', [$field->uid]) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm" data-role="custom-field-archive">Archive</button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <p class="text-caption mt-1">
            Renaming a field never changes its merge field, so existing messages and automations keep working.
            Archiving hides a field from new use but keeps its values and anything that already uses it.
        </p>
    </section>

    @if ($canManage)
        <x-dialog id="custom-field-create" title="Add a custom field">
            <form method="POST" action="{{ $route('store') }}" data-role="custom-field-create-form">
                @csrf
                <div class="mb-1">
                    <label for="cf-label" class="form-label">Name</label>
                    <input id="cf-label" name="label" class="form-control" maxlength="80" required value="{{ old('label') }}" placeholder="Event date">
                </div>
                <div class="mb-1">
                    <label for="cf-type" class="form-label">Type</label>
                    <select id="cf-type" name="type" class="form-select" data-role="custom-field-type" required>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">The type can't be changed later.</small>
                </div>
                <div class="mb-1" data-role="custom-field-options" hidden>
                    <label for="cf-options" class="form-label">Options <span class="text-muted">(one per line)</span></label>
                    <textarea id="cf-options" name="options" rows="4" class="form-control">{{ old('options') }}</textarea>
                </div>
                <p class="text-caption">The merge field is created from the name — for example <code>@{{contact.event_date}}</code> — and stays the same if you rename the field.</p>
                <div class="text-end">
                    <button type="submit" class="btn btn-primary">Add field</button>
                </div>
            </form>
        </x-dialog>

        @foreach ($fields as $field)
            <x-dialog :id="'custom-field-edit-' . $field->uid" :title="'Edit ' . $field->label">
                <form method="POST" action="{{ $route('update', [$field->uid]) }}" data-role="custom-field-edit-form">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="cf-edit-label-{{ $field->uid }}">Name</label>
                        <input id="cf-edit-label-{{ $field->uid }}" name="label" class="form-control" maxlength="80" required value="{{ $field->label }}">
                    </div>
                    <p class="text-caption">
                        Type: {{ \App\Enums\CustomFields\CustomFieldType::from($field->type)->label() }} ·
                        Merge field: <code>{{ $field->token() }}</code> (fixed)
                    </p>
                    @if ($field->fieldType()->hasOptions())
                        <div class="mb-1">
                            <p class="form-label mb-25">Options</p>
                            @foreach ($field->optionList() as $i => $option)
                                <div class="mb-50">
                                    <input type="hidden" name="options[{{ $i }}][id]" value="{{ $option['id'] }}">
                                    <input name="options[{{ $i }}][label]" class="form-control form-control-sm" maxlength="80" value="{{ $option['label'] }}" aria-label="Option {{ $i + 1 }}">
                                </div>
                            @endforeach
                            <small class="text-muted d-block mb-50">Rename an option freely — stored answers keep pointing at it. Clear a name to remove it.</small>
                            <label class="form-label" for="cf-new-options-{{ $field->uid }}">Add options <span class="text-muted">(one per line)</span></label>
                            <textarea id="cf-new-options-{{ $field->uid }}" name="new_options" rows="2" class="form-control"></textarea>
                        </div>
                    @endif
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </x-dialog>
        @endforeach

        <script>
            (function () {
                var optionTypes = @json($optionTypes);
                var select = document.querySelector('[data-role="custom-field-type"]');
                var options = document.querySelector('[data-role="custom-field-options"]');

                function sync() {
                    if (select && options) {
                        options.hidden = optionTypes.indexOf(select.value) === -1;
                    }
                }

                if (select) {
                    select.addEventListener('change', sync);
                    sync();
                }

                document.querySelectorAll('[data-copy]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var text = button.getAttribute('data-copy');
                        var done = function () { button.textContent = 'Copied'; setTimeout(function () { button.textContent = 'Copy'; }, 1500); };

                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(text).then(done, function () {});
                        }
                    });
                });
            })();
        </script>
    @endif
@endsection
