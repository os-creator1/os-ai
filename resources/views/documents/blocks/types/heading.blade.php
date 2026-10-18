@php($level = max(1, min(3, (int) ($data['level'] ?? 2))))
@php($align = in_array($data['align'] ?? null, ['left', 'center', 'right'], true) ? $data['align'] : 'left')
<h{{ $level }} class="doc-align-{{ $align }}">{{ $runs($data['runs'] ?? []) }}</h{{ $level }}>
