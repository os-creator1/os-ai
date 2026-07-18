@extends('layouts/contentLayoutMaster')

@if(isset($coverage))
    @section('title', __('locale.buttons.update_coverage'))
@else
    @section('title', __('locale.buttons.add_coverage'))
@endif

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')

    <style>
        .customized_select2 .select2-selection--multiple {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: calc(1.5em + 0.75rem + 7px) !important;

        }

        .input-group > :not(:first-child):not(.dropdown-menu):not(.valid-tooltip):not(.valid-feedback):not(.invalid-tooltip):not(.invalid-feedback) {
            width: calc(100% - 60px);
        }
    </style>

@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row">
            <div class="col-md-6 col-12">

                <div class="card">
                    <div class="card-header">

                        <h4 class="card-title">@if(isset($coverage))
                                {{ __('locale.buttons.update_coverage') }}
                            @else
                                {{ __('locale.buttons.add_coverage') }}
                            @endif </h4>
                    </div>

                    <div class="card-content">
                        <div class="card-body">
                            <p>{!! __('locale.description.pricing_intro') !!}</p>
                            <div class="form-body">
                                <form class="form form-vertical"
                                      @if(isset($coverage)) action="{{ route('admin.customers.edit_coverage', ['customer' => $customer->uid, 'coverage' => $coverage->uid]) }}"
                                      @else action="{{ route('admin.customers.coverage', $customer->uid) }}"
                                      @endif method="post">
                                    @csrf
                                    <div class="row">

                                        @if(isset($coverage))
                                            <input type="hidden" value="{{ $coverage->country_id }}" name="country">
                                        @else

                                            <div class="col-12">
                                                <label class="form-label">{{ __('locale.labels.country') }}</label>
                                            </div>


                                            <div class="col-md-2 col-12">
                                                <div class="mb-1">

                                                    <div class="input-group">
                                                        <div class="input-group-text">
                                                            <div class="form-check">
                                                                <input type="radio" class="form-check-input select_all"
                                                                       name="country" checked value="0"
                                                                       id="select_all"/>
                                                                <label class="form-check-label"
                                                                       for="select_all">{{ __('locale.labels.all') }}</label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>


                                            <div class="col-md-10 col-12 customized_select2">

                                                <div class="mb-1">
                                                    <div class="input-group">
                                                        <div class="input-group-text">
                                                            <div class="form-check">
                                                                <input type="radio"
                                                                       class="form-check-input select_multiple"
                                                                       name="country" value="select_multiple"
                                                                       id="select_multiple"/>
                                                                <label class="form-check-label"
                                                                       for="select_multiple"></label>
                                                            </div>
                                                        </div>

                                                        <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                                class="form-select select2" id="country"
                                                                name="country[]"
                                                                multiple>
                                                            @foreach($countries as $country)
                                                                <option value="{{$country->id}}"> {{ $country->name }}
                                                                    (+{{$country->country_code}})
                                                                </option>
                                                            @endforeach
                                                        </select>

                                                        @error('country')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                </div>

                                            </div>
                                        @endif


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check me-3 me-lg-5 mt-1">
                                                    <input type="checkbox" class="form-check-input toggle-checkbox"
                                                           {{ !isset($coverage) || (isset($options['plain'])) ? 'checked' : null }}
                                                           id="is_plain"
                                                           value="true" name="plain"
                                                           data-target=".plain-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-primary font-medium-1"
                                                           for="is_plain">{{__('locale.labels.plain')}}</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="plain-sms">

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.labels.plain')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="sending_server"
                                                            name="sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->plain)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="plain_sms"
                                                               class="form-label">{{__('locale.labels.plain_sms')}}</label>
                                                        <input type="text" id="plain_sms"
                                                               class="form-control @error('plain_sms') is-invalid @enderror"
                                                               value="{{ old('plain_sms',  $options['plain_sms'] ?? null) }}"
                                                               name="plain_sms">
                                                        @error('plain_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_plain_sms"
                                                               class="form-label">{{ __('locale.labels.receive') }} {{__('locale.labels.plain_sms')}}</label>
                                                        <input type="text" id="receive_plain_sms"
                                                               class="form-control @error('receive_plain_sms') is-invalid @enderror"
                                                               value="{{ old('receive_plain_sms',  $options['receive_plain_sms'] ?? null) }}"
                                                               name="receive_plain_sms">
                                                        @error('receive_plain_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-success me-3 me-lg-5 mt-1">
                                                    <input type="checkbox"
                                                           class="form-check-input toggle-checkbox"
                                                           id="is_voice"
                                                           value="true" name="voice"
                                                           @if($options['voice_sms'] ?? false) checked @endif
                                                           data-target=".voice-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-success font-medium-1"
                                                           for="is_voice">{{__('locale.labels.voice')}}</label>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="voice-sms sms-type">

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="voice_sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.labels.voice')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="voice_sending_server"
                                                            name="voice_sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->voice)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->voice_sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('voice_sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="voice_sms"
                                                               class=form-label">{{__('locale.labels.voice_sms')}}</label>
                                                        <input type="text" id="voice_sms"
                                                               class="form-control @error('voice_sms') is-invalid @enderror"
                                                               value="{{ old('voice_sms',  $options['voice_sms'] ?? null) }}"
                                                               name="voice_sms">
                                                        @error('voice_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_voice_sms"
                                                               class=form-label">{{__('locale.labels.receive')}} {{__('locale.labels.voice_sms')}}</label>
                                                        <input type="text" id="receive_voice_sms"
                                                               class="form-control @error('receive_voice_sms') is-invalid @enderror"
                                                               value="{{ old('receive_voice_sms',  $options['receive_voice_sms'] ?? null) }}"
                                                               name="receive_voice_sms">
                                                        @error('receive_voice_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-info me-3 me-lg-5 mt-1">
                                                    <input type="checkbox"
                                                           class="form-check-input toggle-checkbox"
                                                           id="is_mms"
                                                           value="true"
                                                           name="mms"
                                                           @if($options['mms_sms'] ?? false) checked @endif
                                                           data-target=".mms-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-info font-medium-1"
                                                           for="is_mms">{{__('locale.labels.mms')}}</label>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="mms-sms sms-type">

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="mms_sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.labels.mms')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="mms_sending_server"
                                                            name="mms_sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->mms)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->mms_sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('mms_sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="mms_sms"
                                                               class=form-label">{{__('locale.labels.mms_sms')}}</label>
                                                        <input type="text" id="mms_sms"
                                                               class="form-control @error('mms_sms') is-invalid @enderror"
                                                               value="{{ old('mms_sms',  $options['mms_sms'] ?? null) }}"
                                                               name="mms_sms">
                                                        @error('mms_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_mms_sms"
                                                               class=form-label">{{__('locale.labels.receive')}} {{__('locale.labels.mms_sms')}}</label>
                                                        <input type="text" id="receive_mms_sms"
                                                               class="form-control @error('receive_mms_sms') is-invalid @enderror"
                                                               value="{{ old('receive_mms_sms',  $options['receive_mms_sms'] ?? null) }}"
                                                               name="receive_mms_sms">
                                                        @error('receive_mms_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-warning me-3 me-lg-5 mt-1">
                                                    <input type="checkbox"
                                                           class="form-check-input toggle-checkbox"
                                                           id="is_whatsapp"
                                                           value="true"
                                                           name="whatsapp"
                                                           @if($options['whatsapp_sms'] ?? false) checked @endif
                                                           data-target=".whatsapp-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-warning font-medium-1"
                                                           for="is_whatsapp">{{__('locale.labels.whatsapp')}}</label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="whatsapp-sms sms-type">
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="whatsapp_sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.labels.whatsapp')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="whatsapp_sending_server"
                                                            name="whatsapp_sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->whatsapp)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->whatsapp_sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('whatsapp_sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="whatsapp_sms"
                                                               class=form-label">{{__('locale.labels.whatsapp_sms')}}</label>
                                                        <input type="text" id="whatsapp_sms"
                                                               class="form-control @error('whatsapp_sms') is-invalid @enderror"
                                                               value="{{ old('whatsapp_sms',  $options['whatsapp_sms'] ?? null) }}"
                                                               name="whatsapp_sms">
                                                        @error('whatsapp_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_whatsapp_sms"
                                                               class=form-label">{{__('locale.labels.receive')}} {{__('locale.labels.whatsapp_sms')}}</label>
                                                        <input type="text" id="receive_whatsapp_sms"
                                                               class="form-control @error('receive_whatsapp_sms') is-invalid @enderror"
                                                               value="{{ old('receive_whatsapp_sms',  $options['receive_whatsapp_sms'] ?? null) }}"
                                                               name="receive_whatsapp_sms">
                                                        @error('receive_whatsapp_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-danger me-3 me-lg-5 mt-1">
                                                    <input type="checkbox"
                                                           class="form-check-input toggle-checkbox"
                                                           id="is_viber"
                                                           value="true"
                                                           name="viber"
                                                           @if($options['viber_sms'] ?? false) checked @endif
                                                           data-target=".viber-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-danger font-medium-1"
                                                           for="is_viber">{{__('locale.menu.Viber')}}</label>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="viber-sms sms-type">

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="viber_sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.menu.Viber')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="viber_sending_server"
                                                            name="viber_sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->viber)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->viber_sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('viber_sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="viber_sms"
                                                               class=form-label">{{__('locale.labels.viber_sms')}}</label>
                                                        <input type="text" id="viber_sms"
                                                               class="form-control @error('viber_sms') is-invalid @enderror"
                                                               value="{{ old('viber_sms',  $options['viber_sms'] ?? null) }}"
                                                               name="viber_sms">
                                                        @error('viber_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_viber_sms"
                                                               class=form-label">{{__('locale.labels.receive')}} {{__('locale.labels.viber_sms')}}</label>
                                                        <input type="text" id="receive_viber_sms"
                                                               class="form-control @error('receive_viber_sms') is-invalid @enderror"
                                                               value="{{ old('receive_viber_sms',  $options['receive_viber_sms'] ?? null) }}"
                                                               name="receive_viber_sms">
                                                        @error('receive_viber_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-secondary me-3 me-lg-5 mt-1">
                                                    <input type="checkbox"
                                                           class="form-check-input toggle-checkbox"
                                                           id="is_otp"
                                                           value="true"
                                                           name="otp"
                                                           @if($options['otp_sms'] ?? false) checked @endif
                                                           data-target=".otp-sms"
                                                    >
                                                    <label class="form-label text-uppercase text-secondary font-medium-1"
                                                           for="is_otp">{{__('locale.menu.OTP')}}</label>
                                                </div>
                                            </div>
                                        </div>


                                        <div class="otp-sms sms-type">
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="otp_sending_server"
                                                           class="form-label">{{__('locale.plans.sending_server_for_sms', ['sms_type' => __('locale.menu.OTP')])}}</label>
                                                    <select data-placeholder="{{ __('locale.labels.choose_your_option') }}"
                                                            class="form-select select2" id="otp_sending_server"
                                                            name="otp_sending_server">
                                                        @foreach($sending_servers as $server)
                                                            @if($server->otp)
                                                                <option value="{{$server->id}}"
                                                                        @if(isset($coverage) && $coverage->otp_sending_server == $server->id) selected @endif> {{ $server->name }}</option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @error('otp_sending_server')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="otp_sms"
                                                               class=form-label">{{__('locale.labels.otp_sms')}}</label>
                                                        <input type="text" id="otp_sms"
                                                               class="form-control @error('otp_sms') is-invalid @enderror"
                                                               value="{{ old('otp_sms',  $options['otp_sms'] ?? null) }}"
                                                               name="otp_sms">
                                                        @error('otp_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-12">
                                                    <div class="mb-1">
                                                        <label for="receive_otp_sms"
                                                               class=form-label">{{__('locale.labels.receive')}} {{__('locale.labels.otp_sms')}}</label>
                                                        <input type="text" id="receive_otp_sms"
                                                               class="form-control @error('receive_otp_sms') is-invalid @enderror"
                                                               value="{{ old('receive_otp_sms',  $options['receive_otp_sms'] ?? null) }}"
                                                               name="receive_otp_sms">
                                                        @error('receive_otp_sms')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 mt-2">
                                            <button type="submit" class="btn btn-primary mr-1 mb-1">
                                                <i data-feather="save"></i> {{__('locale.buttons.save')}}
                                            </button>
                                        </div>

                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>


            </div>


            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title text-uppercase text-primary">{{ __('locale.plans.calculate_price') }}</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">

                            <div class="col-12">
                                <div class="mb-1">
                                    <label for="plain_sms_price"
                                           class="form-label">{{ __('locale.plans.price') }}</label>
                                    <div class="input-group input-group-merge mb-2">
                                        <span class="input-group-text ">{{ str_replace('{PRICE}', '', $customer->customer->subscription->plan->currency->format) }}</span>
                                        <input type="number" id="plain_sms_price" class="form-control">
                                    </div>
                                </div>


                                <div class="col-12">
                                    <div class="mb-1">
                                        <label for="plain_sms_unit"
                                               class="form-label">{{ __('locale.plans.unit_detection') }}</label>
                                        <input type="text" id="plain_sms_unit"
                                               class="form-control">

                                        <div id="sms_loader"
                                             class="spinner-border spinner-border-sm text-primary" role="status"
                                             style="display: none;">
                                            <span class="visually-hidden">{{ __('locale.labels.loading') }}...</span>
                                        </div>

                                        <small id="per_credit_cost" class="text-info d-none"></small>
                                        <!-- Error message -->
                                        <small id="sms_error" class="text-danger d-none"></small>
                                        <!-- Error message -->
                                    </div>
                                </div>

                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection
@section('page-script')

    <script>

        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        // Initially hide all elements with class '.sms-type'
        $('.sms-type').hide();

        // Set up event listener for the 'change' event on elements with class '.toggle-checkbox'
        $('.toggle-checkbox').on('change', function () {
            // Retrieve the target element
            let target = $(this).data('target');

            // Toggle visibility based on checkbox state
            $(target).toggle(this.checked);
        })
            // Trigger the 'change' event on page load to handle initial state
            .change();


        // Basic Select2 select
        $(".select2").each(function () {
            let $this = $(this);
            $this.wrap('<div class="position-relative"></div>');
            $this.select2({
                // the following code is used to disable x-scrollbar when click in select input and
                // take 100% width in responsive also
                dropdownAutoWidth: true,
                width: '100%',
                dropdownParent: $this.parent()
            });
        });



        $(document).ready(function () {
          let debounceTimer;

          function calculateFromPrice(price) {
            let planId = {{ $customer->customer->subscription->plan->id }};

            $('#sms_error').addClass('d-none');
            $('#per_credit_cost').addClass('d-none');
            $('#plain_sms_unit').val('');
            $('#sms_loader').show();

            $.ajax({
              url: "{{ route('admin.plans.calculate.sms.units') }}",
              type: "POST",
              data: {price: price, plan_id: planId, _token: "{{csrf_token()}}"},
              success: function (response) {
                $('#sms_loader').hide();
                if (response.success) {
                  $('#plain_sms_unit').val(response.units);
                  $('#per_credit_cost').text(response.per_credit_cost).removeClass('d-none');
                } else {
                  $('#sms_error').text(response.message).removeClass('d-none');
                }
              },
              error: function () {
                $('#sms_loader').hide();
                $('#sms_error').text("{{ __('locale.exceptions.something_went_wrong') }}").removeClass('d-none');
              }
            });
          }

          function calculateFromUnits(units) {
            let planId = {{ $customer->customer->subscription->plan->id }};

            $('#sms_error').addClass('d-none');
            $('#per_credit_cost').addClass('d-none');
            $('#plain_sms_price').val('');
            $('#sms_loader').show();

            $.ajax({
              url: "{{ route('admin.plans.calculate.sms.price') }}",
              type: "POST",
              data: {units: units, plan_id: planId, _token: "{{csrf_token()}}"},
              success: function (response) {
                $('#sms_loader').hide();
                if (response.success) {
                  $('#plain_sms_price').val(response.price);
                  $('#per_credit_cost').text(response.per_credit_cost).removeClass('d-none');
                } else {
                  $('#sms_error').text(response.message).removeClass('d-none');
                }
              },
              error: function () {
                $('#sms_loader').hide();
                $('#sms_error').text("{{ __('locale.exceptions.something_went_wrong') }}").removeClass('d-none');
              }
            });
          }

          // Debounced price input handling
          $('#plain_sms_price').on('input', function () {
            clearTimeout(debounceTimer);
            let price = $(this).val();

            if (price > 0) {
              debounceTimer = setTimeout(function () {
                calculateFromPrice(price);
              }, 500);
            }
          });

          // Debounced unit input handling
          $('#plain_sms_unit').on('input', function () {
            clearTimeout(debounceTimer);
            let units = $(this).val();

            if (units > 0) {
              debounceTimer = setTimeout(function () {
                calculateFromUnits(units);
              }, 500);
            }
          });
        });

    </script>
@endsection
