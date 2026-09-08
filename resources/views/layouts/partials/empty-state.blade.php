{{--
    Customer Experience Slice 2 — the shared empty-state foundation
    (contract §9.2 "Empty states", §17.3; Slice 2 brief §8).

    Include it from a feature view:

        @include('layouts.partials.empty-state', [
            'title'       => 'No campaigns yet',                 // what this area is
            'explanation' => 'Campaigns you send appear here.',  // why it is empty / unavailable
            'state'       => 'empty',                            // empty | unconfigured | locked
            'primary'     => ['label' => 'Create a campaign', 'url' => route(...)],   // optional
            'secondary'   => ['label' => 'Learn how campaigns work', 'url' => '...'], // optional
            'ownerHint'   => 'Your account owner can turn this on.',   // who can change it, when the user cannot
            'icon'        => 'send',                             // optional, decorative by default
            'iconLabel'   => null,                               // give the icon an accessible name only when it carries meaning
        ])

    Every field is plain text; nothing here is rendered unescaped. The
    three states carry a visible word and a data attribute, never colour
    alone (contract §17.2). Feature pages adopt this in their own slices;
    this partial only makes the pattern available through the allowlisted
    layout surface.
--}}
@php
    $state = in_array($state ?? 'empty', ['empty', 'unconfigured', 'locked'], true) ? ($state ?? 'empty') : 'empty';
    $stateLabel = [
        'empty' => __('locale.labels.empty_state_empty'),
        'unconfigured' => __('locale.labels.empty_state_unconfigured'),
        'locked' => __('locale.labels.empty_state_locked'),
    ][$state];
    $stateIcon = ['empty' => 'inbox', 'unconfigured' => 'settings', 'locked' => 'lock'][$state];
    $icon = $icon ?? $stateIcon;
    $iconLabel = $iconLabel ?? null;
    $primary = isset($primary['label'], $primary['url']) ? $primary : null;
    $secondary = isset($secondary['label'], $secondary['url']) ? $secondary : null;
    $ownerHint = $ownerHint ?? null;
    $explanation = $explanation ?? null;
@endphp
<section class="ds-empty-state customer-empty-state text-center py-4 px-2" data-role="empty-state" data-state="{{ $state }}" aria-labelledby="{{ $headingId ?? 'empty-state-title' }}">
    <div class="ds-empty-state-icon mb-2 d-inline-flex align-items-center justify-content-center">
        @if($iconLabel)
            <x-ds-icon :name="$icon" size="28" role="img" :aria-label="$iconLabel" />
        @else
            <x-ds-icon :name="$icon" size="28" aria-hidden="true" />
        @endif
    </div>
    <p class="text-caption text-uppercase mb-50" data-role="empty-state-status">{{ $stateLabel }}</p>
    <h2 class="text-section-heading mb-1 h4" id="{{ $headingId ?? 'empty-state-title' }}">{{ $title }}</h2>
    @if($explanation)
        <p class="text-body mb-2 mx-auto customer-empty-state__explanation">{{ $explanation }}</p>
    @endif
    @if($primary || $secondary)
        <div class="d-flex flex-wrap justify-content-center gap-1 mb-1">
            @if($primary)
                <a class="btn btn-primary" href="{{ $primary['url'] }}" data-role="empty-state-primary">{{ $primary['label'] }}</a>
            @endif
            @if($secondary)
                <a class="btn btn-outline-secondary" href="{{ $secondary['url'] }}" data-role="empty-state-secondary">{{ $secondary['label'] }}</a>
            @endif
        </div>
    @endif
    @if($ownerHint)
        <p class="text-caption mb-0" data-role="empty-state-owner">{{ $ownerHint }}</p>
    @endif
</section>
