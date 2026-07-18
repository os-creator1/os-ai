@extends('layouts/contentLayoutMaster')

@if(isset($country))
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

                        <h4 class="card-title">@if(isset($country))
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
                                      @if(isset($country)) action="{{ route('admin.sending-servers.edit-coverage', ['server' => $server->uid, 'country' => $country->uid]) }}"
                                      @else action="{{ route('admin.sending-servers.add-coverage', $server->uid) }}"
                                      @endif method="post">
                                    @csrf
                                    <div class="row">

                                        @if(isset($country))
                                            <input type="hidden" value="{{ $country->country_id }}" name="country">
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
                                                                       id="select_all" />
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
                                                                       id="select_multiple" />
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

      let firstInvalid = $("form").find(".is-invalid").eq(0);

      if (firstInvalid.length) {
        $("body, html").stop(true, true).animate({
          "scrollTop": firstInvalid.offset().top - 200 + "px"
        }, 200);
      }


      // Basic Select2 select
      $(".select2").each(function() {
        let $this = $(this);
        $this.wrap("<div class=\"position-relative\"></div>");
        $this.select2({
          // the following code is used to disable x-scrollbar when click in select input and
          // take 100% width in responsive also
          dropdownAutoWidth: true,
          width: "100%",
          dropdownParent: $this.parent()
        });
      });

    </script>
@endsection
