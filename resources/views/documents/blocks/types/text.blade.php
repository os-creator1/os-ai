@php($align = in_array($data['align'] ?? null, ['left', 'center', 'right'], true) ? $data['align'] : 'left')
<p class="doc-text doc-align-{{ $align }}">{{ $runs($data['runs'] ?? []) }}</p>
