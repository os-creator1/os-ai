<div class="row">

    <x-card>
            <div class="col-md-6 col-12">
                <form class="form form-vertical" action="{{ \App\Library\CrmRouting::route('contacts.message', $contact->uid) }}"
                      method="post">
                    @csrf
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-1">
                                <label for="message_form"
                                       class="form-label required">{{__('locale.labels.message_form')}}</label>
                                <select class="form-select select2" name="message_form" required id="message_form">
                                    <option value="signup_sms">{{ __('locale.contacts.signup_sms') }}</option>
                                    <option value="welcome_sms">{{ __('locale.contacts.welcome_message') }}</option>
                                    <option value="unsubscribe_sms">{{ __('locale.contacts.unsubscribe_sms') }}</option>
                                </select>
                                @error('message_form')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="mb-1">
                                <label class="form-label"
                                       for="available_tag">{{__('locale.labels.available_tag')}}</label>
                                <select class="form-select select2" id="available_tag">
                                    @foreach($contact->getFields as $field)
                                        <option value="{{$field->tag}}">{{ $field->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="show-subscribe-url">
                        <p class="m-0"><small
                                    class="text-uppercase text-primary"> {{__('locale.labels.subscribe_url')}} </small>
                        </p>
                        <div class="row">
                            <div class="col-md-10 col-sm-12 pr-0">
                                <div class="mb-1">
                                    <input type="text" class="form-control" id="copy-to-clipboard-input"
                                           value="{{route('contacts.subscribe_url', $contact->uid)}}">
                                </div>
                            </div>
                            <div class="col-md-2 col-sm-12">
                                <x-button type="button"
                                        id="btn-copy">{{__('locale.buttons.copy')}}!
                                </x-button>
                            </div>
                        </div>
                    </div>


                    <div class="show-unsubscribe-url">
                        <p class="m-0"><small
                                    class="text-uppercase text-primary"> {{__('locale.labels.unsubscribe_url')}} </small>
                        </p>
                        <div class="row">
                            <div class="col-md-12 col-sm-12 pr-0">
                                <div class="mb-1">
                                    <input type="text" class="form-control" id="copy-to-clipboard-input"
                                           value="{{route('contacts.unsubscribe_url', $contact->uid)}}">
                                </div>
                            </div>
                        </div>
                    </div>


                    {{--   using on select message like sms template in ultimate sms     --}}
                    <div class="row">
                        <div class="col-12">
                            <div class="mb-1">
                                <label for="text_message"
                                       class="form-label required">{{__('locale.labels.message')}}</label>
                                <textarea class="form-control" name="message" rows="5"
                                          id="text_message">{{$contact->signup_sms}}</textarea>
                                @error('message')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-12">
                            <x-button type="submit" icon="save" class="mb-1">{{__('locale.buttons.save')}}</x-button>
                        </div>
                    </div>
                </form>
            </div>
    </x-card>
</div>
