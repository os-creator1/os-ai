<x-card :padded="true">
    <form class="form form-vertical outreach-form" data-channel="mms" data-mode="campaign"
          action="{{ route('customer.workspaces.businesses.outreach.mms.campaign', [$workspaceUid, $businessUid]) }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-12">
                <div class="mb-1">
                    <label for="name-mms-campaign" class="required form-label">{{ __('locale.labels.name') }}</label>
                    <input type="text" id="name-mms-campaign" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name') }}" name="name" required
                           placeholder="{{ __('locale.labels.required') }}">
                    @error('name')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>
            </div>

            @include('customer.Outreach._originator', [
                'capability' => 'mms',
                'idSuffix' => 'mms-campaign',
                'sendingServers' => $sendingServers,
                'sender_ids' => $sender_ids,
                'phoneNumbers' => $mmsPhoneNumbers,
                'multiple' => true,
            ])

            <div class="col-12">
                <div class="mb-1">
                    <label for="contact_groups-mms-campaign" class="form-label required">{{ __('locale.contacts.contact_groups') }}</label>
                    <select class="select2 form-select" required name="contact_groups[]" multiple="multiple" id="contact_groups-mms-campaign" data-role="contact-groups">
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
                'idSuffix' => 'mms-campaign',
                'templates' => $templates,
                'showDlt' => false,
                'showAvailableTag' => true,
            ])

            <div class="col-12">
                <div class="mb-1">
                    <label for="mms_file-mms-campaign" class="form-label required">{{ __('locale.labels.mms_file') }}</label>
                    <input type="file" name="mms_file" class="form-control" id="mms_file-mms-campaign" accept="image/*,video/*" />
                    @error('mms_file')
                    <div class="text-danger">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @include('customer.Outreach._schedule', ['idSuffix' => 'mms-campaign'])
        </div>

        <div class="row">
            <div class="col-12">
                <input type="hidden" value="mms" name="sms_type" id="sms_type-mms-campaign">
                <input type="hidden" value="{{ $plan_id }}" name="plan_id">
                <button type="button" class="btn btn-primary mt-1 mb-1" data-role="send-preview-trigger"><x-ds-icon name="send" size="16" /> {{ __('locale.buttons.send') }}</button>
            </div>
        </div>
    </form>
</x-card>
