@extends('layouts/contentLayoutMaster')

@section('title', __('locale.keywords.create_new_keyword'))


@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')
    <style>
        .customized_select2 .select2-selection--single {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: 2.75rem !important;
        }
    </style>

@endsection


@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"> {{ __('locale.keywords.create_new_keyword') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            <p>{!!  __('locale.description.keywords') !!} {{config('app.name')}}</p>

                            <form class="form form-vertical" action="{{ route('customer.keywords.store') }}" method="post" enctype="multipart/form-data">
                                @csrf
                                <div class="form-body">
                                    <div class="row">

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="title" class="form-label required">{{ __('locale.labels.title') }}</label>
                                                <input type="text" id="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" name="title" required placeholder="{{__('locale.labels.required')}}" autofocus>
                                                @error('title')
                                                <p><small class="text-danger"> {{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="keyword_name" class="form-label required">{{ __('locale.labels.keyword') }}</label>
                                                <input type="text" id="keyword_name" class="form-control @error('keyword_name') is-invalid @enderror" value="{{ old('keyword_name') }}" name="keyword_name" required placeholder="{{__('locale.labels.required')}}">
                                                @error('keyword_name')
                                                <p><small class="text-danger"> {{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        @if(auth()->user()->customer->getOption('sender_id_verification') == 'yes')
                                            <div class="col-12">
                                                <p class="text-uppercase">{{ __('locale.labels.originator') }}</p>
                                            </div>

                                            @can('view_sender_id')
                                                <div class="col-md-6 col-12 customized_select2">
                                                    <div class="mb-1">
                                                        <label for="sender_id" class="form-label">{{ __('locale.labels.sender_id') }}</label>
                                                        <div class="input-group">
                                                            <div class="input-group-text">
                                                                <div class="form-check">
                                                                    <input type="radio" class="form-check-input sender_id" name="originator" value="sender_id" id="sender_id_check"/>
                                                                    <label class="form-check-label" for="sender_id_check"></label>
                                                                </div>
                                                            </div>

                                                            <select class="form-select select2" id="sender_id" name="sender_id">
                                                                @foreach($sender_ids as $sender_id)
                                                                    <option value="{{$sender_id->sender_id}}">
                                                                        {{ $sender_id->sender_id }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endcan

                                            @can('view_numbers')
                                                <div class="col-md-6 col-12 customized_select2">
                                                    <div class="mb-1">
                                                        <label for="phone_number" class="form-label">{{ __('locale.menu.Phone Numbers') }}</label>
                                                        <div class="input-group">
                                                            <div class="input-group-text">
                                                                <div class="form-check">
                                                                    <input type="radio" class="form-check-input phone_number" value="phone_number" name="originator" id="phone_number_check"/>
                                                                    <label class="form-check-label" for="phone_number_check"></label>
                                                                </div>
                                                            </div>

                                                            <select class="form-select select2" id="phone_number" name="phone_number">
                                                                @foreach($phone_numbers as $number)
                                                                    <option value="{{ $number->number }}"> {{ $number->number }} </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endcan

                                        @else
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="sender_id" class="form-label">{{__('locale.labels.sender_id')}}</label>
                                                    <input type="text" id="sender_id" class="form-control @error('sender_id') is-invalid @enderror" name="sender_id">
                                                    @error('sender_id')
                                                    <p><small class="text-danger">{{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>
                                        @endif

                                        @can('sms_quick_send')
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="reply_text" class="form-label">{{__('locale.keywords.reply_text_recipient')}}</label>
                                                    <textarea class="form-control" id="reply_text" rows="3"
                                                              placeholder="{{ __('locale.labels.type_message') }}"
                                                              name="reply_text">{{old('reply_text')}}</textarea>

                                                    @error('reply_text')
                                                    <p><small class="text-danger"> {{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>
                                        @endcan

                                        @can('voice_quick_send')
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="reply_voice" class="form-label">{{__('locale.keywords.reply_voice_recipient')}}</label>
                                                    <textarea class="form-control" id="reply_voice"
                                                              placeholder="{{ __('locale.labels.type_message') }}"
                                                              rows="3" name="reply_voice">{{old('reply_voice')}}</textarea>

                                                    @error('reply_voice')
                                                    <p><small class="text-danger"> {{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>
                                        @endcan

                                        @can('mms_quick_send')

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="reply_mms" class="form-label">{{ __('locale.keywords.reply_mms_recipient') }}</label>
                                                    <input type="file" name="reply_mms" class="form-control" id="reply_mms" accept="image/*"/>

                                                    @error('reply_mms')
                                                    <p><small class="text-danger"> {{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>

                                        @endcan


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="billing_cycle" class="form-label required">{{__('locale.labels.renew')}}</label>
                                                <select class="form-select" id="billing_cycle" name="billing_cycle">
                                                    <option value="yearly" {{old('billing_cycle') == 'yearly' ? 'selected' : null }}> {{__('locale.labels.yearly')}}</option>
                                                    <option value="daily" {{old('billing_cycle') == 'daily' ? 'selected' : null}}> {{__('locale.labels.daily')}}</option>
                                                    <option value="monthly" {{old('billing_cycle') == 'monthly' ? 'selected' : null}}> {{__('locale.labels.monthly')}}</option>
                                                    <option value="custom" {{old('billing_cycle') == 'custom' ? 'selected' : null}}> {{__('locale.labels.custom')}}</option>
                                                </select>
                                            </div>
                                            @error('billing_cycle')
                                            <p><small class="text-danger"> {{ $message }}</small></p>
                                            @enderror
                                        </div>


                                        <div class="col-sm-6 col-12 show-custom">
                                            <div class="mb-1">
                                                <label for="frequency_amount" class="form-label required">{{__('locale.plans.frequency_amount')}}</label>
                                                <input type="text" id="frequency_amount" class="form-control text-right @error('frequency_amount') is-invalid @enderror" name="frequency_amount" value="{{ old('frequency_amount') }}">
                                                @error('frequency_amount')
                                                <p><small class="text-danger"> {{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-sm-6 col-12 show-custom">
                                            <div class="mb-1">
                                                <label for="frequency_unit" class="form-label required">{{__('locale.plans.frequency_unit')}}</label>
                                                <select class="form-select" id="frequency_unit" name="frequency_unit">
                                                    <option value="day" {{old('frequency_unit') == 'day' ? 'selected' : null}}> {{__('locale.labels.day')}}</option>
                                                    <option value="week" {{old('frequency_unit') == 'week' ? 'selected' : null}}> {{__('locale.labels.week')}}</option>
                                                    <option value="month" {{old('frequency_unit') == 'month' ? 'selected' : null}}> {{__('locale.labels.month')}}</option>
                                                    <option value="year" {{old('frequency_unit') == 'year' ? 'selected' : null}}> {{__('locale.labels.year')}}</option>
                                                </select>
                                            </div>

                                            @error('frequency_unit')
                                            <p><small class="text-danger"> {{ $message }}</small></p>
                                            @enderror
                                        </div>

                                        <div class="col-12">
                                            <input type="hidden" name="user_id" value="{{auth()->user()->id}}">
                                            <input type="hidden" name="price" value="0">
                                            <input type="hidden" name="status" value="assigned">
                                            <input type="hidden" name="currency_id" value={{ \App\Models\Currency::where('status', 1)->firstOrFail()->id }}>
                                            <button type="submit" class="btn btn-primary me-1 mb-1"><i data-feather="save"></i> {{ __('locale.buttons.save') }}</button>
                                            @if( ! isset($keyword))
                                                <button type="reset" class="btn btn-outline-warning me-1 mb-1"><i data-feather="refresh-cw"></i>
                                                    {{ __('locale.buttons.reset') }}
                                                </button>
                                            @endif
                                        </div>

                                    </div>
                                </div>
                            </form>
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
        $(document).ready(function () {

            $(".sender_id").on("click", function () {
                $("#sender_id").prop("disabled", !this.checked);
                $("#phone_number").prop("disabled", this.checked);
            });

            $(".phone_number").on("click", function () {
                $("#phone_number").prop("disabled", !this.checked);
                $("#sender_id").prop("disabled", this.checked);
            });


            // Basic Select2 select
            $(".select2").each(function () {
                let $this = $(this);
                $this.wrap('<div class="position-relative" style="width: 80%"></div>');
                $this.select2({
                    // the following code is used to disable x-scrollbar when click in select input and
                    // take 100% width in responsive also
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent()
                });
            });


            let firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

            let showCustom = $('.show-custom');
            let billing_cycle = $('#billing_cycle');


            if (billing_cycle.val() === 'custom') {
                showCustom.show();
            } else {
                showCustom.hide();
            }

            billing_cycle.on('change', function () {
                if (billing_cycle.val() === 'custom') {
                    showCustom.show();
                } else {
                    showCustom.hide();
                }

            });

        });
    </script>
@endsection
