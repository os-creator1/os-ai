@if($isTemplate || count($schedule) === 0)
    <div class="doc-placeholder" data-role="payment-placeholder">Payment terms &mdash; the deposit, balance and due dates you set on a document appear here.</div>
@else
    <table class="doc-table" data-role="schedule">
        <tbody>
        @foreach($schedule as $item)
            @php($kind = data_get($item, 'kind'))
            @php($kind = $kind instanceof \BackedEnum ? $kind->value : (string) $kind)
            @php($status = data_get($item, 'status'))
            @php($status = $status instanceof \BackedEnum ? $status->value : (string) $status)
            <tr data-role="schedule-item" data-kind="{{ $kind }}">
                <td>{{ ucfirst($kind) }}</td>
                <td>{{ $dueText($item) }}</td>
                <td class="num">{{ $money(data_get($item, 'amount_minor')) }}</td>
                <td data-role="schedule-status">@if($status === 'paid')Paid @elseif($status === 'refunded')Refunded @endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
