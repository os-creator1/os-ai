{{--
    Packages come from the canonical Packages & Products catalog — this
    screen never holds its own copy. Existing packages are listed live (with
    their current price); "Show on my website" chooses which ones the site
    uses (unchecking never deletes anything from the catalog); editing a
    package here edits the catalog row itself; "Add package" creates a new
    catalog package that appears under Packages & Products immediately.
--}}
@php
    $currencyDefault = strtoupper((string) ($business->currency_code ?: 'USD'));
    $packageRows = array_values($packageRows);
    $isFirstSetup = $packageRows === [];
    $f = fn ($index, string $field) => $fieldBase . '[' . $index . '][' . $field . ']';
@endphp

@if (! $isFirstSetup)
    <p class="text-caption mb-2">We found your existing packages. Choose which ones to show on your website, or edit them here — changes update Packages &amp; Products.</p>
@endif

<div data-repeatable data-min-rows="0">
    <div data-repeatable-rows>
        @foreach ($packageRows as $i => $row)
            @php $item = $row['item']; @endphp
            <div class="card card-body mb-2" data-row data-existing-package>
                <input type="hidden" name="{{ $f($i, 'uid') }}" value="{{ $item->uid }}">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label class="form-check mb-0 flex-grow-1">
                        <input class="form-check-input" type="checkbox" name="{{ $f($i, 'include') }}" value="1" @checked($row['included'])>
                        <span class="form-check-label"><strong>{{ $item->name }}</strong>
                            <span class="text-caption ms-1">{{ $item->price_minor !== null ? \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code) : 'Contact for pricing' }}</span></span>
                    </label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="up" aria-label="Move up">&uarr;</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-move-row="down" aria-label="Move down">&darr;</button>
                </div>
                <details class="mt-2">
                    <summary class="text-label">Edit</summary>
                    <div class="mt-2">
                        <div class="mb-2">
                            <label class="form-label">Name</label>
                            <input type="text" name="{{ $f($i, 'name') }}" class="form-control" value="{{ $item->name }}" maxlength="{{ \App\Library\Catalog\CatalogItemManager::NAME_MAX }}">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Price (leave blank for "contact for pricing")</label>
                                <input type="text" inputmode="decimal" name="{{ $f($i, 'price') }}" class="form-control" value="{{ $row['price'] }}">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Currency</label>
                                <input type="text" maxlength="3" name="{{ $f($i, 'currency_code') }}" class="form-control text-uppercase" value="{{ $item->currency_code ?: $currencyDefault }}">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Description</label>
                            <textarea name="{{ $f($i, 'description') }}" rows="2" class="form-control">{{ $row['prose'] }}</textarea>
                        </div>
                        @include('customer.business.website.wizard.steps._feature-list', ['features' => $row['features'], 'nameTemplate' => $fieldBase . '[{i}][features][]', 'index' => $i])
                        <label class="form-check mb-0 mt-2">
                            <input class="form-check-input" type="checkbox" name="{{ $f($i, 'featured') }}" value="1" @checked($item->featured)>
                            <span class="form-check-label">Feature this package</span>
                        </label>
                    </div>
                </details>
            </div>
        @endforeach

        @if ($isFirstSetup)
            @include('customer.business.website.wizard.steps._package-new-row', ['index' => 0])
        @endif
    </div>
    <template data-row-template>
        @include('customer.business.website.wizard.steps._package-new-row', ['index' => '__INDEX__'])
    </template>
    <button type="button" class="btn btn-link px-0" data-add-row>+ Add package</button>
</div>
