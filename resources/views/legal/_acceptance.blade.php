{{--
    Affirmative legal acceptance. Rendered only when every document in the bundle is PUBLISHED
    (LegalAcceptanceRecorder::present returns null otherwise, and then nothing is shown or recorded).
    NEVER pre-checked. $legal = LegalAcceptanceRecorder::present(...); optional $cycle = the plan's real billing interval.
--}}
@php
    $recorder = \App\Library\Legal\LegalAcceptanceRecorder::class;
    $registry = app(\App\Library\Legal\LegalRegistry::class);
    $names = collect($legal['accept'])->map(fn ($d) => '<a href="'.e($registry->url($d->slug)).'" target="_blank" rel="noopener">'.e($d->title).'</a>')->all();
    $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' '.e(__('and')).' '.end($names) : ($names[0] ?? '');
@endphp
<div class="mb-1" data-role="legal-acceptance">
    <input type="hidden" name="{{ $recorder::FINGERPRINT_FIELD }}" value="{{ $legal['fingerprint'] }}">
    <div class="form-check">
        <input class="form-check-input @error($recorder::FIELD) is-invalid @enderror" type="checkbox" name="{{ $recorder::FIELD }}" id="accept_legal" value="1" required>
        <label class="form-check-label" for="accept_legal">
            {!! __('I have read and accept the :documents of :entity (trading as :brand).', ['documents' => $list, 'entity' => e(config('legal.entity.name')), 'brand' => e(config('legal.entity.brand'))]) !!}
        </label>
        @error($recorder::FIELD)
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
    @if (! empty($cycle))
        @php($cycle = str_starts_with(mb_strtolower($cycle), 'every') ? $cycle : 'every '.$cycle)
        <p class="text-muted small mt-50 mb-0" data-role="legal-renewal">{{ __('Your plan renews automatically :cycle until you cancel. Fees are non-refundable except as set out in the Billing, Cancellation & Refund Policy.', ['cycle' => $cycle]) }}</p>
    @endif
    @if (! empty($legal['linked']))
        <p class="text-muted small mt-50 mb-0">
            {{ __('Also see:') }}
            @foreach ($legal['linked'] as $d)<a href="{{ $registry->url($d->slug) }}" target="_blank" rel="noopener">{{ $d->short }}</a>@if (! $loop->last), @endif @endforeach
        </p>
    @endif
</div>
