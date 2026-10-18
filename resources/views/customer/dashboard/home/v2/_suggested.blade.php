{{--
    Suggested next — up to three recommendations from real state (HomeV2Presenter::suggested), never a metric and never a completed
    item. Each has one action to the real setup flow; one already done, or one this actor cannot reach, is not listed.
--}}
@if($suggested !== [])
    <section class="hv2-section" aria-labelledby="hv2-suggested-title" data-band="suggested" data-role="home-suggested">
        <h2 class="hv2-label" id="hv2-suggested-title"><span class="hv2-num hv2-num--plus" aria-hidden="true">+</span>Suggested next</h2>
        <ul class="hv2-card hv2-side-card hv2-suggest" data-role="suggested-items">
            @foreach($suggested as $item)
                <li data-role="suggested-item" data-suggestion="{{ $item['key'] }}">
                    <span class="hv2-suggest__icon" aria-hidden="true">{{ ['website' => '◎', 'follow_ups' => '↻', 'payments' => '＄', 'email' => '✉', 'booking' => '▦'][$item['key']] ?? '•' }}</span>
                    <span>{{ $item['title'] }}</span>
                    <a href="{{ $item['url'] }}" data-role="suggested-action">{{ $item['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
