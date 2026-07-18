@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')
    <div class="text-center">
        <div id="easypay-checkout"></div>
    </div>
@endsection

@php
    $environment = null;
    $paymentMethod = \App\Models\PaymentMethods::where('status', true)->where('type', \App\Models\PaymentMethods::TYPE_EASYPAY)->first();
    if ($paymentMethod){
        $credentials = json_decode($paymentMethod->options);
        $environment = $credentials->environment;
        if ($environment == 'sandbox'){
            $testing = true;
        }else{
            $testing = false;
        }
    }
@endphp

@section('page-script')
    <script src="https://cdn.easypay.pt/checkout/2.4.0/"></script>
    <script type="text/javascript">
        const manifest = JSON.parse({!! json_encode($data) !!});
        let successfulPaymentInteraction = false

        function mySuccessHandler() {
            successfulPaymentInteraction = true
        }

        function myCloseHandler() {
            if (successfulPaymentInteraction) {
                $.ajax({
                    url: "{{ route('customer.callback.easypay') }}",
                    type: "POST",
                    data: {
                        request_type: "{{ $request_type }}",
                        post_data: "{{ $post_data }}",
                        sms_unit: "{{ isset($sms_unit) ?? '' }}",
                        _token: "{{csrf_token()}}"
                    },
                    success: function (data) {
                        if (data.status === 'success') {
                            toastr['success'](data.message, '{{ __('locale.labels.success') }}!', {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });

                            setTimeout(function () {
                                window.location.href = data.url;
                            }, 3000);
                        } else {
                            toastr['error'](data.message, '{{ __('locale.labels.attention') }}!', {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    },
                    error: function (reject) {
                        if (reject.status === 422) {
                            let errors = reject.responseJSON.errors;
                            $.each(errors, function (key, value) {
                                toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                                    closeButton: true,
                                    positionClass: 'toast-top-right',
                                    progressBar: true,
                                    newestOnTop: true,
                                    rtl: isRtl
                                });
                            });
                        } else {
                            toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                                positionClass: 'toast-top-right',
                                containerId: 'toast-top-right',
                                progressBar: true,
                                closeButton: true,
                                newestOnTop: true
                            });
                        }
                    }
                })
            } else {
                try {
                    toastr['warning']('{{ __('locale.sender_id.payment_cancelled') }}', '{{ __('locale.labels.opps') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                } catch (e) {
                    toastr['error'](e.getMessage(), '{{ __('locale.labels.opps') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }
            }
        }

        if (manifest.status === 'error') {
            toastr['error'](manifest.message[0], '{{ __('locale.labels.opps') }}!', {
                closeButton: true,
                positionClass: 'toast-top-right',
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
            });
        } else {
            let testingMode = "{!! $testing !!}";
            if (testingMode === "1") {
                var testing = true;
            } else {
                var testing = false;
            }
            easypayCheckout.startCheckout(manifest, {
                display: 'inline',
                testing: testing,
                logoUrl: '{!! asset(config('app.logo')) !!}',
                onSuccess: mySuccessHandler,
                onClose: myCloseHandler,
            })
        }
    </script>
@endsection
