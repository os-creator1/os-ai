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
                @if ($item->featured)
                    <span class="mg-pkg-flag"><x-ds-icon name="star" size="12" /> Featured</span>
                @endif
                <h2 class="mg-pkg-name">{{ $item->name }}</h2>
                <div class="mg-pkg-price">
                    @if ($item->isQuoteOnly())
                        <span class="mg-pkg-quote">Contact for pricing</span>
                    @else
                        {{ \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) }}
                    @endif
                </div>
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
                <x-button variant="{{ $item->featured ? 'primary' : 'secondary' }}" icon="pencil" href="{{ $editUrl }}" class="w-100 justify-content-center" data-testid="edit-package">Edit package</x-button>
            </article>
        @endforeach
        <a class="mg-pkg-add" href="{{ $createUrl }}"><x-ds-icon name="plus" size="16" /> Add another package</a>
    </div>
@endif

<style>
    .mg-pkg-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: 1rem; }
    .mg-pkg-sub { margin: 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
    .mg-pkg-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.25rem; align-items: stretch; }
    .mg-pkg { position: relative; display: flex; flex-direction: column; padding: 1.25rem; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .875rem; box-shadow: 0 1px 2px rgba(0,0,0,.03); }
    .mg-pkg.is-featured { border-color: var(--color-primary, #B5524C); box-shadow: 0 4px 16px rgba(181,82,76,.12); }
    .mg-pkg-flag { position: absolute; top: -.7rem; left: 50%; transform: translateX(-50%); display: inline-flex; align-items: center; gap: .25rem; padding: .15rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 600; color: #fff; background: var(--color-primary, #B5524C); }
    .mg-pkg-name { margin: 0; font-size: 1.05rem; font-weight: 600; overflow-wrap: anywhere; }
    .mg-pkg-price { margin: .9rem 0 .6rem; font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
    .mg-pkg-quote { font-size: 1.1rem; color: var(--color-text-muted, #6F6D67); font-weight: 600; }
    .mg-pkg-body { flex: 1 1 auto; margin-bottom: 1.25rem; padding-top: .75rem; border-top: 1px solid var(--color-border, #E5E1DA); }
    .mg-pkg-desc { margin: 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
    .mg-pkg-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .45rem; font-size: .875rem; }
    .mg-pkg-list li { display: flex; gap: .5rem; align-items: flex-start; overflow-wrap: anywhere; }
    .mg-pkg-list svg { flex: none; margin-top: .2rem; color: var(--color-success, #3C8D5A); }
    .mg-pkg-add { grid-column: 1 / -1; display: flex; align-items: center; justify-content: center; gap: .4rem; padding: 1.1rem; border: 1.5px dashed var(--color-border, #D8D3CA); border-radius: .875rem; color: var(--color-text-muted, #6F6D67); text-decoration: none; }
    .mg-pkg-add:hover, .mg-pkg-add:focus-visible { border-color: var(--color-primary, #B5524C); color: var(--color-primary, #B5524C); }
    @media (max-width: 1099.98px) { .mg-pkg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 639.98px) { .mg-pkg-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
