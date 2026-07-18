@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ __('locale.labels.pay_payment') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <!-- Crypto Payment Address Display -->

                            <div class="alert alert-danger" role="alert">
                                <h4 class="alert-heading text-uppercase">{{ __('locale.labels.warning') }}</h4>
                                <div class="alert-body">
                                    <p>
                                        Please do not refresh the page, close the window, click the back button, or
                                        click the <code>Claim Payment</code> button without confirming that your payment
                                        status is <code>Finished</code>.
                                        If you mistakenly click any of these actions, don't worry — as long as your
                                        <code>Webhook IPN URL</code> is correctly configured, your payment will be
                                        successfully processed. Otherwise, you have to claim the refund manually.</p>
                                </div>
                            </div>


                            <div class="row">

                                <div class="col-md-6 col-12">
                                    <div class="mb-1">
                                        <label for="price_amount" class="form-label required">Amount in <strong
                                                    class="text-uppercase">{{ $data->price_currency }}</strong></label>
                                        <input type="text" id="address" class="form-control"
                                               value="{{ $data->price_amount }}" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-1">
                                        <label for="pay_amount" class="form-label required">Have to pay: <strong
                                                    class="text-uppercase">{{ $data->pay_currency }}</strong></label>
                                        <input type="text" id="address" class="form-control"
                                               value="{{ $data->pay_amount }}" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="pay_address"
                                       class="font-weight-bold">{{ __('locale.labels.payment_id') }}</label>

                                <!-- Pay Address Display -->
                                <input type="text" id="payment_id" class="form-control" value="{{ $payment_id }}"
                                       readonly>
                            </div>

                            <div class="form-group">
                                <label for="pay_address"
                                       class="font-weight-bold">{{ __('locale.labels.pay_address') }}</label>
                                <div class="input-group">
                                    <!-- Pay Address Display -->
                                    <input type="text" id="pay_address" class="form-control" value="{{ $pay_address }}"
                                           readonly>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-primary" type="button" id="copyButton"
                                                onclick="copyToClipboard()">{{ __('locale.buttons.copy') }}</button>
                                    </div>
                                </div>
                                <small class="form-text text-primary mt-1">
                                    {{ __('locale.labels.copy_address_to_clipboard') }}
                                </small>
                            </div>

                            <!-- QR Code Section -->
                            <div class="text-center mt-3">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?data={{ $pay_address }}&size=200x200"
                                     alt="QR Code" class="img-fluid">
                                <p class="mt-2">{{ __('locale.labels.scan_qr_code_for_payment') }}</p>
                            </div>

                            <!-- Valid Until Information -->
                            <div class="form-group mt-4">
                                <label for="valid_until"
                                       class="font-weight-bold">{{ __('locale.labels.valid_until') }}</label>
                                <input type="text" id="valid_until" class="form-control" value="{{ $valid_unit }}"
                                       readonly>
                                <small class="form-text text-primary mt-1">
                                    {{ __('locale.labels.address_expiry_info') }}
                                </small>
                            </div>

                            <form action="{{ route('customer.payment.nowpayments', $type) }}" method="post"
                                  class="mt-2">
                                <input type="hidden" name="post_data" value="{{ $post_data }}">
                                <input type="hidden" name="payment_id" id="payment_id" value="{{ $payment_id }}">
                                @if(isset($sms_unit))
                                    <input type="hidden" name="sms_unit" value="{{ $sms_unit }}">
                                @endif
                                <button type="submit"
                                        class="btn btn-success me-1 btn-submit">{{ __('locale.labels.claim_payment') }}</button>

                                <a href="javascript:void(0)" id="check_payment_status"
                                   class="btn btn-primary me-1">Check Payment Status
                                </a>

                                <a href="{{ route('user.home') }}"
                                   class="btn btn-danger">{{ __('locale.buttons.back') }}</a>
                            </form>

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
        // Function to copy the crypto address to clipboard
        function copyToClipboard() {
            let copyText = document.getElementById("pay_address");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices

            try {
                let successful = document.execCommand('copy');
                let msg = successful ? 'Copied' : 'Failed to copy';

                toastr['success'](msg, '{{__('locale.labels.success')}}!!', {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });
            } catch (err) {
                toastr['info']('Oops, unable to copy ' + err, '{{ __('locale.labels.warning') }}!', {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });
            }
        }

        $('#check_payment_status').click(function () {
            let payment_id = $('#payment_id').val();
            let url = '{{ route('customer.payment.check_nowpayments_status') }}';

            $.ajax({
                url: url,
                type: 'POST',
                data: {
                    _token: "{{csrf_token()}}",
                    payment_id: payment_id,
                },
                success: function (response) {
                    if (response.status === 'success') {
                        toastr['success'](response.message, '{{__('locale.labels.success')}}!!', {
                            closeButton: true,
                            positionClass: 'toast-top-right',
                            progressBar: true,
                            newestOnTop: true,
                            rtl: isRtl
                        });
                    } else {
                        toastr['error'](response.message, '{{ __('locale.labels.opps') }}!', {
                            closeButton: true,
                            positionClass: 'toast-top-right',
                            progressBar: true,
                            newestOnTop: true,
                            rtl: isRtl
                        });
                    }
                },
                error: function (error) {
                    toastr['error'](error.responseJSON.message, '{{ __('locale.labels.opps') }}!', {
                        closeButton: true,
                        positionClass: 'toast-top-right',
                        progressBar: true,
                        newestOnTop: true,
                        rtl: isRtl
                    });
                }

            });
        });

    </script>
@endsection
