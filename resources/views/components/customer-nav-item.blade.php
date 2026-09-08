@props([
    'item',
    'nested' => false,
])

{{--
    Customer Experience Slice 1B — one entry of the context-aware customer
    menu (App\Library\Navigation\MenuItem). Keeps the Vuexy accordion
    markup the existing menu script drives (nav-item / has-sub / open /
    menu-content), adds the semantics the contract requires: the active
    entry carries aria-current="page", a group carries aria-expanded, and
    the label is the builder's human label unless a translation exists
    (contract §17.1 — never a key path).
--}}
@php
    $translationKey = 'locale.menu.' . $item->label;
    $label = \Illuminate\Support\Facades\Lang::has($translationKey) ? __($translationKey) : $item->label;
    $groupOpen = $item->isGroup() && $item->hasActiveChild();
    $classes = implode(' ', array_filter($nested
        ? [$item->active ? 'active' : null, $groupOpen ? 'open' : null]
        : ['nav-item', $item->isGroup() ? 'has-sub' : null, $item->active ? 'active' : null, $groupOpen ? 'open sidebar-group-active' : null]));
@endphp
<li class="{{ $classes }}" data-nav-key="{{ $item->key }}">
    @if($item->isGroup())
        <a href="javascript:void(0);" class="d-flex align-items-center" role="button" aria-haspopup="true" aria-expanded="{{ $groupOpen ? 'true' : 'false' }}">
            <x-ds-icon :name="$item->icon" aria-hidden="true" />
            <span class="{{ $nested ? 'menu-item' : 'menu-title' }}">{{ $label }}</span>
        </a>
        <ul class="menu-content">
            @foreach($item->children as $child)
                <x-customer-nav-item :item="$child" :nested="true" />
            @endforeach
        </ul>
    @else
        <a href="{{ $item->url }}" class="d-flex align-items-center transition-fast" @if($item->active) aria-current="page" @endif>
            <x-ds-icon :name="$item->icon" aria-hidden="true" />
            <span class="{{ $nested ? 'menu-item' : 'menu-title' }}">{{ $label }}</span>
        </a>
    @endif
</li>
