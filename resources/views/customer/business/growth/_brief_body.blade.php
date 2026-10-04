{{--
    The body of the Daily Brief ($brief from GrowthBriefBuilder). Used by the
    Insights tab and the standalone brief page. It is a presentation of the
    canonical opportunity set — it has no logic of its own.
--}}
@php
    $briefCount = (int) $brief['open_count'];
    $briefIntro = ($briefCount === 1 ? '1 thing needs' : $briefCount . ' things need') . ' your attention today'
        . ($briefCount > count($brief['items']) ? ' — here are the top ' . count($brief['items']) : '') . ':';
@endphp
@if($brief['items'] !== [])
    <p class="mb-1" data-role="brief-intro">{{ $briefIntro }}</p>
    <ol class="gc-bullets" style="grid-template-columns:1fr" data-role="brief-items">
        @foreach($brief['items'] as $i => $card)
            <li style="grid-template-columns:1.5rem 1fr" data-rule="{{ $card['rule_key'] }}">
                <strong>{{ $i + 1 }}.</strong>
                <span>
                    <a href="{{ route('customer.workspaces.businesses.growth.opportunities.show', [$workspaceUid, $businessUid, $card['uid']]) }}">{{ $card['headline'] }}</a>
                    <small class="d-block gc-flat">{{ $card['category_label'] }} · {{ $card['impact'] }} impact</small>
                </span>
            </li>
        @endforeach
    </ol>
@elseif($brief['has_evaluation'])
    <p class="mb-0" data-role="brief-clear">Nothing needs your attention today. You're in good shape.</p>
@else
    <p class="mb-0" data-role="brief-empty">Your first brief appears after the first check of your business.</p>
@endif

@if($brief['positives'] !== [])
    <p class="gc-panel-title mt-2">Positive</p>
    <ul class="gc-bullets" data-role="brief-positives">
        @foreach($brief['positives'] as $line)<li><x-ds-icon name="check-circle" size="16" class="gc-up" /><span>{{ $line }}</span></li>@endforeach
    </ul>
@endif
