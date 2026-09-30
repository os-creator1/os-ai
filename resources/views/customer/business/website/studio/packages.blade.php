{{--
    Website Builder redesign — the Business's real CatalogItem rows
    (Business-wide, not Website-only). The wizard's package step and this
    view read/write the exact same records. Management here is read-only
    for v1 — creating/editing goes through "Edit setup answers" until a
    dedicated Studio package editor exists (a natural, non-blocking
    follow-up).
--}}
@if ($catalogItems->isEmpty())
    <x-empty-state icon="package" title="No packages yet" description="Add packages through the setup questionnaire." />
@else
    <div class="row">
        @foreach ($catalogItems as $item)
            <div class="col-md-6 mb-3">
                <x-card>
                    <div class="d-flex justify-content-between align-items-start">
                        <strong>{{ $item->name }}</strong>
                        @if ($item->featured)
                            <x-badge variant="accent">Featured</x-badge>
                        @endif
                    </div>
                    <p class="text-caption mb-1">{{ $item->isQuoteOnly() ? 'Contact for pricing' : \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}</p>
                    @if ($item->description)
                        <p class="text-caption mb-0" style="white-space: pre-line;">{{ $item->description }}</p>
                    @endif
                </x-card>
            </div>
        @endforeach
    </div>
@endif
