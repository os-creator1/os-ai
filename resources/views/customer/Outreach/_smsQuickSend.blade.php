<x-card :padded="true">
    <x-alert variant="accent" icon="info" class="mb-3">
        {{ __('locale.template_tags.not_work_with_quick_send') }}
    </x-alert>

    <form class="form form-vertical outreach-form" data-channel="sms" data-mode="quick"
          action="{{ route('customer.outreach.sms.send') }}" method="post">
        @csrf
        <div class="row">
            @include('customer.Outreach._originator', [
                'capability' => 'plain',
                'idSuffix' => 'sms-quick',
                'sendingServers' => $sendingServers,
                'sender_ids' => $sender_ids,
                'phoneNumbers' => $smsPhoneNumbers,
            ])

            <div class="col-12">
                <div class="mb-1">
                    <label class="country_code form-label" for="country_code-sms-quick">{{ __('locale.labels.country_code') }}</label>
                    <select class="form-select select2" id="country_code-sms-quick" name="country_code">
                        <option value="0">{{ __('locale.labels.multiple_self') }}</option>
                        @foreach($coverage as $code)
                            <option value="{{ $code->country_id }}">+{{ $code->country->country_code }} ({{ $code->country->iso_code }})</option>
                        @endforeach
                    </select>
                    @error('country_code')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>
            </div>

            <div class="col-12">
                <div class="mb-1">
                    <label for="recipients-sms-quick" class="form-label">{{ __('locale.labels.recipients') }}:
                        @can('sms_campaign_builder')
                            <small class="text-primary">{!! __('locale.description.manual_input') !!}</small>
                            <a class="text-success text-uppercase text-decoration-underline" href="{{ route('customer.outreach.index') }}#sms-campaign">{{ __('locale.menu.Campaign Builder') }}</a>
                            <small class="text-primary">{!! __('locale.contacts.include_country_code_for_successful_import') !!}</small>
                        @else
                            <small class="text-primary">{!! __('locale.description.manual_input') !!}</small>
                        @endcan
                    </label>
                    <textarea class="form-control" id="recipients-sms-quick" name="recipients" data-role="recipients"></textarea>
                    <div class="row mt-1">
                        <div class="col-md-7 col-12">
                            <div class="btn-group btn-group-sm recipients" role="group" data-role="delimiter-group">
                                <input type="radio" class="btn-check" name="delimiter" value="," id="comma-sms-quick" autocomplete="off" checked />
                                <label class="btn btn-outline-primary" for="comma-sms-quick">, ({{ __('locale.labels.comma') }})</label>

                                <input type="radio" class="btn-check" name="delimiter" value=";" id="semicolon-sms-quick" autocomplete="off" />
                                <label class="btn btn-outline-primary" for="semicolon-sms-quick">; ({{ __('locale.labels.semicolon') }})</label>

                                <input type="radio" class="btn-check" name="delimiter" value="new_line" id="new_line-sms-quick" autocomplete="off" />
                                <label class="btn btn-outline-primary" for="new_line-sms-quick">{{ __('locale.labels.new_line') }}</label>
                            </div>
                            @error('delimiter')
                            <p><small class="text-danger">{{ $message }}</small></p>
                            @enderror
                        </div>
                        <div class="col-md-5 col-12 d-flex justify-content-md-end">
                            <small class="text-uppercase">{{ __('locale.labels.total_number_of_recipients') }}:
                                <span data-role="recipient-count" class="fw-bold text-success">0</span></small>
                        </div>
                        @error('recipients')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                </div>
            </div>

            @include('customer.Outreach._messageComposer', [
                'idSuffix' => 'sms-quick',
                'templates' => $templates,
                'showDlt' => $showDlt,
                'showAvailableTag' => false,
            ])
        </div>

        <div class="d-flex justify-content-between">
            <div class="d-none d-sm-block">
                <button type="button" class="btn btn-info mr-1 mb-1" data-role="phone-preview-trigger"><x-ds-icon name="smartphone" size="16" /> {{ __('locale.buttons.preview') }}</button>
            </div>
            <div>
                <input type="hidden" value="plain" name="sms_type" id="sms_type-sms-quick" data-role="sms-type">
                <button type="button" class="btn btn-primary mr-1 mb-1" data-role="send-preview-trigger"><x-ds-icon name="send" size="16" /> {{ __('locale.buttons.send') }}</button>
            </div>
        </div>
    </form>
</x-card>
