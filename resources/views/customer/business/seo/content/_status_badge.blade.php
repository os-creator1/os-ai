{{-- A status chip for an article or an opportunity. Expects $state: draft|scheduled|published|archived|not_covered. Escaped output only. --}}
@php
    $map = [
        'draft' => ['Draft', ''],
        'scheduled' => ['Scheduled', 'is-info'],
        'published' => ['Published', 'is-success'],
        'archived' => ['Archived', 'is-warning'],
        'not_covered' => ['Not covered', 'is-accent'],
    ];
    [$label, $class] = $map[$state] ?? [ucfirst((string) $state), ''];
@endphp
@include('partials.cx._styles')
<span class="cx-pill has-dot {{ $class }}" data-role="status-badge" data-status="{{ $state }}">{{ $label }}</span>
