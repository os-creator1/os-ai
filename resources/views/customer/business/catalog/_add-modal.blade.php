{{--
    The "Add to catalog" dialog (CatalogAdd.dc.html). Presentation only: it
    posts to the SAME store route as the full-page create form, which runs the
    §6 gate chain and then CatalogItemManager. Nothing here decides what a valid
    name, price or currency is — a refusal comes back from the server (as JSON
    for this dialog) worded as the manager worded it, and is shown as-is.

    Progressive enhancement: every "Add item" control is a real link to the
    full-page create URL. Without JavaScript (or without <dialog>) that link
    works exactly as before; with both, the script opens this dialog instead.

    Quote-only items: choosing "Quote only" DISABLES the price and currency
    controls, so neither is submitted and the item is saved with no price —
    a price is never invented for it.

    Expects: $scope, $types, $currencies, $defaultCurrency.
--}}
@php
    $dlgDefault = strtoupper((string) ($defaultCurrency ?? ''));
    $dlgCurrencies = collect($currencies)->map(fn ($currency) => [
        'code' => strtoupper((string) $currency->code),
        'name' => $currency->name,
        // Cosmetic only: the symbol around the amount, taken from the
        // currency record's own display format ("${PRICE}" -> "$").
        'symbol' => trim(str_replace('{PRICE}', '', (string) ($currency->format ?? ''))) ?: strtoupper((string) $currency->code),
    ]);
    $dlgSelected = $dlgCurrencies->firstWhere('code', $dlgDefault);
@endphp

<dialog class="cg-dialog" id="catalog-add-dialog" aria-labelledby="catalog-add-title" aria-describedby="catalog-add-sub" data-role="catalog-add-dialog">
    <form method="POST" action="{{ route('customer.workspaces.businesses.catalog.store', $scope) }}" novalidate data-role="catalog-add-form">
        @csrf
        <div class="cg-dlg-head">
            <div>
                <h2 class="cg-dlg-title" id="catalog-add-title">Add to catalog</h2>
                <span class="cg-dlg-sub" id="catalog-add-sub">Offered at every location unless you change it.</span>
            </div>
            <button type="button" class="cg-close" data-close aria-label="Close">
                <x-ds-icon name="x" size="16" aria-hidden="true" />
            </button>
        </div>

        <div class="cg-dlg-body">
            <div class="cg-msg is-err" id="catalog-add-error" role="alert" tabindex="-1" hidden data-role="catalog-add-error"></div>

            <div class="cg-seg" role="radiogroup" aria-label="Type">
                {{-- Package first and pre-selected, as designed (and as the full-page form defaults). --}}
                @foreach (collect($types)->sortBy(fn ($type) => $type->value === 'package' ? 0 : 1) as $type)
                    <label>
                        <input type="radio" name="type" value="{{ $type->value }}" @checked($type->value === 'package')>
                        <x-ds-icon :name="$type->value === 'package' ? 'package' : 'tag'" size="16" aria-hidden="true" />
                        {{ ucfirst($type->value) }}
                    </label>
                @endforeach
            </div>

            <div class="cg-field">
                <label for="catalog-add-name">Name</label>
                <input type="text" class="cg-in" id="catalog-add-name" name="name" autocomplete="off" required aria-describedby="catalog-add-name-err">
                <p class="cg-err" id="catalog-add-name-err" hidden></p>
            </div>

            <div class="cg-field">
                <label for="catalog-add-description">Description <span>(optional)</span></label>
                <textarea class="cg-in" id="catalog-add-description" name="description" placeholder="What's included — hours, prints, props…" aria-describedby="catalog-add-description-err"></textarea>
                <p class="cg-err" id="catalog-add-description-err" hidden></p>
            </div>

            <div class="cg-field" role="group" aria-labelledby="catalog-add-price-label">
                <span id="catalog-add-price-label" style="font-size:13.5px;font-weight:600;color:var(--cg-ink-2)">Price</span>
                <div class="cg-seg is-sm" role="radiogroup" aria-label="Pricing" style="margin-bottom:6px">
                    <label><input type="radio" name="pricing" value="fixed" checked>Fixed price</label>
                    <label><input type="radio" name="pricing" value="quote">Quote only</label>
                </div>
                <div class="cg-money" id="catalog-add-money" data-role="catalog-add-money">
                    <span class="cg-cc">
                        <span id="catalog-add-currency-label">{{ $dlgSelected['code'] ?? 'Currency' }}</span>
                        <x-ds-icon name="chevron-down" size="14" aria-hidden="true" />
                        <select id="catalog-add-currency" name="currency_code" aria-label="Currency" aria-describedby="catalog-add-price-err" autocomplete="off">
                            @unless ($dlgSelected)
                                <option value="" selected data-symbol="">Choose currency</option>
                            @endunless
                            @foreach ($dlgCurrencies as $currency)
                                <option value="{{ $currency['code'] }}" data-symbol="{{ $currency['symbol'] }}" @selected($dlgSelected && $dlgSelected['code'] === $currency['code'])>{{ $currency['code'] }} — {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </span>
                    <span class="cg-sym" id="catalog-add-symbol" aria-hidden="true">{{ $dlgSelected['symbol'] ?? '' }}</span>
                    <input type="text" class="cg-in" id="catalog-add-price" name="price" inputmode="decimal" autocomplete="off" placeholder="0.00" aria-label="Price" aria-describedby="catalog-add-price-err">
                </div>
                <p class="cg-err" id="catalog-add-price-err" hidden></p>
                <p class="cg-err" id="catalog-add-quote-note" hidden style="color:var(--cg-muted)">Customers will be asked for a quote. No price is saved.</p>
            </div>
        </div>

        <div class="cg-dlg-foot">
            <button type="button" class="cg-btn" data-close data-role="catalog-add-cancel">Cancel</button>
            <button type="submit" class="cg-btn is-primary" data-role="catalog-add-submit"><span data-label>Add to catalog</span></button>
        </div>
    </form>
</dialog>

<script>
    (function () {
        var dlg = document.getElementById('catalog-add-dialog');
        if (!dlg || typeof dlg.showModal !== 'function') { return; } // the links keep going to the full-page form

        var form = dlg.querySelector('form');
        var submit = form.querySelector('[data-role="catalog-add-submit"]');
        var label = submit.querySelector('[data-label]');
        var general = document.getElementById('catalog-add-error');
        var money = document.getElementById('catalog-add-money');
        var priceInput = document.getElementById('catalog-add-price');
        var currency = document.getElementById('catalog-add-currency');
        var currencyLabel = document.getElementById('catalog-add-currency-label');
        var symbol = document.getElementById('catalog-add-symbol');
        var quoteNote = document.getElementById('catalog-add-quote-note');
        var nameInput = document.getElementById('catalog-add-name');
        var fields = {
            name: { input: nameInput, error: document.getElementById('catalog-add-name-err') },
            description: { input: document.getElementById('catalog-add-description'), error: document.getElementById('catalog-add-description-err') },
            price: { input: priceInput, error: document.getElementById('catalog-add-price-err') },
            currency_code: { input: currency, error: document.getElementById('catalog-add-price-err') }
        };
        var defaultCurrency = currency.value;
        var opener = null;
        var busy = false;

        function isFixed() { return form.elements.pricing.value === 'fixed'; }

        function syncCurrency() {
            var option = currency.options[currency.selectedIndex];
            currencyLabel.textContent = currency.value || 'Currency';
            symbol.textContent = option ? (option.getAttribute('data-symbol') || '') : '';
        }

        function syncPricing() {
            var fixed = isFixed();
            money.hidden = !fixed;
            priceInput.disabled = !fixed;   // disabled controls are not submitted,
            currency.disabled = !fixed;     // so a quote-only item carries no price
            quoteNote.hidden = fixed;
            if (!fixed) { clearFieldErrors(); }
        }

        function setBusy(value) {
            busy = value;
            submit.disabled = value;
            submit.setAttribute('aria-busy', value ? 'true' : 'false');
            label.textContent = value ? 'Adding…' : 'Add to catalog';
            var spin = submit.querySelector('.cg-spin');
            if (value && !spin) {
                spin = document.createElement('span'); spin.className = 'cg-spin'; spin.setAttribute('aria-hidden', 'true');
                submit.insertBefore(spin, label);
            } else if (!value && spin) { spin.remove(); }
        }

        function clearFieldErrors() {
            Object.keys(fields).forEach(function (key) {
                var f = fields[key];
                f.input.removeAttribute('aria-invalid');
                f.error.hidden = true; f.error.textContent = '';
            });
            money.classList.remove('is-invalid');
        }

        function clearErrors() {
            clearFieldErrors();
            general.hidden = true; general.textContent = '';
        }

        function fieldError(key, message) {
            var f = fields[key];
            f.input.setAttribute('aria-invalid', 'true');
            f.error.textContent = message; f.error.hidden = false;
            if (key === 'price' || key === 'currency_code') { money.classList.add('is-invalid'); }
        }

        function generalError(message) {
            general.textContent = message; general.hidden = false;
        }

        function focusFirstProblem() {
            var order = ['name', 'description', 'currency_code', 'price'];
            for (var i = 0; i < order.length; i++) {
                if (fields[order[i]].input.getAttribute('aria-invalid') === 'true') { fields[order[i]].input.focus(); return; }
            }
            if (!general.hidden) { general.focus(); }
        }

        function showServerErrors(errors, fallback) {
            var shown = false;
            Object.keys(errors || {}).forEach(function (key) {
                var message = [].concat(errors[key])[0];
                if (fields[key]) { fieldError(key, message); shown = true; }
                else { generalError(message); shown = true; }   // catalog, type, pricing: the manager's own wording
            });
            if (!shown) { generalError(fallback); }
            focusFirstProblem();
        }

        function open(from) {
            opener = from || null;
            form.reset();
            currency.value = defaultCurrency;
            syncCurrency(); syncPricing(); clearErrors(); setBusy(false);
            dlg.showModal();
            nameInput.focus();
        }

        function close() {
            if (busy) { return; }
            dlg.close();
        }

        dlg.addEventListener('close', function () {
            if (opener && document.contains(opener)) { opener.focus(); }
        });
        dlg.addEventListener('cancel', function (event) { if (busy) { event.preventDefault(); } });
        dlg.addEventListener('click', function (event) { if (event.target === dlg) { close(); } }); // the backdrop
        dlg.querySelectorAll('[data-close]').forEach(function (button) { button.addEventListener('click', close); });
        currency.addEventListener('change', syncCurrency);
        form.querySelectorAll('input[name="pricing"]').forEach(function (radio) { radio.addEventListener('change', syncPricing); });

        document.querySelectorAll('[data-catalog-add]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }
                event.preventDefault();
                open(link);
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (busy) { return; }
            clearErrors();

            // Quick feedback only — the server re-validates everything.
            if (!nameInput.value.trim()) { fieldError('name', 'Enter a name.'); }
            if (isFixed()) {
                if (!currency.value) { fieldError('currency_code', 'Choose a currency, or switch to Quote only.'); }
                else if (!priceInput.value.trim()) { fieldError('price', 'Enter a price, or switch to Quote only.'); }
            }
            if (dlg.querySelector('[aria-invalid="true"]')) { focusFirstProblem(); return; }

            setBusy(true);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (response.ok) {
                        // Reload the list so the new item is shown from the
                        // server's own rendering; the success flash rides along.
                        window.location.assign(body.redirect || window.location.href);
                        return;
                    }
                    setBusy(false);
                    if (response.status === 422) { showServerErrors(body.errors, body.message || 'That could not be added.'); }
                    else if (response.status === 419) { generalError('Your session expired. Refresh the page and try again.'); general.focus(); }
                    else if (response.status === 401 || response.status === 403) { generalError('You don’t have permission to add to this catalog.'); general.focus(); }
                    else if (response.status === 404) { generalError('This catalog is no longer available.'); general.focus(); }
                    else { generalError('Something went wrong on our side. Nothing was added — please try again.'); general.focus(); }
                });
            }).catch(function () {
                setBusy(false);
                generalError('Couldn’t reach the server. Check your connection — nothing was added.');
                general.focus();
            });
        });
    })();
</script>
