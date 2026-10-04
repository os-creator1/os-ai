@php
    $f = fn (string $field) => $fieldBase . '[' . $index . '][' . $field . ']';
    $currencyDefault = strtoupper((string) ($business->currency_code ?: 'USD'));
@endphp
<div class="card card-body mb-2" data-row data-new-package>
    <div class="mb-2">
        <label class="form-label">Package name</label>
        <input type="text" name="{{ $f('name') }}" class="form-control" maxlength="{{ \App\Library\Catalog\CatalogItemManager::NAME_MAX }}">
    </div>
    <div class="row">
        <div class="col-md-6 mb-2">
            <label class="form-label">Price (leave blank for "contact for pricing")</label>
            <input type="text" inputmode="decimal" name="{{ $f('price') }}" class="form-control">
        </div>
        <div class="col-md-6 mb-2">
            <label class="form-label">Currency</label>
            <input type="text" maxlength="3" name="{{ $f('currency_code') }}" class="form-control text-uppercase" value="{{ $currencyDefault }}">
        </div>
    </div>
    <div class="mb-2">
        <label class="form-label">Description</label>
        <textarea name="{{ $f('description') }}" rows="2" class="form-control"></textarea>
    </div>
    @include('customer.business.website.wizard.steps._feature-list', ['features' => [], 'nameTemplate' => $fieldBase . '[{i}][features][]'])
    <label class="form-check mb-0 mt-2">
        <input class="form-check-input" type="checkbox" name="{{ $f('featured') }}" value="1">
        <span class="form-check-label">Feature this package</span>
    </label>
    <div class="d-flex gap-2 mt-2">
        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-remove-row>Remove</button>
    </div>
</div>
