{{--
    A contact's Business-defined Custom Fields: typed controls per field, saved
    all-or-nothing through ContactDirectoryController@updateCustomFields →
    CustomFieldValueService. $customFields is null when the actor may not see
    this Contact's Location (the section is then absent). Raw ids and JSON are
    never shown: option labels are displayed, option ids are only form values.
--}}
@php
    $cfRoute = route('customer.workspaces.businesses.people.custom-fields.update', [$workspaceUid, $businessUid, $contactUid]);
    $cfSettings = route('customer.workspaces.businesses.custom-fields.index', [$workspaceUid, $businessUid]);
    $cfCanEdit = auth()->user()?->can('update_contact');
    $currencyCode = $currencyCode ?? null;
@endphp

<x-card title="Custom fields" data-role="contact-custom-fields">
    @if (session('flash_success'))
        <x-alert variant="success" class="mb-1" role="status" data-role="custom-fields-saved">{{ session('flash_success') }}</x-alert>
    @endif
    @error('custom_fields')
        <x-alert variant="danger" class="mb-1" role="alert" data-role="custom-fields-error">{{ $message }}</x-alert>
    @enderror

    @if (empty($customFields['editable']) && empty($customFields['archived']))
        <p class="text-caption mb-0" data-role="custom-fields-none">
            No custom fields yet.
            <a href="{{ $cfSettings }}">Add fields in Settings</a> to keep details like an event date or venue.
        </p>
    @else
        <form method="POST" action="{{ $cfRoute }}" data-role="custom-fields-form">
            @csrf
            @foreach ($customFields['editable'] as $entry)
                @php
                    $field = $entry['definition'];
                    $name = 'custom_fields[' . $field->uid . ']';
                    $value = old('custom_fields.' . $field->uid, $entry['input']);
                    $id = 'cf-' . $field->uid;
                    $type = $field->type;
                @endphp
                <div class="row mb-1 align-items-start" data-role="custom-field" data-key="{{ $field->key }}">
                    <label class="col-sm-4 col-form-label" for="{{ $id }}">{{ $field->label }}</label>
                    <div class="col-sm-8">
                        @if ($type === 'long_text')
                            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" class="form-control" maxlength="5000" @disabled(! $cfCanEdit)>{{ $value }}</textarea>
                        @elseif ($type === 'number' || $type === 'currency')
                            <input id="{{ $id }}" name="{{ $name }}" type="number" step="any" class="form-control" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @elseif ($type === 'date')
                            <input id="{{ $id }}" name="{{ $name }}" type="date" class="form-control" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @elseif ($type === 'datetime')
                            <input id="{{ $id }}" name="{{ $name }}" type="datetime-local" class="form-control" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @elseif ($type === 'boolean')
                            <select id="{{ $id }}" name="{{ $name }}" class="form-select" @disabled(! $cfCanEdit)>
                                <option value="">Not set</option>
                                <option value="yes" @selected($value === 'yes')>Yes</option>
                                <option value="no" @selected($value === 'no')>No</option>
                            </select>
                        @elseif ($type === 'select')
                            <select id="{{ $id }}" name="{{ $name }}" class="form-select" @disabled(! $cfCanEdit)>
                                <option value="">Not set</option>
                                @foreach ($field->optionList() as $option)
                                    <option value="{{ $option['id'] }}" @selected($value === $option['id'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        @elseif ($type === 'multi_select')
                            <input type="hidden" name="{{ $name }}[]" value="">
                            @foreach ($field->optionList() as $option)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="{{ $id }}-{{ $option['id'] }}" name="{{ $name }}[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], (array) $value, true)) @disabled(! $cfCanEdit)>
                                    <label class="form-check-label" for="{{ $id }}-{{ $option['id'] }}">{{ $option['label'] }}</label>
                                </div>
                            @endforeach
                        @elseif ($type === 'email')
                            <input id="{{ $id }}" name="{{ $name }}" type="email" class="form-control" maxlength="255" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @elseif ($type === 'phone')
                            <input id="{{ $id }}" name="{{ $name }}" type="tel" class="form-control" maxlength="32" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @else
                            <input id="{{ $id }}" name="{{ $name }}" type="text" class="form-control" maxlength="255" value="{{ $value }}" @disabled(! $cfCanEdit)>
                        @endif
                    </div>
                </div>
            @endforeach

            @foreach ($customFields['archived'] as $entry)
                <div class="row mb-1" data-role="custom-field-archived">
                    <span class="col-sm-4 text-muted">{{ $entry['definition']->label }} <small>(archived)</small></span>
                    <span class="col-sm-8">{{ $entry['display'] }}</span>
                </div>
            @endforeach

            @if ($cfCanEdit && ! empty($customFields['editable']))
                <div class="text-end">
                    <x-button type="submit" variant="primary" size="sm">Save custom fields</x-button>
                </div>
            @endif
        </form>
    @endif
</x-card>
