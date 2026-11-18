<div class="mgx-gd-settings">
    <section class="mgx-card mgx-gd-card mgx-gd-form">
                <form class="mgx-gd-stack settings" action="{{ \App\Library\CrmRouting::route('contacts.update', $contact->uid) }}" method="post">
                    {{ method_field('PUT') }}
                    @csrf
                    <div class="row">

                        <div class="col-12">
                            <div class="mb-1">
                                <label for="name" class="form-label required">Group name</label>
                                <input type="text" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ $contact->name }}" name="name" required>
                                @error('name')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                        </div>

                        @if(auth()->user()->customer->getOption('sender_id_verification') == 'yes')
                            <div class="col-12">
                                <p class="text-uppercase">{{ __('locale.labels.sender_identity') }}</p>
                            </div>

                            @can('view_sender_id')
                                <div class="col-md-6 col-12 customized_select2">
                                    <div class="mb-1">
                                        <label for="sender_id" class="form-label">{{ __('locale.labels.sender_identity') }}</label>
                                        <div class="input-group">
                                            <div class="input-group-text">
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input sender_id" name="originator" @if( !is_numeric($contact->sender_id) && !is_null($contact->sender_id)) checked @endif value="sender_id" id="sender_id_check"/>
                                                    <label class="form-check-label" for="sender_id_check"></label>
                                                </div>
                                            </div>


                                            <div style="width: 17rem">
                                                <select class="form-select select2" id="sender_id" name="sender_id" @if( is_numeric($contact->sender_id) || is_null($contact->sender_id)) disabled @endif>
                                                    @foreach($sender_ids as $sender_id)
                                                        <option value="{{$sender_id->sender_id}}" {{ $sender_id->sender_id == $contact->sender_id ? 'selected': null  }}> {{ $sender_id->sender_id }} </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endcan

                            @can('view_numbers')
                                <div class="col-md-6 col-12 customized_select2">
                                    <div class="mb-1">
                                        <label for="phone_number" class="form-label">{{ __('locale.menu.Phone Numbers') }}</label>
                                        <div class="input-group">
                                            <div class="input-group-text">
                                                <div class="form-check">
                                                    <input type="radio" class="form-check-input phone_number" @if( is_numeric($contact->sender_id)) checked @endif value="phone_number" name="originator" id="phone_number_check"/>
                                                    <label class="form-check-label" for="phone_number_check"></label>
                                                </div>
                                            </div>

                                            <div style="width: 17rem">
                                                <select class="form-select select2" id="phone_number" name="phone_number" @if( !is_numeric($contact->sender_id)) disabled @endif>
                                                    @foreach($phone_numbers as $number)
                                                        <option value="{{ $number->number }}" {{ $number->number == $contact->sender_id ? 'selected': null  }}> {{ $number->number }} </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endcan

                        @else
                            <div class="col-12">
                                <div class="mb-1">
                                    <label for="sender_id" class="form-label">{{__('locale.labels.sender_identity')}}</label>
                                    <input type="text" id="sender_id" class="form-control @error('sender_id') is-invalid @enderror" value="{{ $contact->sender_id }}" name="sender_id">
                                    @error('sender_id')
                                    <p><small class="text-danger">{{ $message }}</small></p>
                                    @enderror
                                </div>
                            </div>
                        @endif

                    </div>

                    <div class="mgx-gd-auto">
                        <span class="mgx-gd-eyebrow">Automatic texts</span>
                        <div class="mgx-gd-autorow">
                            <div>
                                <b>{{ __('locale.contacts.send_welcome_message') }}?</b>
                                <span>{!! __('locale.contacts.send_welcome_message_description') !!} <a href="#message" class="mgx-link" data-goto-tab="message">Edit the message</a></span>
                            </div>
                            <label class="mgx-switch" for="send_welcome_sms">
                                <input type="checkbox" name="send_welcome_sms" value="1" {{ $contact->send_welcome_sms == 1 ? 'checked': null  }} id="send_welcome_sms">
                                <span class="mgx-switch-ui"></span><span class="visually-hidden">{{ __('locale.contacts.send_welcome_message') }}</span>
                            </label>
                        </div>
                        <div class="mgx-gd-autorow">
                            <div>
                                <b>{{ __('locale.contacts.send_unsubscribe_notification') }}?</b>
                                <span>{!! __('locale.contacts.send_unsubscribe_notification_description') !!}</span>
                            </div>
                            <label class="mgx-switch" for="unsubscribe_notification">
                                <input type="checkbox" name="unsubscribe_notification" value="1" {{ $contact->unsubscribe_notification == 1 ? 'checked': null  }} id="unsubscribe_notification">
                                <span class="mgx-switch-ui"></span><span class="visually-hidden">{{ __('locale.contacts.send_unsubscribe_notification') }}</span>
                            </label>
                        </div>
                        @can('view_keywords')
                            <div class="mgx-gd-autorow">
                                <div>
                                    <b>{{ __('locale.contacts.send_keyword_message') }}?</b>
                                    <span>{!! __('locale.contacts.send_keyword_message_description') !!} <a href="#keywords" class="mgx-link" data-goto-tab="keywords">{{ __('locale.menu.Keywords') }}</a></span>
                                </div>
                                <label class="mgx-switch" for="send_keyword_message">
                                    <input type="checkbox" name="send_keyword_message" value="1" {{ $contact->send_keyword_message == 1 ? 'checked': null  }} id="send_keyword_message">
                                    <span class="mgx-switch-ui"></span><span class="visually-hidden">{{ __('locale.contacts.send_keyword_message') }}</span>
                                </label>
                            </div>
                        @endcan
                    </div>

                    <div class="mgx-gd-actions">
                        <button type="submit" class="mgx-btn is-primary">{{ __('locale.buttons.save_changes') }}</button>
                    </div>
                </form>
    </section>

    @can('delete_contact_group')
        <button type="button" class="mgx-gd-delete" data-role="group-delete"
                data-delete-url="{{ \App\Library\CrmRouting::route('contacts.destroy', $contact->uid) }}"
                data-back-url="{{ \App\Library\CrmRouting::route('contacts.index') }}">Delete this group</button>
    @endcan
</div>
