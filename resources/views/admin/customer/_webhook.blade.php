<div class="card">
    <div class="card-header"></div>
    <div class="card-content">
        <div class="card-body">

            <div class="mt-2 row">
                <div class="col-12">
                    <p class="font-medium-2">Webhook URL For Twilio</p>

                    <span class="font-medium-2 text-primary"
                          id="copy-to-webhook-input">{{ route('inbound.webhook', ['user' => $customer->uid]) }}</span>
                    <span id="btn-webhook-url-copy" data-bs-toggle="tooltip" data-placement="top"
                          title="{{ __('locale.labels.copy') }}"><i data-feather="clipboard"
                                                                    class="font-medium-2 text-info cursor-pointer"></i></span>

                </div>
            </div>

            <small class="text-muted text-uppercase mt-3">You can use this webhook URL to receive inbound messages from Twilio.</small>

        </div>
    </div>
</div>
