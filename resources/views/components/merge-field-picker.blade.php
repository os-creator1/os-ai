@props([
    // MergeFieldRegistry::picker() — ['groups' => [...], 'extra' => [...]]
    'picker' => ['groups' => [], 'extra' => []],
    // CSS selector of the field a token goes into when none has been focused yet.
    'target' => null,
    'label' => 'Insert field',
])

{{--
    THE reusable "Insert field" merge-field picker (one component, one script:
    public/js/merge-fields/insert-field.js). Grouped Contact / Custom fields /
    Business / Location / Opportunity / Appointment; clicking a token inserts
    the canonical {{group.key}} at the cursor, so nobody has to memorise syntax.

    The caller decides which groups exist (MergeFieldRegistry::picker): a group
    the editor cannot resolve is simply not offered. Groups marked `requires`
    are shown only when the host editor says its context supplies them (the
    Automations builder does so from the workflow's trigger).

    $picker['extra'] holds valid tokens that are not offered for NEW use (an
    archived custom field) so the editor does not flag existing text as unknown.
--}}
<div class="merge-field-picker-wrap" data-role="merge-field-picker-wrap">
    <div class="dropdown d-inline-block" data-merge-picker @if ($target) data-default-target="{{ $target }}" @endif data-extra="{{ json_encode($picker['extra'] ?? []) }}">
        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" data-role="merge-field-picker-button">{{ $label }}</button>
        <div class="dropdown-menu" style="max-height: 20rem; overflow-y: auto; min-width: 16rem;">
            @foreach ($picker['groups'] as $group)
                <div data-merge-group="{{ $group['group'] }}" @if (! empty($group['requires'])) data-merge-requires="{{ $group['requires'] }}" hidden @endif>
                    <h6 class="dropdown-header">{{ $group['title'] }}</h6>
                    @foreach ($group['fields'] as $field)
                        <button type="button" class="dropdown-item" data-merge-insert="{{ $field['token'] }}">{{ $field['label'] }}</button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
    <p class="small text-warning mb-0 mt-50" data-merge-warning hidden role="status"></p>
</div>
