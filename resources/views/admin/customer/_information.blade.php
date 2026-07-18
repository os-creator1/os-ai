<div class="card">
    <div class="card-header border-bottom">
        <h4 class="card-title">{{ __('locale.customer.personal_information') }}</h4>
    </div>
    <div class="card-body pt-1">
        <form class="form form-vertical mt-2 pt-50"
              action="{{ route('admin.customers.update_information', $customer->uid) }}" method="post">
            @csrf
            <div class="row mt-1">
                <div class="col-12 col-md-4">
                    <h5 class="mb-1"><i data-feather="user"></i>{{__('locale.customer.personal_information')}}</h5>

                    <div class="mb-1">
                        <label for="phone" class="form-label required">{{__('locale.labels.phone')}}</label>
                        <input type="text" id="phone" class="form-control @error('phone') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->phone)
                                   value="{{ $customer->customer->phone }}"
                               @endif
                               name="phone" required>
                        @error('phone')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="company" class="form-label">{{__('locale.labels.company')}}</label>
                        <input type="text" id="company" class="form-control @error('company') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->company)
                                   value="{{ $customer->customer->company }}"
                               @endif
                               name="company">
                        @error('company')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="website" class="form-label">{{__('locale.labels.website')}}</label>
                        <input type="url" id="website" class="form-control @error('website') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->website)
                                   value="{{ $customer->customer->website }}"
                               @endif
                               name="website">
                        @error('website')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                </div>
                <div class="col-12 col-md-4">
                    <h5 class="mb-1 mt-2 mt-sm-0"><i data-feather="map-pin"></i> {{__('locale.labels.address')}}</h5>

                    <div class="mb-1">
                        <label for="address" class="form-label required">{{__('locale.labels.address')}}</label>
                        <input type="text" id="address" class="form-control @error('address') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->address)
                                   value="{{ $customer->customer->address }}"
                               @endif
                               name="address"
                               required>
                        @error('address')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="city" class="form-label required">{{__('locale.labels.city')}}</label>
                        <input type="text" id="city" class="form-control @error('city') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->city)
                                   value="{{ $customer->customer->city }}"
                               @endif
                               name="city"
                               required>
                        @error('city')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="state" class="form-label">{{__('locale.labels.state')}}</label>
                        <input type="text" id="state" class="form-control @error('state') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->state)
                                   value="{{ $customer->customer->state }}"
                               @endif
                               name="state">
                        @error('state')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="country" class="form-label required">{{__('locale.labels.country')}}</label>
                        <select class="form-select select2" id="country" name="country">
                            @foreach(\App\Helpers\Helper::countries() as $country)
                                <option value="{{$country['name']}}" {{ isset($customer->customer) && $customer->customer->country == $country['name'] ? 'selected': null }}> {{ $country['name'] }}</option>
                            @endforeach
                        </select>
                        @error('country')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                </div>
                <div class="col-12 col-md-4">

                    <h5 class="mb-1 mt-2 mt-sm-0"><i
                                data-feather="map-pin"></i> {{ __('locale.labels.billing_address') }}</h5>


                    <div class="mb-1">
                        <label for="financial_address" class="form-label">{{__('locale.labels.address')}}</label>
                        <input type="text" id="financial_address"
                               class="form-control @error('financial_address') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->financial_address)
                                   value="{{ $customer->customer->financial_address }}"
                               @endif
                               name="financial_address">
                        @error('financial_address')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="financial_city" class="form-label">{{__('locale.labels.city')}}</label>
                        <input type="text" id="financial_city"
                               class="form-control @error('financial_city') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->financial_city)
                                   value="{{ $customer->customer->financial_city }}"
                               @endif
                               name="financial_city">
                        @error('financial_city')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="financial_postcode" class="form-label">{{__('locale.labels.postcode')}}</label>
                        <input type="text" id="financial_postcode"
                               class="form-control @error('financial_postcode') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->financial_postcode)
                                   value="{{ $customer->customer->financial_postcode }}"
                               @endif
                               name="financial_postcode">
                        @error('financial_postcode')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="mb-1">
                        <label for="tax_number" class="form-label">{{__('locale.labels.tax_number')}}</label>
                        <input type="text" id="tax_number"
                               class="form-control @error('tax_number') is-invalid @enderror"
                               @if(isset($customer->customer) && $customer->customer->tax_number)
                                   value="{{ $customer->customer->tax_number }}"
                               @endif
                               name="tax_number">
                        @error('tax_number')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>


                </div>
                <div class="col-12 d-flex flex-sm-row flex-column justify-content-end mt-1">
                    <button type="submit" class="btn btn-primary glow mb-1 mb-sm-0 mr-0 mr-sm-1"><i
                                data-feather="save"></i> {{ __('locale.buttons.save_changes') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
