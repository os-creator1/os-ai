{{--
    The Book a Demo form fields, in the approved design's markup (.f / .opt). Shared by the public page and the
    owner's preview, so a field added in the editor can only ever look like the rest. Receives $fields (visible only).
    Consecutive half-width fields share a row; a lone half-width field simply takes a full row.
--}}
@php
    $autocomplete = ['first_name' => 'given-name', 'last_name' => 'family-name', 'email' => 'email', 'phone' => 'tel', 'business_name' => 'organization'];
    $rows = [];
    foreach (array_values($fields) as $i => $field) {
        $prev = $rows ? count($rows) - 1 : null;
        if ($field['half'] && $prev !== null && count($rows[$prev]) === 1 && $rows[$prev][0]['half']) {
            $rows[$prev][] = $field;
        } else {
            $rows[] = [$field];
        }
    }
@endphp
@foreach ($rows as $row)
@if (count($row) > 1)<div class="f-row">@endif
@foreach ($row as $f)
@php
    $name = $f['key'];
    $id = 'mgd-'.str_replace('_', '-', $name);
    $note = (! $f['required'] && $f['show_optional']) ? '<span class="f-note">(optional)</span>' : '';
    $hint = $f['help'] !== '' ? '<span class="f-help">'.e($f['help']).'</span>' : '';
    $err = '<span class="f-err" data-err="'.e($name).'" role="alert" hidden></span>';
@endphp
@switch($f['type'])
@case('long_text')
<label class="f" for="{{ $id }}">{{ $f['label'] }} {!! $note !!}<textarea id="{{ $id }}" name="{{ $name }}" @if ($f['placeholder'] !== '') placeholder="{{ $f['placeholder'] }}" @endif @required($f['required']) maxlength="2000"></textarea>{!! $hint !!}{!! $err !!}</label>
@break
@case('dropdown')
<label class="f" for="{{ $id }}">{{ $f['label'] }} {!! $note !!}<select id="{{ $id }}" name="{{ $name }}" @required($f['required'])><option value="">{{ $f['placeholder'] !== '' ? $f['placeholder'] : 'Choose one' }}</option>@foreach ($f['options'] as $o)<option value="{{ $o['value'] }}">{{ $o['label'] }}</option>@endforeach</select>{!! $hint !!}{!! $err !!}</label>
@break
@case('checkboxes')
@case('radio')
<fieldset class="f-set"><legend>{{ $f['label'] }} {!! $note !!}</legend>@if ($hint !== '')<div style="margin-top:-4px">{!! $hint !!}</div>@endif
<div class="opt-grid">@foreach ($f['options'] as $o)<label class="opt"><input type="{{ $f['type'] === 'radio' ? 'radio' : 'checkbox' }}" name="{{ $name }}{{ $f['type'] === 'radio' ? '' : '[]' }}" value="{{ $o['value'] }}" @if ($f['type'] === 'radio' && $f['required']) required @endif>{{ $o['label'] }}</label>@endforeach</div>{!! $err !!}</fieldset>
@break
@default
<label class="f" for="{{ $id }}">{{ $f['label'] }} {!! $note !!}<input id="{{ $id }}" type="{{ ['email' => 'email', 'phone' => 'tel'][$f['type']] ?? 'text' }}" name="{{ $name }}" @if (isset($autocomplete[$name])) autocomplete="{{ $autocomplete[$name] }}" @endif @if ($f['placeholder'] !== '') placeholder="{{ $f['placeholder'] }}" @endif @required($f['required'])>{!! $hint !!}{!! $err !!}</label>
@endswitch
@endforeach
@if (count($row) > 1)</div>@endif
@endforeach
