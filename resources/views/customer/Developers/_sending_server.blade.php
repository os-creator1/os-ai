<div class="modal fade text-start modal-primary"
     id="sendingServer"
     tabindex="-1"
     aria-labelledby="sendingServer"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('locale.developers.select_sending_server_for_api_messages') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form class="form" method="post" action="{{ route('customer.developer.server') }}">
                @csrf
                <div class="modal-body">

                    @if($sendingServers->isNotEmpty())
                        @php
                            $user = Auth::user();
                            $types = [
                                'sending_server' => [
                                    'permissions' => ['sms_quick_send', 'sms_campaign_builder'],
                                    'label' => __('locale.labels.plain'),
                                    'field' => 'plain',
                                    'selected' => $user->api_sending_server,
                                ],
                                'voice_sending_server' => [
                                    'permissions' => ['voice_quick_send', 'voice_campaign_builder'],
                                    'label' => __('locale.labels.voice'),
                                    'field' => 'voice',
                                    'selected' => $user->api_voice_sending_server,
                                ],
                                'mms_sending_server' => [
                                    'permissions' => ['mms_quick_send', 'mms_campaign_builder'],
                                    'label' => __('locale.labels.mms'),
                                    'field' => 'mms',
                                    'selected' => $user->api_mms_sending_server,
                                ],
                                'whatsapp_sending_server' => [
                                    'permissions' => ['whatsapp_quick_send', 'whatsapp_campaign_builder'],
                                    'label' => __('locale.labels.whatsapp'),
                                    'field' => 'whatsapp',
                                    'selected' => $user->api_whatsapp_sending_server,
                                ],
                                'viber_sending_server' => [
                                    'permissions' => ['viber_quick_send', 'viber_campaign_builder'],
                                    'label' => __('locale.menu.Viber'),
                                    'field' => 'viber',
                                    'selected' => $user->api_viber_sending_server,
                                ],
                                'otp_sending_server' => [
                                    'permissions' => ['otp_quick_send', 'otp_campaign_builder'],
                                    'label' => __('locale.menu.OTP'),
                                    'field' => 'otp',
                                    'selected' => $user->api_otp_sending_server,
                                ],
                            ];
                        @endphp

                        @foreach($types as $id => $type)
                            @canany($type['permissions'])
                                <div class="col-12 mb-1">
                                    <label for="{{ $id }}" class="form-label">
                                        {{ __('locale.plans.sending_server_for_sms', ['sms_type' => $type['label']]) }}
                                    </label>
                                    <select class="form-select select2"
                                            id="{{ $id }}"
                                            name="{{ $id }}"
                                            data-placeholder="{{ __('locale.labels.choose_your_option') }}">
                                        @foreach($sendingServers as $server)
                                            @if(!empty($server->sendingServer) && $server->sendingServer->{$type['field']})
                                                <option value="{{ $server->sendingServer->id }}"
                                                        @selected($type['selected'] == $server->sendingServer->id)>
                                                    {{ $server->sendingServer->name }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error($id)
                                    <p><small class="text-danger">{{ $message }}</small></p>
                                    @enderror
                                </div>
                            @endcanany
                        @endforeach
                    @else
                        <p class="card-text fw-bolder text-danger">
                            {{ __('locale.sending_servers.have_no_sending_server') }}
                        </p>
                    @endif

                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">
                        <i data-feather="save"></i> {{ __('locale.buttons.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
