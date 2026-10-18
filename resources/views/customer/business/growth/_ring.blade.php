{{--
    Score ring. $value is an integer 0-100 (never null here — the caller shows
    "Not enough data" instead). Color bands are presentation only: >=75 good,
    >=50 fair, else low.
--}}
@php
    $radius = 52;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - max(0, min(100, $value)) / 100);
    $band = $value >= 75 ? 'is-good' : ($value >= 50 ? 'is-fair' : 'is-low');
@endphp
<div class="gc-ring" role="img" aria-label="Growth Score {{ $value }} out of 100" data-role="score-ring">
    <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle class="gc-ring-track" cx="60" cy="60" r="{{ $radius }}"></circle>
        <circle class="gc-ring-value {{ $band }}" cx="60" cy="60" r="{{ $radius }}" stroke-dasharray="{{ round($circumference, 2) }}" stroke-dashoffset="{{ round($offset, 2) }}"></circle>
    </svg>
    <div class="gc-ring-number"><strong data-role="score-value">{{ $value }}</strong><span>out of 100</span></div>
</div>
