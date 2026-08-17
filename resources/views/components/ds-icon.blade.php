@props([
    'name',
    'size' => 18,
    'strokeWidth' => 1.75,
])

{{--
    Design System Contract, Milestone 1, §9 item 12 — the single centralized
    icon-rendering seam (contract §4 "centralize icon rendering so the
    library can later be replaced globally"). Backed by Lucide via
    technikermathe/blade-lucide-icons (MIT, built on blade-ui-kit/blade-icons,
    §7 item 2), thin/rounded by construction (Lucide's own default stroke
    style). Uses blade-icons' own svg() helper (not <x-dynamic-component>,
    which does not resolve icon-set-prefixed dynamic names correctly) for
    a name computed at render time. Existing data-feather="..." call sites
    are unmodified in this milestone (§6) — this component is the seam
    future call sites migrate to, one page at a time, without ever
    touching the icon library choice itself again.
--}}
{{ svg('lucide-' . $name, 'ds-icon', $attributes->merge(['width' => $size, 'height' => $size, 'stroke-width' => $strokeWidth])->getAttributes()) }}
