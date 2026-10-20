{{-- A section's questions, saved together. $group from SiteEditorPresenter. --}}
<div data-scope="fields" data-group="{{ $group['key'] }}" class="wl-group">
    @foreach ($group['fields'] as $field)
        @include('customer.agency.white-label._field', ['field' => $field, 'canEdit' => $canEdit])
    @endforeach
    @if ($canEdit)
        <button type="button" class="btn btn-link btn-sm p-0" data-reset-group="{{ $group['key'] }}">Reset this section to defaults</button>
    @endif
</div>
