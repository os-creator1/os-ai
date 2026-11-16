{{--
    Website Studio -> Packages. The Business's real CatalogItem rows (Business-wide, not Website-only): the setup wizard,
    the Catalog screens and this tab read and write the exact same records. Add / Edit go to the canonical Catalog
    create/edit screens - nothing is duplicated here. A published revision is immutable, so a package change since the
    last publish is flagged (out_of_sync) with the existing safe path: publish again.
--}}
@php
    $createUrl = route('customer.workspaces.businesses.catalog.create', [$workspaceUid, $businessUid]);
@endphp

<div class="mg-pkg-head">
    <p class="mg-pkg-sub">What customers see and book on your website. {{ $catalogItems->count() }} {{ $catalogItems->count() === 1 ? 'package' : 'packages' }}.</p>
    @if ($catalogItems->isNotEmpty())
        <x-button variant="primary" icon="plus" href="{{ $createUrl }}" data-testid="add-package">Add package</x-button>
    @endif
</div>

@if (! empty($catalogSync['out_of_sync']))
    <x-alert variant="warning" class="mb-3" data-catalog-sync>
        Your packages changed after you last published
        @if (! empty($catalogSync['changed']))
            (updated: {{ implode(', ', $catalogSync['changed']) }})@endif
        @if (! empty($catalogSync['removed']))
            (removed: {{ implode(', ', $catalogSync['removed']) }})@endif.
        Your live website still shows the older package details.
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-warning">Publish update</button>
        </form>
    </x-alert>
@endif

@if ($catalogItems->isEmpty())
    <x-empty-state icon="package" title="No packages yet" description="Add the packages customers can book. They appear on your website and everywhere else you quote.">
    <x-slot:action>
        <x-button variant="primary" icon="plus" href="{{ $createUrl }}" data-testid="add-package">Add package</x-button>
    </x-slot:action>
    </x-empty-state>
@else
    <div class="mg-pkg-grid">
        @foreach ($catalogItems as $item)
            @php
                $lines = collect(preg_split('/\R/', (string) $item->description))->map(fn ($l) => trim($l))->filter()->values();
                $asList = $lines->count() > 1;
                $editUrl = route('customer.workspaces.businesses.catalog.edit', [$workspaceUid, $businessUid, $item->uid]);
            @endphp
            <article class="mg-pkg @if ($item->featured) is-featured @endif" data-testid="package-card">
                <div class="mg-pkg-band" aria-hidden="true">
                    <span class="mg-pkg-tag">@if ($item->featured)<x-ds-icon name="star" size="12" /> Featured @else On your site @endif</span>
                </div>
                <div class="mg-pkg-main">
                    <h2 class="mg-pkg-name">{{ $item->name }}</h2>
                    <div class="mg-pkg-body">
                        @if ($asList)
                            <ul class="mg-pkg-list">
                                @foreach ($lines as $line)
                                    <li><x-ds-icon name="check" size="14" /> <span>{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        @elseif ($lines->isNotEmpty())
                            <p class="mg-pkg-desc">{{ $lines->first() }}</p>
                        @endif
                    </div>
                    <div class="mg-pkg-foot">
                        <div class="mg-pkg-price">
                            @if ($item->isQuoteOnly())
                                <span class="mg-pkg-quote">Contact for pricing</span>
                            @else
                                {{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}
                            @endif
                        </div>
                        <x-button variant="secondary" icon="pencil" href="{{ $editUrl }}" data-testid="edit-package">Edit<span class="wb-sr-only"> package</span></x-button>
                    </div>
                </div>
            </article>
        @endforeach
        <a class="mg-pkg-add" href="{{ $createUrl }}"><span class="wb-tile" aria-hidden="true"><x-ds-icon name="plus" size="20" /></span>Add another package<small>e.g. 360 booth, prints add-on</small></a>
    </div>
@endif
