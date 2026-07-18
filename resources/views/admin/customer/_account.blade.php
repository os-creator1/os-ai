<div class="card">
    <div class="card-body py-2 my-25">
        <!-- header section -->
        <div class="d-flex">
            <a href="{{ route('admin.customers.show', $customer->uid) }}" class="me-25">
                <img src="{{ route('admin.customers.avatar', $customer->uid) }}" alt="{{ $customer->displayName() }}"
                     class="uploadedAvatar rounded me-50"
                     height="100"
                     width="100"
                />
            </a>
            <!-- upload and reset button -->
            <div class="d-flex align-items-end mt-75 ms-1">
                <div>
                    @if(isset($customer->customer) && $customer->customer->activeSubscription())
                        <h5 class="mb-1 text-uppercase">
                            {{ $customer->sms_unit == 1 ? __('locale.labels.sms_credit') : __('locale.labels.sms_units') }}
                            : {{ $customer->sms_unit == '-1' ? __('locale.labels.unlimited') : \App\Library\Tool::number_with_delimiter($customer->sms_unit) }}
                        </h5>

                    @else
                        <p>{{ __('locale.subscription.no_active_subscription') }}</p>
                    @endif

                    {{-- Show when no on mobile --}}
                    <div class="d-none d-md-block ">
                        <div class="mt-0 mb-1 d-flex flex-row">
                            <div class="input-group input-group-merge form-password-toggle d-inline-flex">
                                <span class="input-group-text bg-white" style="pointer-events:none"><i
                                            class="" data-feather="link"></i>&nbsp; {{ __('locale.developers.api_token') }} </span>
                                <input type="password"
                                       class="form-control px-1 @error('api_token') is-invalid @enderror"
                                       value="{{ $customer->api_token }}" name="api_token" disabled/>
                                <span class="py-auto input-group-text pe-1 cursor-pointer bg-white rounded btn btn-sm btn-outline-success text-info d-inline-flex align-items-center"

                                      data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      title="{{__('locale.customer.show_hide_api_token')}}"><i
                                            data-feather="eye"></i></span>
                            </div>
                            <span
                                    id="btn-copy"
                                    class="py-auto cursor-pointer bg-white rounded btn btn-sm btn-outline-primary text-primary copy-to-clipboard d-inline-flex align-items-center"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="{{__('locale.customer.copy_api_token')}}"
                                    data-text="{{ $customer->api_token }}">
															<i class="" data-feather="copy"></i>
													</span>
                        </div>
                    </div>

                    @include('admin.customer._update_avatar')
                    @if(isset($customer->customer) && $customer->customer->activeSubscription())
                        @include('admin.customer._add_unit')
                        @include('admin.customer._remove_unit')
                    @endif

                    @if($customer->id !== 1)
                        <a href="{{ route('admin.subscriptions.create', ['customer_id' => $customer->id]) }}"
                           class="btn btn-sm btn-info mb-75 me-75 text-capitalize"><i
                                    data-feather="shopping-cart"></i> {{__('locale.customer.assign_plan')}}</a>

                        <a href="{{ route('admin.customers.login_as', $customer->uid) }}"
                           class="btn btn-sm btn-light btn-outline-success mb-75 me-75 text-capitalize"><i
                                    data-feather="log-in"></i> {{__('locale.customer.login_as_customer')}}</a>

                        <button id="remove-avatar" data-id="{{$customer->uid}}"
                                class="btn btn-sm btn-danger mb-75 me-75"><i
                                    data-feather="trash-2"></i> {{__('locale.labels.remove')}}</button>
                    @endif


                </div>
            </div>
            <!--/ upload and reset button -->
        </div>
        <!--/ header section -->


        {{-- Show on mobile only --}}
        <div class="mt-1 d-block d-md-none ">
            <label for="api_token" class="form-label required"><i
                        class="" data-feather="link"></i>&nbsp; {{__('locale.developers.api_token')}}</label>
            <div class="d-flex flex-row">
                <div class="input-group input-group-merge form-password-toggle">
                    <input type="password" id="api_token"
                           class="form-control px-1 @error('api_token') is-invalid @enderror"
                           value="{{ $customer->api_token }}" name="api_token" disabled/>
                    <span class="py-auto input-group-text cursor-pointer bg-white rounded btn btn-sm btn-outline-success text-info d-inline-flex align-items-center"
                          data-bs-toggle="tooltip"
                          data-bs-placement="top"
                          title="{{__('locale.customer.show_hide_api_token')}}">
										<i data-feather="eye"></i>
									</span>
                </div>
                <span class="py-auto cursor-pointer bg-white rounded btn btn-sm btn-outline-primary text-primary copy-to-clipboard d-inline-flex align-items-center"
                      data-bs-toggle="tooltip"
                      data-bs-placement="top"
                      title="{{__('locale.customer.copy_api_token')}}" data-text="{{ $customer->api_token }}">
									<i data-feather="copy"></i>
							</span>
            </div>
        </div>

        <!-- form -->
        <form class="form form-vertical mt-2 pt-50" action="{{ route('admin.customers.update', $customer->uid) }}"
              method="post">
            @method('PATCH')
            @csrf
            <div class="row">

                <div class="col-12 col-sm-6">
                    <div class="col-12">
                        <div class="mb-1">
                            <label for="email" class="form-label required">{{__('locale.labels.email')}}</label>
                            <input type="email" id="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ $customer->email }}" name="email" required>
                            @error('email')
                            <p><small class="text-danger">{{ $message }}</small></p>
                            @enderror
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="mb-1">
                            <label class="form-label required" for="password">{{ __('locale.labels.password') }}</label>
                            <div class="input-group input-group-merge form-password-toggle">
                                <input type="password" id="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       value="{{ old('password') }}" name="password"/>
                                <span class="input-group-text cursor-pointer"><i data-feather="eye"></i></span>
                            </div>

                            @if($errors->has('password'))
                                <p><small class="text-danger">{{ $errors->first('password') }}</small></p>
                            @else
                                <p><small class="text-primary"> {{__('locale.customer.leave_blank_password')}} </small>
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="mb-1">
                            <label class="form-label required"
                                   for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                            <div class="input-group input-group-merge form-password-toggle">
                                <input type="password" id="password_confirmation"
                                       class="form-control @error('password_confirmation') is-invalid @enderror"
                                       value="{{ old('password_confirmation') }}"
                                       name="password_confirmation"/>
                                <span class="input-group-text cursor-pointer"><i data-feather="eye"></i></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6">
                    <div class="row">
                        <div class="col-12 col-sm-6">
                            <div class="mb-1">
                                <label for="first_name"
                                       class="form-label required">{{__('locale.labels.first_name')}}</label>
                                <input type="text" id="first_name"
                                       class="form-control @error('first_name') is-invalid @enderror"
                                       value="{{ $customer->first_name }}" name="first_name" required>
                                @error('first_name')
                                <p><small class="text-danger">{{ $message }}</small></p>
                                @enderror
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="mb-1">
                                <label for="last_name" class="form-label">{{__('locale.labels.last_name')}}</label>
                                <input type="text" id="last_name"
                                       class="form-control @error('last_name') is-invalid @enderror"
                                       value="{{ $customer->last_name }}" name="last_name">
                                @error('last_name')
                                <p><small class="text-danger">{{ $message }}</small></p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="mb-2">
                            <label for="timezone" class="required form-label">{{__('locale.labels.timezone')}}</label>
                            <select class="select2 form-select" id="timezone" name="timezone">
                                @foreach(\App\Library\Tool::allTimeZones() as $timezone)
                                    <option value="{{$timezone['zone']}}" {{ $customer->timezone == $timezone['zone'] ? 'selected': null }}> {{ $timezone['text'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('timezone')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>

                    <div class="col-12">
                        <div class="mb-1">
                            <label for="locale" class="required form-label">{{__('locale.labels.language')}}</label>
                            <select class="select2 form-select" id="locale" name="locale">
                                @foreach($languages as $language)
                                    <option value="{{ $language->code }}" {{ $customer->locale == $language->code ? 'selected': null }}> {{ $language->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('locale')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>

                <div class="col-12 d-flex flex-sm-row flex-column justify-content-end mt-1">
                    <button type="submit" class="btn btn-primary mt-1 me-1"><i
                                data-feather="save"></i> {{__('locale.buttons.save_changes')}}</button>
                </div>

            </div>
        </form>
        <!--/ form -->
    </div>
</div>
