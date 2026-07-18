<div class="row" id="basic-table">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">{{ __('locale.labels.manage_fields') }}</h4>
            </div>
            <div class="card-body">
                <p class="card-text">
                    Manage the fields available to your groups contacts.
                </p>
            </div>

            <form action="{{ route('customer.contact.store-contact-field', $contact->uid) }}"
                  method="post">
                {{ csrf_field() }}

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
                                                <span class="switch-icon-left"><i data-feather="check"></i></span>
                                                <span class="switch-icon-right"><i data-feather="x"></i></span>
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
                                                <span class="switch-icon-left"><i data-feather="check"></i></span>
                                                <span class="switch-icon-right"><i data-feather="x"></i></span>
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
                                                <span class="input-group-text"><i data-feather="clock"></i> </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        @if($item->tag != 'PHONE')

                                            <span class="action-delete-fields text-danger cursor-pointer"
                                                  data-bs-toggle="tooltip" data-bs-placement="top"
                                                  title="{{ __('locale.buttons.delete') }}"
                                                  data-field-id='{{ $item->uid }}'><i data-feather="trash-2"
                                                                                      class="feather-20"></i></span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach

                            </tbody>
                        </table>
                    </div>
                @endif

                <hr/>

                <div class="card-header">
                    <h4 class="card-title">New Field</h4>
                </div>

                <div class="card-body">
                    <p class="card-text">
                        Click To add
                    </p>

                    <span sample-url="{{ route('customer.contact.contact-sample-field', ['contact' => $contact->uid,"type" => "text" ]) }}"
                          class="btn btn-relief-primary me-1 add-custom-field-button" type_name="text">
                        <i data-feather="type" class="me-25"></i>
                        Text
                    </span>

                    <span sample-url="{{ route('customer.contact.contact-sample-field', ['contact' => $contact->uid,"type" => "number" ]) }}"
                          class="btn btn-relief-success me-1 add-custom-field-button" type_name="number">
                        <i data-feather="hash" class="me-25"></i>
                        Number
                    </span>

                    <span sample-url="{{ route('customer.contact.contact-sample-field', ['contact' => $contact->uid,"type" => "date" ]) }}"
                          class="btn btn-relief-info me-1 add-custom-field-button" type_name="date">
                        <i data-feather="calendar" class="me-25"></i>
                        Date
                    </span>

                    <span sample-url="{{ route('customer.contact.contact-sample-field', ['contact' => $contact->uid,"type" => "datetime" ]) }}"
                          class="btn btn-relief-warning me-1 add-custom-field-button" type_name="datetime">
                        <i data-feather="clock" class="me-25"></i>
                        Datetime
                    </span>

                    <span sample-url="{{ route('customer.contact.contact-sample-field', ['contact' => $contact->uid,"type" => "textarea" ]) }}"
                          class="btn btn-relief-danger me-1 add-custom-field-button" type_name="textarea">
                        <i data-feather="file-text" class="me-25"></i>
                        Textarea
                    </span>
                </div>
                <hr/>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">

                            <button type="submit" class="btn btn-primary">
                                <i data-feather="save"></i> {{__('locale.buttons.save_changes')}}
                            </button>
                        </div>
                    </div>
                </div>


            </form>
        </div>
    </div>
</div>
