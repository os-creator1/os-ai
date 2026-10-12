@php($services = $onboarding->business?->services ?? collect())

<p>List the services you offer, in the order you want them. Prices are optional and you can add or change services at any time. You can also skip this and finish later.</p>

<form method="POST" action="{{ route('customer.onboarding.services.store') }}" data-role="services-form">
    @csrf

    <div data-role="rows">
        @foreach ($services as $index => $service)
            <x-card class="mb-1" data-row="saved">
                <input type="hidden" name="services[{{ $index }}][id]" value="{{ $service->id }}">
                <div class="row">
                    <div class="col-md-6">
                        <x-input name="services[{{ $index }}][name]" label="Service name" type="text" :value="old('services.'.$index.'.name', $service->name)" required />
                    </div>
                    <div class="col-md-3">
                        <x-input name="services[{{ $index }}][starting_price]" label="Starting price (optional)" type="number" step="0.01" :value="old('services.'.$index.'.starting_price', $service->starting_price)" />
                    </div>
                    <div class="col-md-3 mb-1 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="services[{{ $index }}][is_primary]" id="primary-{{ $index }}" value="1" data-primary @checked(old("services.$index.is_primary", $service->is_primary))>
                            <label class="form-check-label" for="primary-{{ $index }}">Primary</label>
                        </div>
                    </div>
                </div>
                <div data-controls hidden>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-act="up">Move up</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-act="down">Move down</button>
                </div>
            </x-card>
        @endforeach

        @php($newIndex = $services->count())
        <x-card class="mb-1" data-row="new">
            <div class="row">
                <div class="col-md-6">
                    <x-input name="services[{{ $newIndex }}][name]" label="New service name" type="text" :value="old('services.'.$newIndex.'.name')" />
                </div>
                <div class="col-md-3">
                    <x-input name="services[{{ $newIndex }}][starting_price]" label="Starting price (optional)" type="number" step="0.01" :value="old('services.'.$newIndex.'.starting_price')" />
                </div>
                <div class="col-md-3 mb-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="services[{{ $newIndex }}][is_primary]" id="primary-{{ $newIndex }}" value="1" data-primary @checked(old("services.$newIndex.is_primary"))>
                        <label class="form-check-label" for="primary-{{ $newIndex }}">Primary</label>
                    </div>
                </div>
            </div>
            <div data-controls hidden>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-act="up">Move up</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-act="down">Move down</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-act="del">Remove</button>
            </div>
        </x-card>
    </div>

    <div class="d-flex flex-wrap gap-1 mt-1">
        <x-button type="button" variant="outline" size="sm" data-role="add-service" hidden>Add another service</x-button>
        <x-button type="submit" variant="primary">Save and continue</x-button>
    </div>
</form>

<form method="POST" action="{{ route('customer.onboarding.services.skip') }}" class="mt-2" data-role="skip-services">
    @csrf
    <x-button type="submit" variant="outline">Set up services later</x-button>
</form>

<script>
    // Progressive enhancement: the form above works without script. With it, rows can be
    // added, unsaved rows removed, and every row moved; names are re-indexed in page order.
    (function () {
        var form = document.querySelector('[data-role="services-form"]');
        if (!form) { return; }
        var holder = form.querySelector('[data-role="rows"]');
        var addBtn = form.querySelector('[data-role="add-service"]');
        var template = holder.querySelector('[data-row="new"]').cloneNode(true);

        function reindex() {
            Array.prototype.forEach.call(holder.querySelectorAll('[data-row]'), function (row, i) {
                row.querySelectorAll('[name^="services["]').forEach(function (f) {
                    f.name = f.name.replace(/^services\[\d+\]/, 'services[' + i + ']');
                });
                row.querySelectorAll('[id^="primary-"]').forEach(function (f) { f.id = 'primary-' + i; });
                row.querySelectorAll('label[for^="primary-"]').forEach(function (l) { l.setAttribute('for', 'primary-' + i); });
                var rows = holder.querySelectorAll('[data-row]');
                var up = row.querySelector('[data-act="up"]'), down = row.querySelector('[data-act="down"]');
                if (up) { up.disabled = i === 0; }
                if (down) { down.disabled = i === rows.length - 1; }
            });
        }
        function enhance() {
            addBtn.hidden = false;
            holder.querySelectorAll('[data-controls]').forEach(function (c) { c.hidden = false; });
            reindex();
        }
        holder.addEventListener('change', function (e) {
            if (e.target.matches('[data-primary]') && e.target.checked) {
                holder.querySelectorAll('[data-primary]').forEach(function (o) { if (o !== e.target) { o.checked = false; } });
            }
        });
        holder.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) { return; }
            var row = b.closest('[data-row]');
            var act = b.getAttribute('data-act');
            if (act === 'del') { row.remove(); }
            if (act === 'up' && row.previousElementSibling) { holder.insertBefore(row, row.previousElementSibling); }
            if (act === 'down' && row.nextElementSibling) { holder.insertBefore(row.nextElementSibling, row); }
            reindex();
        });
        addBtn.addEventListener('click', function () {
            var fresh = template.cloneNode(true);
            fresh.querySelectorAll('input[type="text"],input[type="number"]').forEach(function (i) { i.value = ''; });
            fresh.querySelectorAll('input[type="checkbox"]').forEach(function (i) { i.checked = false; });
            fresh.querySelectorAll('[data-controls]').forEach(function (c) { c.hidden = false; });
            holder.appendChild(fresh);
            reindex();
        });
        enhance();
    })();
</script>
