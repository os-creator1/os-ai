{{--
    The "Edit item" dialog (CatalogEdit.dc.html), opened from the list's item
    names. Presentation only: it posts to the SAME update route as the full-page
    edit form, which runs the §6 gate chain and then CatalogItemManager; a
    refusal comes back as JSON worded as the validator or manager worded it.
    Every item name stays a real link to the full-page edit URL, so without
    JavaScript (or without <dialog>) editing works exactly as before.

    "Delete item" is the catalog's archive action — the catalog has no hard
    delete, and past quotes and invoices keep the price they were made with.

    Quote-only: the price and currency controls are DISABLED, so neither is
    submitted and the item is saved with no price.

    Expects: $scope, $types, $currencies, $defaultCurrency, $editData.
--}}
@php
    $edCurrencies = collect($currencies)->map(fn ($currency) => [
        'code' => strtoupper((string) $currency->code),
        'name' => $currency->name,
        'symbol' => trim(str_replace('{PRICE}', '', (string) ($currency->format ?? ''))) ?: strtoupper((string) $currency->code),
    ]);
@endphp

<dialog class="cg-dialog is-edit" id="catalog-edit-dialog" aria-labelledby="catalog-edit-title" aria-describedby="catalog-edit-sub" data-role="catalog-edit-dialog">
    <form method="POST" action="#" novalidate data-role="catalog-edit-form">
        @csrf
        <div class="cg-dlg-head">
            <div>
                <h2 class="cg-dlg-title" id="catalog-edit-title">Edit item</h2>
                <span class="cg-dlg-sub" id="catalog-edit-sub">Changes apply everywhere it's offered.</span>
            </div>
            <button type="button" class="cg-close" data-close aria-label="Close">
                <x-ds-icon name="x" size="16" aria-hidden="true" />
            </button>
        </div>

        <div class="cg-dlg-body">
            <div class="cg-msg is-err" id="catalog-edit-error" role="alert" tabindex="-1" hidden data-role="catalog-edit-error"></div>

            <div class="cg-seg" role="radiogroup" aria-label="Type">
                @foreach (collect($types)->sortBy(fn ($type) => $type->value === 'package' ? 0 : 1) as $type)
                    <label>
                        <input type="radio" name="type" value="{{ $type->value }}">
                        <x-ds-icon :name="$type->value === 'package' ? 'package' : 'tag'" size="16" aria-hidden="true" />
                        {{ ucfirst($type->value) }}
                    </label>
                @endforeach
            </div>

            <div class="cg-field">
                <label for="catalog-edit-name">Name</label>
                <input type="text" class="cg-in" id="catalog-edit-name" name="name" autocomplete="off" required aria-describedby="catalog-edit-name-err">
                <p class="cg-err" id="catalog-edit-name-err" hidden></p>
            </div>

            <div class="cg-field">
                <label for="catalog-edit-description">Description <span>(optional)</span></label>
                <textarea class="cg-in" id="catalog-edit-description" name="description" aria-describedby="catalog-edit-description-err"></textarea>
                <p class="cg-err" id="catalog-edit-description-err" hidden></p>
            </div>

            <div class="cg-field" role="group" aria-labelledby="catalog-edit-price-label">
                <span id="catalog-edit-price-label" style="font-size:13.5px;font-weight:600;color:var(--cg-ink-2)">Price</span>
                <div class="cg-seg is-sm" role="radiogroup" aria-label="Pricing" style="margin-bottom:6px">
                    <label><input type="radio" name="pricing" value="fixed">Fixed price</label>
                    <label><input type="radio" name="pricing" value="quote">Quote only</label>
                </div>
                <div class="cg-money" id="catalog-edit-money">
                    <span class="cg-cc">
                        <span id="catalog-edit-currency-label">Currency</span>
                        <x-ds-icon name="chevron-down" size="14" aria-hidden="true" />
                        <select id="catalog-edit-currency" name="currency_code" aria-label="Currency" aria-describedby="catalog-edit-price-err" autocomplete="off">
                            <option value="" data-symbol="">Choose currency</option>
                            @foreach ($edCurrencies as $currency)
                                <option value="{{ $currency['code'] }}" data-symbol="{{ $currency['symbol'] }}">{{ $currency['code'] }} — {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </span>
                    <span class="cg-sym" id="catalog-edit-symbol" aria-hidden="true"></span>
                    <input type="text" class="cg-in" id="catalog-edit-price" name="price" inputmode="decimal" autocomplete="off" placeholder="0.00" aria-label="Price" aria-describedby="catalog-edit-price-err">
                </div>
                <p class="cg-err" id="catalog-edit-price-err" hidden></p>
                <div class="cg-info" id="catalog-edit-quote-note" hidden>
                    <x-ds-icon name="message-square" size="16" aria-hidden="true" />
                    <span>Shown as &ldquo;Ask for a quote&rdquo; — no price or currency needed.</span>
                </div>
            </div>
        </div>

        <div class="cg-offer" data-role="catalog-edit-offer">
            <x-ds-icon name="map-pin" size="18" aria-hidden="true" />
            <div class="cg-offer-text">
                <b id="catalog-edit-offer-title">Offered at 0 locations</b>
                <span>A location's own price wins there.</span>
            </div>
            <a href="{{ route('customer.workspaces.businesses.catalog.locations.index', $scope) }}" data-role="catalog-edit-manage-locations">Manage by location</a>
        </div>

        <div class="cg-dlg-foot">
            <button type="submit" form="catalog-edit-archive" class="cg-btn cg-del" data-role="catalog-edit-delete">Delete item</button>
            <button type="button" class="cg-btn" data-close data-role="catalog-edit-cancel">Cancel</button>
            <button type="submit" class="cg-btn is-primary" data-role="catalog-edit-submit"><span data-label>Save changes</span></button>
        </div>
    </form>
    <form method="POST" action="#" id="catalog-edit-archive" hidden>@csrf</form>
</dialog>
<script type="application/json" id="catalog-edit-data">{!! json_encode($editData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

<script>
    (function () {
        var dlg = document.getElementById('catalog-edit-dialog');
        if (!dlg || typeof dlg.showModal !== 'function') { return; } // names keep linking to the full-page form
        var data = {};
        try { data = JSON.parse(document.getElementById('catalog-edit-data').textContent) || {}; } catch (e) { return; }

        var form = dlg.querySelector('[data-role="catalog-edit-form"]');
        var archiveForm = document.getElementById('catalog-edit-archive');
        var submit = form.querySelector('[data-role="catalog-edit-submit"]');
        var del = form.querySelector('[data-role="catalog-edit-delete"]');
        var label = submit.querySelector('[data-label]');
        var general = document.getElementById('catalog-edit-error');
        var money = document.getElementById('catalog-edit-money');
        var priceInput = document.getElementById('catalog-edit-price');
        var currency = document.getElementById('catalog-edit-currency');
        var currencyLabel = document.getElementById('catalog-edit-currency-label');
        var symbol = document.getElementById('catalog-edit-symbol');
        var quoteNote = document.getElementById('catalog-edit-quote-note');
        var nameInput = document.getElementById('catalog-edit-name');
        var offerTitle = document.getElementById('catalog-edit-offer-title');
        var fields = {
            name: { input: nameInput, error: document.getElementById('catalog-edit-name-err') },
            description: { input: document.getElementById('catalog-edit-description'), error: document.getElementById('catalog-edit-description-err') },
            price: { input: priceInput, error: document.getElementById('catalog-edit-price-err') },
            currency_code: { input: currency, error: document.getElementById('catalog-edit-price-err') }
        };
        var opener = null, busy = false;

        function isFixed() { return form.elements.pricing.value === 'fixed'; }
        function syncCurrency() {
            var option = currency.options[currency.selectedIndex];
            currencyLabel.textContent = currency.value || 'Currency';
            symbol.textContent = option ? (option.getAttribute('data-symbol') || '') : '';
        }
        function syncPricing() {
            var fixed = isFixed();
            money.hidden = !fixed; quoteNote.hidden = fixed;
            priceInput.disabled = !fixed; currency.disabled = !fixed;   // not submitted => no price saved
            if (!fixed) { clearFieldErrors(); }
        }
        function setBusy(value) {
            busy = value; submit.disabled = value; del.disabled = value;
            submit.setAttribute('aria-busy', value ? 'true' : 'false');
            label.textContent = value ? 'Saving…' : 'Save changes';
            var spin = submit.querySelector('.cg-spin');
            if (value && !spin) { spin = document.createElement('span'); spin.className = 'cg-spin'; spin.setAttribute('aria-hidden', 'true'); submit.insertBefore(spin, label); }
            else if (!value && spin) { spin.remove(); }
        }
        function clearFieldErrors() {
            Object.keys(fields).forEach(function (k) { var f = fields[k]; f.input.removeAttribute('aria-invalid'); f.error.hidden = true; f.error.textContent = ''; });
            money.classList.remove('is-invalid');
        }
        function clearErrors() { clearFieldErrors(); general.hidden = true; general.textContent = ''; }
        function fieldError(key, message) {
            var f = fields[key]; f.input.setAttribute('aria-invalid', 'true'); f.error.textContent = message; f.error.hidden = false;
            if (key === 'price' || key === 'currency_code') { money.classList.add('is-invalid'); }
        }
        function generalError(message) { general.textContent = message; general.hidden = false; }
        function focusFirstProblem() {
            var order = ['name', 'description', 'currency_code', 'price'];
            for (var i = 0; i < order.length; i++) { if (fields[order[i]].input.getAttribute('aria-invalid') === 'true') { fields[order[i]].input.focus(); return; } }
            if (!general.hidden) { general.focus(); }
        }
        function showServerErrors(errors, fallback) {
            var shown = false;
            Object.keys(errors || {}).forEach(function (key) {
                var message = [].concat(errors[key])[0];
                if (fields[key]) { fieldError(key, message); } else { generalError(message); }
                shown = true;
            });
            if (!shown) { generalError(fallback); }
            focusFirstProblem();
        }
        function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }

        function open(from, uid) {
            var item = data[uid]; if (!item) { return false; }
            opener = from;
            form.reset(); clearErrors(); setBusy(false);
            form.action = item.updateUrl; archiveForm.action = item.archiveUrl;
            form.elements.type.value = item.type;
            nameInput.value = item.name;
            fields.description.input.value = item.description;
            form.elements.pricing.value = item.pricing;
            if (item.currency && !Array.prototype.some.call(currency.options, function (o) { return o.value === item.currency; })) {
                var extra = document.createElement('option'); extra.value = item.currency; extra.textContent = item.currency; extra.setAttribute('data-symbol', item.currency);
                currency.appendChild(extra);
            }
            currency.value = item.currency || '';
            priceInput.value = item.price || '';
            syncCurrency(); syncPricing();
            offerTitle.textContent = item.locations === 0 ? 'No locations to offer it at yet'
                : (item.offered === item.locations ? 'Offered at ' + plural(item.offered, 'location') : 'Offered at ' + item.offered + ' of ' + plural(item.locations, 'location'));
            dlg.showModal(); nameInput.focus();
            return true;
        }
        function close() { if (!busy) { dlg.close(); } }

        dlg.addEventListener('close', function () { if (opener && document.contains(opener)) { opener.focus(); } });
        dlg.addEventListener('cancel', function (e) { if (busy) { e.preventDefault(); } });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) { close(); } });
        dlg.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
        currency.addEventListener('change', syncCurrency);
        form.querySelectorAll('input[name="pricing"]').forEach(function (r) { r.addEventListener('change', syncPricing); });

        document.querySelectorAll('[data-catalog-edit]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
                if (open(link, link.getAttribute('data-catalog-edit'))) { e.preventDefault(); }
            });
        });

        archiveForm.addEventListener('submit', function (e) {
            if (busy || !window.confirm('Delete this item? It is archived: no longer offered anywhere, and past quotes and invoices keep the price they were made with.')) { e.preventDefault(); }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (busy) { return; }
            clearErrors();
            if (!nameInput.value.trim()) { fieldError('name', 'Enter a name.'); }
            if (isFixed()) {
                if (!currency.value) { fieldError('currency_code', 'Choose a currency, or switch to Quote only.'); }
                else if (!priceInput.value.trim()) { fieldError('price', 'Enter a price, or switch to Quote only.'); }
            }
            if (dlg.querySelector('[aria-invalid="true"]')) { focusFirstProblem(); return; }

            setBusy(true);
            fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
                .then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (body) {
                        if (response.ok) { window.location.reload(); return; } // same filter/scroll; the flash rides along
                        setBusy(false);
                        if (response.status === 422) { showServerErrors(body.errors, body.message || 'That could not be saved.'); }
                        else if (response.status === 419) { generalError('Your session expired. Refresh the page and try again.'); general.focus(); }
                        else if (response.status === 401 || response.status === 403) { generalError('You don’t have permission to change this catalog.'); general.focus(); }
                        else if (response.status === 404) { generalError('This item is no longer available. Refresh the page.'); general.focus(); }
                        else { generalError('Something went wrong on our side. Nothing was saved — please try again.'); general.focus(); }
                    });
                })
                .catch(function () { setBusy(false); generalError('Couldn’t reach the server. Check your connection — nothing was saved.'); general.focus(); });
        });
    })();
</script>
