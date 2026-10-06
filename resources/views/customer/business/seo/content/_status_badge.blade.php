{{-- A status chip for an article or an opportunity. Expects $state: draft|scheduled|published|archived|not_covered. Escaped output only. --}}
@php
    $map = [
        'draft' => ['Draft', 'bg-light-secondary'],
        'scheduled' => ['Scheduled', 'bg-light-info'],
        'published' => ['Published', 'bg-light-success'],
        'archived' => ['Archived', 'bg-light-warning'],
        'not_covered' => ['Not covered', 'bg-light-primary'],
    ];
    [$label, $class] = $map[$state] ?? [ucfirst((string) $state), 'bg-light-secondary'];
@endphp
<span class="badge rounded-pill {{ $class }}" data-role="status-badge" data-status="{{ $state }}">{{ $label }}</span>
