{{--
    Home V2 §C — Needs your attention.

    Four honest kinds, each a WORD (never colour alone): Problem, Approval,
    Info and — reserved for work in flight — Processing. Anything the primary
    move already shows is removed upstream, so one problem is never told twice.

    The all-clear is conditional on the sources having been READ: when a source
    failed the card says it could not check, never "all good".
--}}
@php
    $words = ['problem' => 'Problem', 'approval' => 'Approval', 'info' => 'Info', 'processing' => 'In progress'];
@endphp

<section class="h-100" aria-labelledby="home-attention-heading" data-band="needs_attention" data-role="home-attention-section">
    <x-card class="h-100">
        <h2 class="h5 text-section-heading mb-1" id="home-attention-heading">Needs your attention</h2>

        @if($attention['visible'] === [])
            @if($attention['unknown'])
                <p class="mb-0" data-role="attention-unknown">We couldn't check everything just now, so we can't confirm that all is well. Please refresh in a moment.</p>
            @elseif($attention['hasPrimaryException'])
                <p class="mb-0 text-muted" data-role="attention-clear">Nothing else needs your attention.</p>
            @else
                <p class="mb-0" data-role="attention-clear">All clear. Nothing needs your attention right now.</p>
            @endif
        @else
            <ul class="list-unstyled mb-0" data-role="attention-items">
                @foreach($attention['visible'] as $item)
                    @include('customer.dashboard.home._attention-item', ['item' => $item, 'words' => $words])
                @endforeach
            </ul>

            @if($attention['overflow'] !== [])
                <details class="mt-1" data-role="attention-more">
                    <summary class="text-caption">Show {{ count($attention['overflow']) }} more</summary>
                    <ul class="list-unstyled mb-0 mt-1">
                        @foreach($attention['overflow'] as $item)
                            @include('customer.dashboard.home._attention-item', ['item' => $item, 'words' => $words])
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </x-card>
</section>
