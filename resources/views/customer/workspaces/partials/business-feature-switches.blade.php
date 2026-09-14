{{--
    Business features: only what the server says this customer can switch
    (BusinessFeatureSettings) is ever sent here — nothing the plan leaves out,
    no locked rows. Each switch saves straight away through the existing
    enable/disable routes (same CSRF, auth and EntitlementManager checks),
    without leaving the page. The switch shows only what the server confirms;
    on any failure it returns to its previous state.

    Shared by an Agency account page (every client account's switches) and a
    Core or Growth Business's Settings hub (its own switches).

    @param string $workspaceUid
    @param array<int, array{uid: string, name: string}> $businesses
    @param array<string, array<int, array{key: string, name: string, description: string, enabled: bool}>> $featureSettings
    @param bool $showHeading        a "Features" heading, when the section sits inside a larger card
    @param bool $showBusinessNames  each Business's name above its switches
--}}
<div id="business-feature-settings" class="{{ ($showHeading ?? false) ? 'mt-2' : '' }}">
    @if ($showHeading ?? false)
    <h5>Features</h5>
    @endif
    <p class="text-caption mb-1">Turn features on or off for {{ ($showBusinessNames ?? true) ? 'each Business' : 'this Business' }}. Changes are saved straight away.</p>
    @foreach ($businesses as $business)
        @if (! empty($featureSettings[$business['uid']] ?? []))
            @if ($showBusinessNames ?? true)
            <h6 class="mt-1">{{ $business['name'] }}</h6>
            @endif
            <ul class="list-group mb-2" data-role="business-features">
                @foreach ($featureSettings[$business['uid']] as $setting)
                    @php $switchId = 'business-feature-' . $business['uid'] . '-' . $loop->index; @endphp
                    <li class="list-group-item d-flex justify-content-between align-items-start" data-role="business-feature">
                        <div class="me-2">
                            <div class="fw-bolder" id="{{ $switchId }}-name">{{ $setting['name'] }}</div>
                            <div class="text-caption" id="{{ $switchId }}-description">{{ $setting['description'] }}</div>
                            <div class="text-danger small mt-25" data-role="business-feature-error" role="alert" hidden></div>
                        </div>
                        <div class="form-check form-switch flex-shrink-0 mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="{{ $switchId }}" data-business-feature-switch data-business-uid="{{ $business['uid'] }}" data-feature="{{ $setting['key'] }}"
                                   data-enable-url="{{ route('customer.workspaces.businesses.features.enable', [$workspaceUid, $business['uid'], $setting['key']]) }}"
                                   data-disable-url="{{ route('customer.workspaces.businesses.features.disable', [$workspaceUid, $business['uid'], $setting['key']]) }}"
                                   aria-describedby="{{ $switchId }}-description" @checked($setting['enabled'])>
                            {{-- The switch is named after the feature; its on/off state is
                                 the switch's own, so the visible word is not read twice. --}}
                            <label class="form-check-label" for="{{ $switchId }}"><span class="visually-hidden">{{ $setting['name'] }}</span><span aria-hidden="true" data-role="business-feature-state">{{ $setting['enabled'] ? 'Enabled' : 'Disabled' }}</span></label>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach
</div>

<script>
    (function () {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var genericError = 'We couldn\'t save that change. Please try again.';

        document.querySelectorAll('input[data-business-feature-switch]').forEach(function (input) {
            var row = input.closest('[data-role="business-feature"]');
            var state = row.querySelector('[data-role="business-feature-state"]');
            var error = row.querySelector('[data-role="business-feature-error"]');
            var saving = false;

            var show = function (enabled) {
                input.checked = enabled;
                state.textContent = enabled ? 'Enabled' : 'Disabled';
            };

            // A second click while a change is being saved does nothing.
            input.addEventListener('click', function (event) {
                if (saving) {
                    event.preventDefault();
                }
            });

            input.addEventListener('change', function () {
                var wanted = input.checked;
                var previous = ! wanted;
                var url = input.getAttribute(wanted ? 'data-enable-url' : 'data-disable-url');

                saving = true;
                input.setAttribute('aria-disabled', 'true');
                row.setAttribute('aria-busy', 'true');
                error.hidden = true;
                error.textContent = '';

                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
                    }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return null;
                    }).then(function (body) {
                        return { ok: response.ok, body: body };
                    });
                }).then(function (result) {
                    if (result.ok && result.body && result.body.status === 'success' && typeof result.body.enabled === 'boolean') {
                        show(result.body.enabled);

                        return;
                    }

                    show(previous);
                    error.textContent = (result.body && typeof result.body.customer_message === 'string') ? result.body.customer_message : genericError;
                    error.hidden = false;
                }).catch(function () {
                    show(previous);
                    error.textContent = genericError;
                    error.hidden = false;
                }).then(function () {
                    saving = false;
                    input.removeAttribute('aria-disabled');
                    row.removeAttribute('aria-busy');
                });
            });
        });
    })();
</script>
