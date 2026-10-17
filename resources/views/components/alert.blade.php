@props([
    'variant' => 'neutral', // neutral | accent | success | warning | danger
    'icon' => null,         // accepted for older call sites; the status glyph now follows the variant
    'title' => null,
    'dismissible' => true,
])

@php
    $status = match ($variant) {
        'accent' => 'info',
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        default => 'neutral',
    };
    // Errors and warnings need attention; everything else is calm status feedback.
    $defaultRole = in_array($status, ['danger', 'warning'], true) ? 'alert' : 'status';
    $title = is_string($title) ? trim($title) : '';
@endphp

{{--
    The shared Business OS notification card (see <x-notice-styles>): used for
    session flashes, validation summaries and any other inline feedback.
    Title and message are always escaped. Layout never varies by status.
--}}
<x-notice-styles />
<div {{ $attributes->except('role')->merge(['class' => "ds-notice ds-notice--{$status} ds-alert"]) }} role="{{ $attributes->get('role', $defaultRole) }}">
    <span class="ds-notice__icon" data-role="notice-icon"><x-notice-glyph :variant="$status === 'neutral' ? 'info' : $status" /></span>
    <div class="ds-notice__body" data-role="notice-body">
        @if ($title !== '')
            <strong class="ds-notice__title">{{ $title }}</strong>
        @endif
        @if (! $slot->isEmpty())
            <div class="ds-notice__text">{{ $slot }}</div>
        @endif
    </div>
    @if ($dismissible)
        <button type="button" class="ds-notice__close" data-ds-notice-dismiss aria-label="Dismiss notification"><x-notice-glyph variant="close" /></button>
    @endif
</div>
