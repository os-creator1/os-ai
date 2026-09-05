{{--
    Shared originator picker: Sending Server + Sender ID / Phone Number.
    Params: $capability ('plain' or 'mms'), $idSuffix (unique per form),
    $sendingServers, $sender_ids, $phoneNumbers, $multiple (campaign builder
    submits sender_id/phone_number as arrays, matching legacy campaignBuilder
    field names exactly).
--}}
@php($multiple = $multiple ?? false)
@php($fieldSuffix = $multiple ? '[]' : '')
@if($sendingServers->count() > 0)
    <div class="col-12">
        <div class="mb-1">
            <label for="sending_server-{{ $idSuffix }}" class="form-label required">{{ __('locale.labels.sending_server') }}</label>
            <select class="select2 form-select" name="sending_server" id="sending_server-{{ $idSuffix }}" data-role="sending-server">
                @foreach($sendingServers as $server)
                    @if(isset($server->sendingServer) && $server->sendingServer->status == 1 && $server->sendingServer->{$capability})
                        <option value="{{ $server->sendingServer->id }}">{{ $server->sendingServer->name }}</option>
                    @endif
                @endforeach
            </select>
            @error('sending_server')
            <p><small class="text-danger">{{ $message }}</small></p>
            @enderror
        </div>
    </div>
@endif

@can('view_sender_id')
    @if(auth()->user()->customer->getOption('sender_id_verification') == 'yes')
        <div class="col-12">
            <p class="text-uppercase">{{ __('locale.labels.originator') }}</p>
        </div>
        <div class="col-md-6 col-12 customized_select2">
            <div class="mb-1">
                <label for="sender_id-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.sender_id') }}
                    <a class="text-success text-decoration-underline mx-1 text-uppercase cursor-pointer text"
                       href="{{ route('customer.senderid.request') }}" target="__blank">{{ __('locale.labels.request_new') }}</a>
                </label>
                <div class="input-group">
                    <div class="input-group-text">
                        <div class="form-check">
                            <input type="radio" class="form-check-input outreach-sender-id-radio" name="originator" checked value="sender_id" id="sender_id_check-{{ $idSuffix }}" data-role="originator-sender-id" />
                            <label class="form-check-label" for="sender_id_check-{{ $idSuffix }}"></label>
                        </div>
                    </div>
                    <div style="width: 17rem">
                        <select class="form-select select2" id="sender_id-{{ $idSuffix }}" name="sender_id{{ $fieldSuffix }}" data-role="sender-id">
                            @foreach($sender_ids as $sender_id)
                                <option value="{{ $sender_id->sender_id }}">{{ $sender_id->sender_id }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>
    @else
        @can('view_numbers')
            <div class="col-md-6 col-12 customized_select2">
                <div class="mb-1">
                    <label for="sender_id_custom-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.sender_id') }}
                        <span class="text-success font-small-1 text-uppercase text">({{ __('locale.labels.select_one_or_insert_your_own') }})</span>
                    </label>
                    <div class="input-group">
                        <div class="input-group-text">
                            <div class="form-check">
                                <input type="radio" class="form-check-input outreach-sender-id-radio" name="originator" checked value="sender_id" id="sender_id_check-{{ $idSuffix }}" data-role="originator-sender-id" />
                                <label class="form-check-label" for="sender_id_check-{{ $idSuffix }}"></label>
                            </div>
                        </div>
                        <div style="width: 17rem">
                            <select class="form-select max-length input_sender_id" multiple id="sender_id_custom-{{ $idSuffix }}" name="sender_id{{ $fieldSuffix }}" data-role="sender-id-custom">
                                @foreach($sender_ids as $sender_id)
                                    <option value="{{ $sender_id->sender_id }}">{{ $sender_id->sender_id }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="col-12">
                <div class="mb-1">
                    <label for="sender_id_custom-{{ $idSuffix }}" class="form-label">{{ __('locale.labels.sender_id') }}
                        <span class="text-success font-small-1 text-uppercase text">({{ __('locale.labels.select_one_or_insert_your_own') }})</span>
                    </label>
                    <select class="form-select max-length input_sender_id" id="sender_id_custom-{{ $idSuffix }}" multiple name="sender_id{{ $fieldSuffix }}" data-role="sender-id-custom">
                        @foreach($sender_ids as $sender_id)
                            <option value="{{ $sender_id->sender_id }}">{{ $sender_id->sender_id }}</option>
                        @endforeach
                    </select>
                    @error('sender_id')
                    <p><small class="text-danger">{{ $message }}</small></p>
                    @enderror
                </div>
            </div>
        @endcan
    @endif
@endcan

@can('view_numbers')
    <div class="col-md-6 col-12 customized_select2">
        <div class="mb-1">
            <label for="phone_number-{{ $idSuffix }}" class="form-label">{{ __('locale.menu.Phone Numbers') }}
                <a class="text-success text-decoration-underline mx-1 text-uppercase cursor-pointer text"
                   href="{{ route('customer.numbers.buy') }}" target="__blank">{{ __('locale.labels.request_new') }}</a>
            </label>
            <div class="input-group">
                <div class="input-group-text">
                    <div class="form-check">
                        <input type="radio" class="form-check-input outreach-phone-number-radio" value="phone_number" name="originator" id="phone_number_check-{{ $idSuffix }}" data-role="originator-phone-number" />
                        <label class="form-check-label" for="phone_number_check-{{ $idSuffix }}"></label>
                    </div>
                </div>
                <div style="width: 17rem">
                    <select class="form-select select2" disabled id="phone_number-{{ $idSuffix }}" name="phone_number{{ $fieldSuffix }}" data-role="phone-number" @if($multiple) multiple @endif>
                        @foreach($phoneNumbers as $number)
                            <option value="{{ $number->number }}">{{ $number->number }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>
@endcan
