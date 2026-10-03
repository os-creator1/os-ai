@if($isTemplate || count($lines) === 0)
    <div class="doc-placeholder" data-role="product-placeholder">Product / pricing block &mdash; the products you add to a document appear here, with totals and the payment schedule.</div>
@else
    @php($showDescription = ($data['show_description'] ?? true) !== false)
    @php($showQuantity = ($data['show_quantity'] ?? true) !== false)
    @php($span = $showQuantity ? 3 : 2)
    <table class="doc-table" data-role="lines">
        <thead>
        <tr>
            <th>Item</th>
            @if($showQuantity)<th class="num">Qty</th>@endif
            <th class="num">Unit price</th>
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $line)
            <tr data-role="line">
                <td>
                    {{ data_get($line, 'name') }}
                    @if($showDescription && data_get($line, 'description'))<div class="doc-muted">{{ data_get($line, 'description') }}</div>@endif
                </td>
                @if($showQuantity)<td class="num">{{ data_get($line, 'quantity') }}</td>@endif
                <td class="num">{{ $money(data_get($line, 'unit_price_minor')) }}</td>
                <td class="num">{{ $money(data_get($line, 'line_total_minor')) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        @if($subtotalMinor !== null && $totalMinor !== null && (int) $subtotalMinor !== (int) $totalMinor)
            <tr><td colspan="{{ $span }}">Subtotal</td><td class="num">{{ $money($subtotalMinor) }}</td></tr>
        @endif
        <tr class="doc-total"><td colspan="{{ $span }}">Total</td><td class="num" data-role="total">{{ $money($totalMinor) }}</td></tr>
        @if(count($schedule) === 2)
            @foreach($schedule as $item)
                @php($kind = data_get($item, 'kind'))
                @php($kind = $kind instanceof \BackedEnum ? $kind->value : (string) $kind)
                <tr data-role="schedule-row" data-kind="{{ $kind }}">
                    <td colspan="{{ $span }}">{{ $kind === 'deposit' ? 'Deposit' : 'Balance' }} <span class="doc-muted">&mdash; {{ $dueText($item) }}</span></td>
                    <td class="num">{{ $money(data_get($item, 'amount_minor')) }}</td>
                </tr>
            @endforeach
        @endif
        </tfoot>
    </table>
@endif
