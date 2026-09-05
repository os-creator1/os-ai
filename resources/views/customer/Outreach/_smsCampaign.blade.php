<x-card :padded="true">
    <form class="form form-vertical outreach-form" data-channel="sms" data-mode="campaign"
          action="{{ route('customer.outreach.sms.campaign') }}" method="post">
        @csrf
        <div class="row">
            <div class="col-12">
                <div class="mb-1">
                    <label for="name-sms-campaign" class="required form-label">{{ __('locale.campaigns.campaign_reference') }}</label>
                    <input type="text" id="name-sms-campaign" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name') }}" name="name" required
                           placeholder="{{ __('locale.campaigns.campaign_name_hint') }}">
                    @error('name')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>
            </div>

            @include('customer.Outreach._originator', [
                'capability' => 'plain',
                'idSuffix' => 'sms-campaign',
                'sendingServers' => $sendingServers,
                'sender_ids' => $sender_ids,
                'phoneNumbers' => $smsPhoneNumbers,
                'multiple' => true,
            ])

            <div class="col-12">
                <div class="mb-1">
                    <label for="contact_groups-sms-campaign" class="form-label required">{{ __('locale.contacts.contact_groups') }}</label>
                    <select class="select2 form-select" required name="contact_groups[]" multiple="multiple" id="contact_groups-sms-campaign" data-role="contact-groups">
                        @foreach($contact_groups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }} ({{ \App\Library\Tool::number_with_delimiter($group->subscribersCount($group->cache)) }} {{ __('locale.menu.Contacts') }})</option>
                        @endforeach
                    </select>
                    @error('contact_groups')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>
            </div>

            @include('customer.Outreach._messageComposer', [
                'idSuffix' => 'sms-campaign',
                'templates' => $templates,
                'showDlt' => $showDlt,
                'showAvailableTag' => true,
            ])

            @include('customer.Outreach._schedule', ['idSuffix' => 'sms-campaign'])
        </div>

        <div class="d-flex justify-content-between">
            <div class="d-none d-sm-block">
                <button type="button" class="btn btn-info mr-1 mt-1 mb-1" data-role="phone-preview-trigger"><x-ds-icon name="smartphone" size="16" /> {{ __('locale.buttons.preview') }}</button>
            </div>
            <div>
                <input type="hidden" value="plain" name="sms_type" id="sms_type-sms-campaign" data-role="sms-type">
                <input type="hidden" value="{{ $plan_id }}" name="plan_id">
                <button type="button" class="btn btn-primary mt-1 mb-1" data-role="send-preview-trigger"><x-ds-icon name="send" size="16" /> {{ __('locale.buttons.send') }}</button>
            </div>
        </div>
    </form>
</x-card>
