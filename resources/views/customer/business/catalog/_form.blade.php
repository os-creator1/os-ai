{{-- Contract 16 §12.E — the shared create/edit fields. Presentation only:
     what a valid name/price/currency IS belongs to CatalogItemManager, and a
     refusal comes back through the `catalog` error bag worded as the manager
     worded it. The price is typed as a plain decimal ("49.99") and converted
     to whole minor units by CatalogMoney before the manager sees it. --}}
<div class="form-group">
    <label for="type">Type</label>
    <select id="type" name="type" class="form-control" required>
        @foreach ($types as $type)
            <option value="{{ $type->value }}" @selected(old('type', $item->type->value ?? 'package') === $type->value)>{{ ucfirst($type->value) }}</option>
        @endforeach
    </select>
</div>

<div class="form-group">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" class="form-control" value="{{ old('name', $item->name ?? '') }}" required>
</div>

<div class="form-group">
    <label for="description">Description</label>
    <textarea id="description" name="description" class="form-control" rows="4">{{ old('description', $item->description ?? '') }}</textarea>
</div>

<div class="form-row">
    <div class="form-group col-md-6">
        <label for="price">Price</label>
        <input type="text" inputmode="decimal" id="price" name="price" class="form-control" value="{{ old('price', $priceInput ?? '') }}" placeholder="49.99" autocomplete="off">
        <small class="form-text text-muted">Leave the price and currency blank for a quote-only item.</small>
    </div>
    <div class="form-group col-md-6">
        <label for="currency_code">Currency</label>
        @php($selectedCurrency = strtoupper((string) old('currency_code', $item->currency_code ?? ($defaultCurrency ?? ''))))
        <select id="currency_code" name="currency_code" class="form-control" autocomplete="off">
            <option value="">No currency (quote only)</option>
            @foreach ($currencies as $currency)
            <option value="{{ $currency->code }}" @selected($selectedCurrency === strtoupper($currency->code))>
                    {{ $currency->name }} ({{ $currency->code }})
                </option>
            @endforeach
        </select>
    </div>
</div>
