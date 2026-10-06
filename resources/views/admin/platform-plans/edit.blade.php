@extends('layouts/contentLayoutMaster')

@section('title', 'Edit ' . $catalog->display_name)

@section('content')
    <section id="admin-platform-plan-edit">
        @include('admin.partials.flash')

        <form method="POST" action="{{ route('admin.platform-plans.update', $catalog->tier->value) }}">
            @csrf
            @method('PUT')

            <div class="card mb-2">
                <div class="card-header"><h4 class="card-title">{{ $catalog->display_name }} plan</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-1">
                            <label class="form-label" for="display_name">Display name</label>
                            <input class="form-control" id="display_name" name="display_name" value="{{ old('display_name', $catalog->display_name) }}" required>
                        </div>
                        <div class="col-md-8 mb-1 d-flex align-items-end gap-2 flex-wrap">
                            <div class="form-check">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $catalog->is_active))>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <div class="form-check">
                                <input type="hidden" name="available_for_signup" value="0">
                                <input class="form-check-input" type="checkbox" id="available_for_signup" name="available_for_signup" value="1" @checked(old('available_for_signup', $catalog->available_for_signup))>
                                <label class="form-check-label" for="available_for_signup">Available for signup</label>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted mb-0">
                        {{ $subscribers }} {{ \Illuminate\Support\Str::plural('Workspace', $subscribers) }} {{ $subscribers === 1 ? 'is' : 'are' }} on this plan.
                        Untick <strong>Active</strong> to archive the plan: it stops being sold and assigned, and every existing subscription and its history stays exactly as it is.
                    </p>
                </div>
            </div>

            <div class="card mb-2">
                <div class="card-header"><h4 class="card-title">Pricing &amp; trial</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="price">Price</label>
                            <input class="form-control" id="price" name="price" inputmode="decimal" value="{{ old('price', $catalog->price) }}" placeholder="e.g. 97.00">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="currency_id">Currency</label>
                            <select class="form-select" id="currency_id" name="currency_id">
                                <option value="">—</option>
                                @foreach ($currencies as $cur)
                                    <option value="{{ $cur->id }}" @selected((string) old('currency_id', $catalog->currency_id) === (string) $cur->id)>{{ $cur->code }} — {{ $cur->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="billing_cycle">Billing cycle</label>
                            <select class="form-select" id="billing_cycle" name="billing_cycle">
                                <option value="monthly" @selected(old('billing_cycle', $catalog->billing_cycle) === 'monthly')>Monthly</option>
                                <option value="yearly" @selected(old('billing_cycle', $catalog->billing_cycle) === 'yearly')>Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="provider_price_id">Stripe Price ID</label>
                            <input class="form-control" id="provider_price_id" name="provider_price_id" value="{{ old('provider_price_id', $catalog->provider_price_id) }}" placeholder="price_…">
                        </div>
                        <div class="col-md-3 mb-1">
                            <div class="form-check mt-2">
                                <input type="hidden" name="trial_enabled" value="0">
                                <input class="form-check-input" type="checkbox" id="trial_enabled" name="trial_enabled" value="1" @checked(old('trial_enabled', $catalog->trial_enabled))>
                                <label class="form-check-label" for="trial_enabled">Offer a free trial</label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="trial_days">Trial length (days)</label>
                            <input class="form-control" id="trial_days" name="trial_days" type="number" min="1" max="730" value="{{ old('trial_days', $catalog->trial_days) }}">
                        </div>
                    </div>
                    <p class="text-muted mb-0">A Stripe Price ID is checked against Stripe before saving, so the catalog can never promise a price Stripe does not charge. Price changes are recorded in the pricing history.</p>
                </div>
            </div>

            <div class="card mb-2">
                <div class="card-header"><h4 class="card-title">Business slots</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="business_slot_included">Businesses included</label>
                            <input class="form-control" id="business_slot_included" name="business_slot_included" type="number" min="1" value="{{ old('business_slot_included', $catalog->business_slot_included) }}" required>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="business_slot_max">Maximum Businesses</label>
                            <input class="form-control" id="business_slot_max" name="business_slot_max" type="number" min="1" value="{{ old('business_slot_max', $catalog->business_slot_max) }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <div class="form-check mt-2">
                                <input type="hidden" name="unlimited_business_slots" value="0">
                                <input class="form-check-input" type="checkbox" id="unlimited_business_slots" name="unlimited_business_slots" value="1" @checked(old('unlimited_business_slots', $catalog->unlimited_business_slots))>
                                <label class="form-check-label" for="unlimited_business_slots">Unlimited</label>
                            </div>
                        </div>
                        @if ($canSlotRatio)
                            <div class="col-md-3 mb-1">
                                <label class="form-label" for="additional_business_slot_price_ratio">Extra-Business price (× plan price)</label>
                                <input class="form-control" id="additional_business_slot_price_ratio" name="additional_business_slot_price_ratio" inputmode="decimal" value="{{ old('additional_business_slot_price_ratio', $catalog->additional_business_slot_price_ratio) }}">
                            </div>
                        @endif
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="location_slot_included">Locations included (per Business)</label>
                            <input class="form-control" id="location_slot_included" name="location_slot_included" type="number" min="0" value="{{ old('location_slot_included', $catalog->location_slot_included) }}" required>
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="location_slot_max">Maximum Locations</label>
                            <input class="form-control" id="location_slot_max" name="location_slot_max" type="number" min="0" value="{{ old('location_slot_max', $catalog->location_slot_max) }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <div class="form-check mt-2">
                                <input type="hidden" name="unlimited_location_slots" value="0">
                                <input class="form-check-input" type="checkbox" id="unlimited_location_slots" name="unlimited_location_slots" value="1" @checked(old('unlimited_location_slots', $catalog->unlimited_location_slots))>
                                <label class="form-check-label" for="unlimited_location_slots">Unlimited</label>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted mb-0">Slot limits apply to new Businesses and Locations. Anything a customer already has stays in place.</p>
                </div>
            </div>

            <div class="card mb-2" id="plan-features">
                <div class="card-header"><h4 class="card-title">What this plan includes</h4></div>
                <div class="card-body">
                    <div class="row">
                        @foreach ($groups as $group => $items)
                            <div class="col-md-4 mb-2">
                                <h6>{{ $group }}</h6>
                                @foreach ($items as $key => $label)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="feature-{{ $key }}" name="feature_keys[]" value="{{ $key }}"
                                               @checked(in_array($key, old('feature_keys', $packaged), true))>
                                        <label class="form-check-label" for="feature-{{ $key }}">
                                            {{ $label }}
                                            @unless ($available[$key] ?? false)
                                                <span class="badge badge-light-secondary">Coming soon</span>
                                            @endunless
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <p class="text-muted mb-0">Features marked "Coming soon" are not built yet; including them changes nothing for customers until they ship. Removing a feature takes it away from every Workspace on this plan.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="mb-1">
                        <label class="form-label" for="reason">Reason for this change</label>
                        <input class="form-control" id="reason" name="reason" value="{{ old('reason') }}" maxlength="500" required placeholder="Recorded in the audit trail">
                    </div>
                    <button class="btn btn-primary" type="submit">Save plan</button>
                    <a class="btn btn-flat-secondary" href="{{ route('admin.platform-plans.index') }}">Back to Plans</a>
                </div>
            </div>
        </form>
    </section>
@endsection
