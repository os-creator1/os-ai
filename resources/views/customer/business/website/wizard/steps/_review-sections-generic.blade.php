{{--
    The answer sections of the Review page for any other questionnaire (not the guided Photo Booth one): the generic,
    saved-answer summary from WebsiteWizardController::answerSummary(), in the same card and row styling. Inherits
    $answerSummary, $plan, $stepUrl, $nextNumber from _review-page.
--}}
@php
    $ids = ['About your business' => 'rv-about', 'Services & packages' => 'rv-services', 'Website content' => 'rv-content', 'Contact & other' => 'rv-contact'];
@endphp
@foreach ($ids as $heading => $sectionId)
    @php
        $blocks = $answerSummary[$heading] ?? [];
    @endphp
    @continue($blocks === [] && ! ($sectionId === 'rv-content' && $plan))
    <section class="rv-card" id="{{ $sectionId }}" aria-labelledby="{{ $sectionId }}-title">
        <div class="rv-card-head">
            <span class="rv-num" aria-hidden="true">{{ $nextNumber() }}</span>
            <h2 class="rv-title" id="{{ $sectionId }}-title">{{ $heading }}</h2>
        </div>
        <div class="rv-card-body">
            @foreach ($blocks as $block)
                <div class="rv-row" data-summary-block>
                    <div class="rv-row-label">{{ $block['prompt'] }}</div>
                    <div class="rv-row-value">
                        @if (! empty($block['notice']))
                            <div class="rv-notice mb-2" data-summary-notice>{{ $block['notice'] }}</div>
                        @endif

                        @if ($block['kind'] === 'text')
                            {{ $block['value'] }}
                        @elseif ($block['kind'] === 'photos' || $block['kind'] === 'backdrops')
                            <div class="rv-tiles">
                                @foreach ($block['entries'] as $entry)
                                    <div class="rv-tile">
                                        @if ($entry['thumb'])
                                            <img src="{{ $entry['thumb'] }}" alt="{{ $entry['label'] }}" loading="lazy">
                                        @endif
                                        <div class="rv-tile-name">{{ $entry['label'] }}</div>
                                        @if ($entry['meta'])<div class="rv-muted">{{ $entry['meta'] }}</div>@endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <ul class="mb-0 ps-3">
                                @foreach ($block['entries'] as $entry)
                                    <li>{{ $entry['label'] }}@if ($entry['meta']) <span class="rv-muted">&mdash; {{ $entry['meta'] }}</span>@endif</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <a class="rv-edit" href="{{ $stepUrl($block['edit_key']) }}" aria-label="Edit {{ $block['prompt'] }}">Edit</a>
                </div>
            @endforeach

            @if ($sectionId === 'rv-content' && $plan)
                <div class="rv-row" data-testid="website-plan">
                    <div class="rv-row-label">Website plan</div>
                    <div class="rv-row-value">
                        <div data-testid="plan-count">{{ $plan['budget']['planned'] }} pages will be built <span class="rv-muted">(a website is built with at most {{ $plan['budget']['max'] }})</span></div>
                        <details class="rv-more mt-1">
                            <summary>See the pages</summary>
                            @include('customer.business.website.wizard.steps._plan')
                        </details>
                    </div>
                    <span></span>
                </div>
            @endif
        </div>
    </section>
@endforeach

@if ($answerSummary === [] && ! $plan)
    <p class="text-caption">No answers to show yet.</p>
@endif
