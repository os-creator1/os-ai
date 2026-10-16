{{--
    Your account → Information tab. Same route (user.account.update_information),
    same fields and same server validation as before: contact details, address,
    billing address, tax number and the five email-notification switches that the
    customer record really stores (login, sender_id, keyword, subscription,
    promotion). "Same as my address" is a client-side convenience that copies the
    address / city / postcode values into the billing fields before saving.
--}}
@php
    $customer = $user->customer;
    $notifications = $customer->getNotifications();
    $notificationRows = [
        'login' => [__('locale.auth.login'), __('locale.customer.every_single_login')],
        'sender_id' => [__('locale.labels.sender_identity'), __('locale.customer.sender_id_verification')],
        'keyword' => [__('locale.labels.keyword'), __('locale.customer.purchase_keyword')],
        'subscription' => [__('locale.menu.Subscriptions'), __('locale.customer.successful_subscription')],
        'promotion' => [__('locale.labels.promotion'), __('locale.customer.promotional_newsletter')],
    ];
@endphp

<div class="sr-card">
    <form action="{{ route('user.account.update_information') }}" method="post" id="account-information-form">
        @csrf

        <div class="sr-section">
            <div class="sr-section__label">
                <h3>Contact details</h3>
                <p>How MotionGrove can reach you.</p>
            </div>
            <div class="sr-fields">
                <div class="sr-field">
                    <label for="phone">{{ __('locale.labels.phone') }} <span class="text-danger">*</span></label>
                    <input type="number" id="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $customer->phone) }}" name="phone" required>
                    @error('phone')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="company">{{ __('locale.labels.company') }}</label>
                    <input type="text" id="company" class="form-control @error('company') is-invalid @enderror" value="{{ old('company', $customer->company) }}" name="company">
                    @error('company')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field sr-span-2">
                    <label for="website">{{ __('locale.labels.website') }}</label>
                    <input type="url" id="website" placeholder="https://" class="form-control @error('website') is-invalid @enderror" value="{{ old('website', $customer->website) }}" name="website">
                    @error('website')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-section">
            <div class="sr-section__label">
                <h3>Address</h3>
                <p>Where you're based.</p>
            </div>
            <div class="sr-fields">
                <div class="sr-field sr-span-2">
                    <label for="address">{{ __('locale.labels.address') }} <span class="text-danger">*</span></label>
                    <input type="text" id="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address', $customer->address) }}" name="address" required>
                    @error('address')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="city">{{ __('locale.labels.city') }} <span class="text-danger">*</span></label>
                    <input type="text" id="city" class="form-control @error('city') is-invalid @enderror" value="{{ old('city', $customer->city) }}" name="city" required>
                    @error('city')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="state">{{ __('locale.labels.state') }}</label>
                    <input type="text" id="state" class="form-control @error('state') is-invalid @enderror" value="{{ old('state', $customer->state) }}" name="state">
                    @error('state')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="postcode">{{ __('locale.labels.postcode') }}</label>
                    <input type="text" id="postcode" class="form-control @error('postcode') is-invalid @enderror" value="{{ old('postcode', $customer->postcode) }}" name="postcode">
                    @error('postcode')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="country">{{ __('locale.labels.country') }} <span class="text-danger">*</span></label>
                    <select class="form-select select2" id="country" name="country">
                        @foreach(\App\Helpers\Helper::countries() as $country)
                            <option value="{{ $country['name'] }}" {{ old('country', $customer->country) == $country['name'] ? 'selected' : null }}>{{ $country['name'] }}</option>
                        @endforeach
                    </select>
                    @error('country')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-section">
            <div class="sr-section__label">
                <h3>Billing address</h3>
                <p>Shown on your invoices.</p>
            </div>
            <div class="sr-fields">
                <div class="sr-span-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="billing-same-as-address">
                        <label class="form-check-label" for="billing-same-as-address">Same as my address</label>
                    </div>
                </div>
                <div class="sr-field sr-span-2">
                    <label for="financial_address">{{ __('locale.labels.address') }}</label>
                    <input type="text" id="financial_address" class="form-control @error('financial_address') is-invalid @enderror" value="{{ old('financial_address', $customer->financial_address) }}" name="financial_address">
                    @error('financial_address')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="financial_city">{{ __('locale.labels.city') }}</label>
                    <input type="text" id="financial_city" class="form-control @error('financial_city') is-invalid @enderror" value="{{ old('financial_city', $customer->financial_city) }}" name="financial_city">
                    @error('financial_city')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field">
                    <label for="financial_postcode">{{ __('locale.labels.postcode') }}</label>
                    <input type="text" id="financial_postcode" class="form-control @error('financial_postcode') is-invalid @enderror" value="{{ old('financial_postcode', $customer->financial_postcode) }}" name="financial_postcode">
                    @error('financial_postcode')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
                <div class="sr-field sr-span-2">
                    <label for="tax_number">{{ __('locale.labels.tax_number') }}</label>
                    <input type="text" id="tax_number" class="form-control @error('tax_number') is-invalid @enderror" value="{{ old('tax_number', $customer->tax_number) }}" name="tax_number">
                    @error('tax_number')<p class="sr-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="sr-section">
            <div class="sr-section__label">
                <h3>{{ __('locale.labels.email_notifications') }}</h3>
                <p>Choose which emails you get.</p>
            </div>
            <div>
                @foreach ($notificationRows as $key => [$label, $hint])
                    <div class="d-flex align-items-center justify-content-between gap-3 py-2 {{ $loop->last ? '' : 'border-bottom' }}" style="border-color:var(--color-border)!important">
                        <label for="{{ $key }}" class="mb-0" style="font-size:.8125rem;font-weight:500" title="{{ $hint }}">{{ $label }}
                            <span class="d-block fw-normal text-muted" style="font-size:.75rem">{{ $hint }}</span>
                        </label>
                        <label class="sr-switch" for="{{ $key }}">
                            <input type="checkbox" name="notifications[{{ $key }}]" value="yes" id="{{ $key }}" {{ ($notifications[$key] ?? 'no') == 'yes' ? 'checked' : '' }}>
                            <span class="sr-switch__text sr-on">On</span>
                            <span class="sr-switch__text sr-off">Off</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="sr-card__foot">
            <span class="sr-req"><span class="text-danger">*</span> Required</span>
            <button type="submit" class="btn btn-primary">{{ __('locale.buttons.save_changes') }}</button>
        </div>
    </form>
</div>

<script>
    (function () {
        var same = document.getElementById('billing-same-as-address');
        if (!same) { return; }
        var pairs = [['address', 'financial_address'], ['city', 'financial_city'], ['postcode', 'financial_postcode']];
        function copy() {
            pairs.forEach(function (p) {
                var from = document.getElementById(p[0]), to = document.getElementById(p[1]);
                if (from && to) { to.value = from.value; to.readOnly = same.checked; }
            });
        }
        same.addEventListener('change', copy);
        pairs.forEach(function (p) {
            var from = document.getElementById(p[0]);
            if (from) { from.addEventListener('input', function () { if (same.checked) { copy(); } }); }
        });
    })();
</script>
