@extends('layouts/contentLayoutMaster')

@section('title', __('locale.sender_id.add_new_sender_id'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.sender_id.add_new_sender_id') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            <p>{!!  __('locale.description.sender_id') !!} {{config('app.name')}}</p>
                            @if(config('app.trai_dlt'))
                                <p><code>{!!  __('locale.sender_id.dlt_description') !!}</code></p>
                            @endif

                            <form class="form form-vertical" action="{{ route('admin.senderid.store') }}" method="post">
                                @csrf
                                <div class="form-body">
                                    <div class="row">

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="sender_id"
                                                       class="form-label required">{{ __('locale.menu.Sender ID') }}</label>
                                                <input type="text" id="sender_id"
                                                       class="form-control @error('sender_id') is-invalid @enderror"
                                                       value="{{ old('sender_id') }}" name="sender_id" required
                                                       placeholder="{{__('locale.labels.required')}}" maxlength="11" autofocus>
                                                @error('sender_id')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="status"
                                                       class="form-label required">{{ __('locale.labels.status') }}</label>
                                                <select class="form-select" name="status" id="status">
                                                    <option value="active">{{ __('locale.labels.active') }}</option>
                                                    <option value="payment_required">{{ __('locale.labels.payment_required') }}</option>
                                                    <option value="block">{{ __('locale.labels.block')}} </option>
                                                </select>
                                                @error('status')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="price"
                                                       class="form-label required">{{ __('locale.plans.price') }}</label>
                                                <input type="text" id="price"
                                                       class="form-control @error('price') is-invalid @enderror"
                                                       value="0" name="price" required
                                                       placeholder="{{__('locale.labels.required')}}">
                                                @error('price')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="billing_cycle"
                                                       class="form-label required">{{__('locale.plans.billing_cycle')}}</label>
                                                <select class="form-select" id="billing_cycle" name="billing_cycle">
                                                    @foreach (\App\Enums\BillingCycleEnum::getAllValues() as $cycle)
                                                        <option value="{{ $cycle }}" {{ old('billing_cycle') || 'monthly' == $cycle ? 'selected': null }}> {{__('locale.labels.'.$cycle)}}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @error('billing_cycle')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>

                                        <div class="col-sm-6 col-12 show-custom">
                                            <div class="mb-1">
                                                <label for="frequency_amount"
                                                       class="form-label required">{{__('locale.plans.frequency_amount')}}</label>
                                                <input type="text" id="frequency_amount"
                                                       class="form-control text-right @error('frequency_amount') is-invalid @enderror"
                                                       value="{{ old('frequency_amount') }}" name="frequency_amount">
                                                @error('frequency_amount')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-sm-6 col-12 show-custom">
                                            <div class="mb-1">
                                                <label for="frequency_unit"
                                                       class="form-label required">{{__('locale.plans.frequency_unit')}}</label>
                                                <select class="form-select" id="frequency_unit" name="frequency_unit">
                                                    <option value="day"> {{__('locale.labels.day')}}</option>
                                                    <option value="week">  {{__('locale.labels.week')}}</option>
                                                    <option value="month">  {{__('locale.labels.month')}}</option>
                                                    <option value="year">  {{__('locale.labels.year')}}</option>
                                                </select>
                                            </div>
                                            @error('frequency_unit')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>


                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="user_id"
                                                       class="form-label required">{{__('locale.labels.select_customer')}}</label>
                                                <select class="form-select select2" id="user_id" name="user_id[]"
                                                        multiple>
                                                    @foreach($customers as $customer)
                                                        <option value="{{$customer->id}}">{{$customer->displayName()}}</option>
                                                    @endforeach
                                                </select>
                                                @error('user_id')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror

                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="currency_id"
                                                       class="form-label required">{{__('locale.labels.currency')}}</label>
                                                <select class="form-select select2" id="currency_id" name="currency_id">
                                                    @foreach($currencies as $currency)
                                                        <option value="{{$currency->id}}"> {{ $currency->name }}
                                                            ({{$currency->code}})
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @error('currency_id')
                                                <p><small class="text-danger">{{ $message }}</small></p>
                                                @enderror
                                            </div>
                                        </div>

                                        @if(config('app.trai_dlt'))

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <div class="form-check me-3 me-lg-5 mt-1">
                                                        <input type="checkbox" class="form-check-input" id="is_dlt"
                                                               name="is_dlt">
                                                        <label class="form-label"
                                                               for="is_dlt">{{__('locale.labels.dlt')}}</label>
                                                    </div>
                                                    <p>
                                                        <small class="text-muted">{{__('locale.labels.trai_dlt')}}</small>
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="show_dlt">
                                                <div class="col-12">
                                                    <div class="mb-1">
                                                        <label for="entity_id"
                                                               class="form-label required">{{ __('locale.labels.entity_id') }}</label>
                                                        <input type="text" id="entity_id"
                                                               class="form-control @error('entity_id') is-invalid @enderror"
                                                               value="{{ old('entity_id') }}" name="entity_id"
                                                               placeholder="{{__('locale.labels.required')}}">
                                                        @error('entity_id')
                                                        <div class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                        @enderror
                                                    </div>
                                                </div>

                                                <div class="col-12">
                                                    <div class="mb-1">
                                                        <label for="description"
                                                               class="form-label">{{__('locale.labels.description')}}</label>
                                                        <textarea class="form-control" name="description" rows="2"
                                                                  id="description">{{ old('description') }}</textarea>
                                                        @error('description')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                </div>
                                            </div>

                                        @endif

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary me-1 mb-1"><i
                                                        data-feather="save"></i> {{ __('locale.buttons.save') }}
                                            </button>
                                            <button type="reset" class="btn btn-outline-warning mb-1"><i
                                                        data-feather="refresh-cw"></i> {{ __('locale.buttons.reset') }}
                                            </button>
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
            let showCustom = $('.show-custom'),
                billing_cycle = $('#billing_cycle'),
                isDlt = $('#is_dlt'),
                showDlt = $('.show_dlt');

            showDlt.hide();

            isDlt.on('change', function () {
                if (isDlt.is(':checked')) {
                    showDlt.toggle(300);
                } else {
                    showDlt.toggle(300);
                }
            });

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

            let firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

        });
    </script>
@endsection
