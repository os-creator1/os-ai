{{--
    One schema-driven question. $field comes from SiteEditorPresenter (path, label, type, value, default, overridden).
    Inputs carry data-path; the editor script saves a section's fields together and the server re-validates every one.
--}}
@php
    $id = 'f-'.md5($field['path']);
    $value = $field['value'];
    $isList = in_array($field['type'], ['strings', 'rows'], true);
@endphp
<div class="wl-field mb-2" data-field data-path="{{ $field['path'] }}" data-type="{{ $field['type'] }}">
    @if ($field['type'] === 'text' || $field['type'] === 'url')
        <label for="{{ $id }}" class="form-label">{{ $field['label'] }}</label>
        <input id="{{ $id }}" class="form-control" type="{{ $field['type'] === 'url' ? 'url' : 'text' }}" value="{{ $value }}" maxlength="{{ $field['max'] ?? 255 }}" data-input @disabled(! $canEdit)>
    @elseif ($field['type'] === 'textarea')
        <label for="{{ $id }}" class="form-label">{{ $field['label'] }}</label>
        <textarea id="{{ $id }}" class="form-control" rows="3" maxlength="{{ $field['max'] ?? 600 }}" data-input @disabled(! $canEdit)>{{ $value }}</textarea>
    @elseif ($field['type'] === 'choice')
        <label for="{{ $id }}" class="form-label">{{ $field['label'] }}</label>
        <select id="{{ $id }}" class="form-select" data-input @disabled(! $canEdit)>
            @foreach ($field['options'] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected($value === $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($field['type'] === 'strings')
        <label class="form-label">{{ $field['label'] }} <span class="text-muted small">(up to {{ $field['items'] }})</span></label>
        <div data-list="strings" data-max="{{ $field['items'] }}" data-length="{{ $field['max'] }}">
            @foreach ((array) $value as $item)
                <div class="input-group mb-1" data-item>
                    <input class="form-control" type="text" value="{{ $item }}" maxlength="{{ $field['max'] }}" data-cell @disabled(! $canEdit)>
                    @if ($canEdit)<button type="button" class="btn btn-outline-secondary" data-remove aria-label="Remove">×</button>@endif
                </div>
            @endforeach
        </div>
        @if ($canEdit)<button type="button" class="btn btn-sm btn-outline-secondary" data-add="strings">Add</button>@endif
    @elseif ($field['type'] === 'rows')
        <label class="form-label">{{ $field['label'] }} <span class="text-muted small">(up to {{ $field['items'] }})</span></label>
        <div data-list="rows" data-max="{{ $field['items'] }}" data-columns='@json(array_map(fn ($c) => $c[0], $field['columns']))' data-labels='@json(array_map(fn ($c) => $c[1], $field['columns']))' data-length="{{ $field['max'] }}">
            @foreach ((array) $value as $row)
                <div class="border rounded p-2 mb-1" data-item>
                    @foreach ($field['columns'] as [$col, $colLabel])
                        <label class="small text-muted mb-0">{{ $colLabel }}</label>
                        @if (($field['max'] ?? 0) > 160 && ! in_array($colLabel, ['Question', 'Problem', 'Title', 'Step name', 'Name', 'Industry name', 'Point', 'Milestone label', 'Milestone'], true))
                            <textarea class="form-control form-control-sm mb-1" rows="2" maxlength="{{ $field['max'] }}" data-cell="{{ $col }}" @disabled(! $canEdit)>{{ $row[$col] ?? '' }}</textarea>
                        @else
                            <input class="form-control form-control-sm mb-1" type="text" maxlength="{{ $field['max'] }}" value="{{ $row[$col] ?? '' }}" data-cell="{{ $col }}" @disabled(! $canEdit)>
                        @endif
                    @endforeach
                    @if ($canEdit)
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-up aria-label="Move up">↑</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-down aria-label="Move down">↓</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($canEdit)<button type="button" class="btn btn-sm btn-outline-secondary" data-add="rows">Add</button>@endif
    @endif
    @if ($field['overridden'] && $canEdit)
        <button type="button" class="btn btn-link btn-sm p-0 ms-1" data-reset-field>Reset to default</button>
    @endif
</div>
