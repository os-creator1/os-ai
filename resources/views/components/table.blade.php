@props([
    'headers' => [],
])

<div class="table-responsive">
    <table {{ $attributes->merge(['class' => 'table ds-table align-middle']) }}>
        @if (count($headers) > 0)
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th class="text-label text-uppercase text-muted">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
