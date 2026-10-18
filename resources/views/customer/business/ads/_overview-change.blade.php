{{-- A period-over-period change, only when the reader produced one (it is null when the previous period has no data). $change is a fraction; $goodWhenUp says which direction is good for this metric. --}}
@if($change !== null)
    @php
        $up = $change > 0.0005;
        $down = $change < -0.0005;
        $good = $goodWhenUp ? $up : $down;
        $bad = $goodWhenUp ? $down : $up;
    @endphp
    <span class="ads-change {{ $good ? 'is-up' : ($bad ? 'is-down' : 'is-flat') }}">
        <x-ds-icon :name="$up ? 'arrow-up' : ($down ? 'arrow-down' : 'minus')" size="12" aria-hidden="true" />
        {{ ($change >= 0 ? '+' : '') . number_format($change * 100, 0) }}% vs previous period
    </span>
@endif
