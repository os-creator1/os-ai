<form action="{{ \App\Library\CrmRouting::route('contact.store-contact-field', $contact->uid) }}" method="post" id="basic-table">
    {{ csrf_field() }}
    <section class="mgx-card mgx-gd-card mgx-gd-fields">
        <div class="mgx-gd-cardhead">
            <div>
                <b>Contact fields</b>
                <span class="mgx-mut">What you store about each contact in this group. Use the tags in messages.</span>
            </div>
            <button type="submit" class="mgx-btn is-primary">{{__('locale.buttons.save_changes')}}</button>
        </div>

@if($fields->count())

                    <div class="table-responsive">
                        <table class="table field-list">
                            <thead>
                            <tr>
                                <th style="width: 1%"></th>
                                <th>Label and Type</th>
                                <th style="width: 100px">Required?</th>
                                <th style="width: 100px">Visible?</th>
                                <th>Tag</th>
                                <th>Default Value</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($fields as $key => $item)
                                <tr data-remove-id="{{ $item->uid }}">
                                    <td>
                                        <input type="hidden" name="fields[{{ $item->uid }}][uid]"
                                               value="{{ $item->uid }}"/>
                                    </td>

                                    <td style="width: 28%">
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="fields[{{$item->uid}}][label]"
                                                   value="{{ $item->label }}" aria-describedby="labelNtype">
                                            <span class="input-group-text" id="labelNtype">{{ $item->type }}</span>
                                        </div>
                                        <input type="hidden"
                                               value="{{ $item->type }}"
                                               name="fields[{{ $item->uid }}][type]"
                                        />
                                    </td>
                                    <td>
                                        <div class="form-check form-switch form-check-primary">
                                            <input type="checkbox"
                                                   class="form-check-input"
                                                   value="1"
                                                   id="required_{{$item->uid}}"
                                                   name="fields[{{$item->uid}}][required]"
                                                   @if($item->required) checked @endif
                                                   @if($item->tag == 'PHONE') disabled @endif
                                            />
                                            <label class="form-check-label" for="required_{{$item->uid}}">
                                                <span class="switch-icon-left"><x-ds-icon name="check" /></span>
                                                <span class="switch-icon-right"><x-ds-icon name="x" /></span>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch form-check-primary">
                                            <input type="checkbox"
                                                   class="form-check-input"
                                                   value="1"
                                                   id="visible_{{$item->uid}}"
                                                   name="fields[{{$item->uid}}][visible]"
                                                   @if($item->visible) checked @endif
                                                   @if($item->tag == 'PHONE') disabled @endif
                                            />
                                            <label class="form-check-label" for="visible_{{$item->uid}}">
                                                <span class="switch-icon-left"><x-ds-icon name="check" /></span>
                                                <span class="switch-icon-right"><x-ds-icon name="x" /></span>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-merge">
                                            <span class="input-group-text" style="padding: 0.571rem 0 0.571rem 1rem;">{SUBSCRIBER_</span>
                                            <input type="text"
                                                   class="form-control text-uppercase"
                                                   name="fields[{{$item->uid}}][tag]"
                                                   value="{{ $item->tag }}"
                                                   @if($item->tag == 'PHONE') disabled @endif
                                            />
                                            <span class="input-group-text">}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-merge">
                                            <input type="{{\App\Models\ContactGroupFields::getControlNameByType($item->type)}}"
                                                   class="form-control {{\App\Models\ContactGroupFields::getControlNameByType($item->type)}}"
                                                   name="fields[{{$item->uid}}][default_value]"
                                                   value="{{ $item->default_value }}"
                                            />
                                            @if($item->type == 'date' || $item->type == 'datetime')
                                                <span class="input-group-text"><x-ds-icon name="clock" /> </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @if($item->tag != 'PHONE')

                                            <span class="action-delete-fields text-danger cursor-pointer"
                                                  data-bs-toggle="tooltip" data-bs-placement="top"
                                                  title="{{ __('locale.buttons.delete') }}"
                                                  data-field-id='{{ $item->uid }}'><x-ds-icon name="trash-2"
                                                                                      class="feather-20" /></span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach

                            </tbody>
                        </table>
                    </div>
                @endif

        <div class="mgx-gd-newfield">
            <span class="mgx-mut">Add a field:</span>
            @foreach ([['text', 'Text', 'type'], ['number', 'Number', 'hash'], ['date', 'Date', 'calendar'], ['datetime', 'Date & time', 'clock'], ['textarea', 'Long text', 'file-text']] as [$fieldType, $fieldLabel, $fieldIcon])
                <button type="button" class="mgx-tok is-btn add-custom-field-button" type_name="{{ $fieldType }}"
                        sample-url="{{ \App\Library\CrmRouting::route('contact.contact-sample-field', ['contact' => $contact->uid, 'type' => $fieldType]) }}">+ {{ $fieldLabel }}</button>
            @endforeach
        </div>
    </section>
</form>
