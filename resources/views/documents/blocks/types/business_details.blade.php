@php($shown = array_values(array_intersect(['name', 'phone', 'email', 'website'], is_array($data['show'] ?? null) ? $data['show'] : [])))
<div class="doc-details">
    @foreach($shown as $field)
        @php($value = $mergeValue('business.' . $field))
        @if($value !== '')
            <div>@if($field === 'name')<strong>{{ $value }}</strong>@elseif($field === 'website' && $safeHref($value))<a href="{{ $value }}" rel="noopener noreferrer nofollow" target="_blank">{{ $value }}</a>@else{{ $value }}@endif</div>
        @elseif(in_array($mode, ['editor', 'template_preview'], true))
            <div><span class="doc-merge">Business {{ $field }}</span></div>
        @endif
    @endforeach
</div>
