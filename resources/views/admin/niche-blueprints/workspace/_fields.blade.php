{{-- Renders a BlueprintComponentDefinition form-field spec as c[name] inputs. --}}
@foreach ($fields as $field)
    @php
        $name = $field['name'];
        $value = old('c.'.$name, $values[$name] ?? '');
        $id = 'f_'.md5(($component->id ?? 'new').json_encode($fields).$name);
    @endphp
    <div class="mb-2">
        <label class="form-label" for="{{ $id }}">{{ $field['label'] }}@if (! empty($field['required'])) <span class="text-danger">*</span>@endif</label>
        @if ($field['type'] === 'textarea' || $field['type'] === 'lines')
            <textarea class="form-control" id="{{ $id }}" name="c[{{ $name }}]" rows="{{ $field['type'] === 'lines' ? 5 : 3 }}">{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
        @elseif ($field['type'] === 'select')
            <select class="form-select" id="{{ $id }}" name="c[{{ $name }}]">
                @if (empty($field['required']))
                    <option value="">—</option>
                @endif
                @foreach ($field['options'] ?? [] as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                @endforeach
            </select>
        @elseif ($field['type'] === 'number')
            <input type="number" class="form-control" id="{{ $id }}" name="c[{{ $name }}]" value="{{ $value }}">
        @else
            <input type="text" class="form-control" id="{{ $id }}" name="c[{{ $name }}]" value="{{ $value }}">
        @endif
        @if (! empty($field['help']))
            <div class="form-text" style="white-space: pre-line">{{ $field['help'] }}</div>
        @endif
    </div>
@endforeach
