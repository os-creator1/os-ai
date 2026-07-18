@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.top_up'))

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row">
            <div class="col-md-4 col-12">

                @if ($errors->any())
                    <div class="alert alert-danger pt-1">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"> {{__('locale.customer.add_unit_to_your_account')}} </h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <div class="form-body">
                                <form class="form form-vertical" action="{{ route('user.account.top_up') }}"
                                      method="post">
                                    @csrf
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="add_unit"
                                                           class="form-label required">{{ __('locale.labels.amount') }}</label>
                                                    <div class="input-group input-group-merge mb-2">
                                                        <span class="input-group-text ">{{ str_replace('{PRICE}', '', Auth::user()->customer->subscription->plan->currency->format) }}</span>
                                                        <input type="text" id="add_unit"
                                                               class="form-control @error('add_unit') is-invalid @enderror"
                                                               name="add_unit" required>


                                                        @error('add_unit')
                                                        <p><small class="text-danger">{{ $message }}</small></p>
                                                        @enderror
                                                    </div>
                                                    <p>
                                                        <small class="text-primary text-uppercase hidden show_units_info"></small>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12">

                                            <input type="hidden" id="sms_unit" name="sms_unit">

                                            <button id="topup-submit-btn" type="submit" class="btn btn-primary mb-1">
                                                <i data-feather="plus-square"></i> {{ __('locale.labels.process_to_pay') }}
                                            </button>
                                        </div>


                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title text-info text-uppercase"> {{__('locale.labels.validity')}}:
                            @if(Auth::user()->customer->subscription->plan->frequency_amount == 1)
                                1 {{ Auth::user()->customer->subscription->plan->displayFrequencyTime() }}
                            @else
                                {{ Auth::user()->customer->subscription->plan->displayFrequencyTime() }}
                            @endif
                        </h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered mb-0">
                                    <thead class="thead-primary">
                                    <tr class="text-center">
                                        <th colspan="2">{{ __('locale.plans.recharge_volume') }}</th>
                                        <th>{{ __('locale.labels.per_unit_price') }}</th>
                                        <th class="text-nowrap">{{ __('locale.plans.number_of_units') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody class="text-center">

                                    @php
                                        $plan = Auth::user()->customer->subscription->plan;
                                    @endphp

                                    @if($plan->getCreditPrices()->count() > 0)
                                        @foreach ($plan->getCreditPrices()->get() as $key => $item)
                                            <tr>

                                                <td>
                                                    <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->unit_from }}</span>
                                                </td>

                                                <td>
                                                    <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->unit_to }}</span>
                                                </td>

                                                <td>
                                                    <span>{{ str_replace('{PRICE}', '', $plan->currency->format) }} {{ $item->per_credit_cost }}</span>
                                                </td>
                                                <td>
                                                    <span class="number_of_units"> {{ $item->calculateUnits() }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection


@section('page-script')

    <script>
        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        let timeoutId, topupSubmitBtn = $('#topup-submit-btn');

        $('#add_unit').on('input', function () {
            topupSubmitBtn.prop('disabled', true);
            // Clear the previous timeout
            clearTimeout(timeoutId);

            // Set a new timeout
            timeoutId = setTimeout(function () {
                let enteredAmount = $('#add_unit').val();

                $.ajax({
                    url: '{{ route('user.account.get.units') }}',
                    method: 'POST',
                    data: {
                        '_token': '{{ csrf_token() }}',
                        'amount': enteredAmount
                    },
                    success: function (response) {

                        if (response.units) {
                            $('#sms_unit').val(response.units);
                            $('.show_units_info').text('{{__('locale.labels.you_will_get')}} ' + response.units + ' {{__('locale.labels.sms_credit')}}').removeClass('hidden');
                            topupSubmitBtn.prop('disabled', false);
                        } else {
                            $('.show_units_info').text('').addClass('hidden');
                            toastr['warning'](response.error, "{{__('locale.labels.attention')}}", {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    },
                    error: function (error) {
                        toastr['warning'](error.responseText, "{{__('locale.labels.attention')}}", {
                            closeButton: true,
                            positionClass: 'toast-top-right',
                            progressBar: true,
                            newestOnTop: true,
                            rtl: isRtl
                        });
                    }
                });
            }, 500); // Adjust the delay time (in milliseconds) as needed
        });

    </script>
@endsection
