@if($contact->cache)
    <div class="mgx-gd-stats" data-role="group-stats">
        <span><b>{{ $contact->readCache('TotalSubscribers') }}</b> {{ __('locale.labels.total') }}</span>
        <span><b>{{ $contact->readCache('SubscribersCount') }}</b> {{ __('locale.contacts.active_contacts') }}</span>
        <span><b>{{ $contact->readCache('UnsubscribesCount') }}</b> {{ __('locale.contacts.inactive_contacts') }}</span>
    </div>
@endif

<div id="datatables-basic" class="mgx-card mgx-gd-card mgx-gd-contacts">
    @can('view_contact')
        <div class="mgx-gd-toolbar">
            <div class="dropdown">
                <button class="mgx-btn is-ghost dropdown-toggle" type="button" id="bulk_actions" data-bs-toggle="dropdown" aria-expanded="false">
                    {{ __('locale.labels.actions') }}<svg class="mgx-ic mgx-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                </button>
                <div class="dropdown-menu" aria-labelledby="bulk_actions">
                    <a class="dropdown-item bulk-subscribe" href="#"><x-ds-icon name="check" /> {{ __('locale.labels.subscribe') }}</a>
                    <a class="dropdown-item bulk-unsubscribe" href="#"><x-ds-icon name="stop-circle" /> {{ __('locale.labels.unsubscribe') }}</a>
                    <a class="dropdown-item bulk-copy" href="#"><x-ds-icon name="copy" /> {{ __('locale.buttons.copy') }}</a>
                    <a class="dropdown-item bulk-move" href="#"><x-ds-icon name="move" /> {{ __('locale.buttons.move') }}</a>
                    @if(Auth::user()->can('delete_contact'))
                        <a class="dropdown-item bulk-delete" href="#"><x-ds-icon name="trash" /> {{ __('locale.datatables.bulk_delete') }}</a>
                    @endif
                </div>
            </div>

            <div class="dropdown">
                <button class="mgx-btn is-ghost dropdown-toggle" type="button" id="columns" data-bs-toggle="dropdown" aria-expanded="false">
                    {{ __('locale.labels.columns') }}<svg class="mgx-ic mgx-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                </button>
                <div class="dropdown-menu" aria-labelledby="columns">
                    @php
                        $key = 6;
                    @endphp
                    @foreach ($contact->getFields as  $field)
                        @if ($field->tag != "PHONE")
                            <a class="dropdown-item toggle-vis" href="#" data-column="{{$key++}}"><x-ds-icon class="toggle-icon" name="eye" /> {{ $field->label }}</a>
                        @endif
                    @endforeach
                </div>
            </div>

            <a href="{{ \App\Library\CrmRouting::route('contact.import', $contact->uid) }}" class="mgx-btn is-ghost"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 20V9M7 14l5-5 5 5M5 4h14"/></svg>{{ __('locale.buttons.import') }}</a>
            <button type="button" id="export-contact" class="mgx-btn is-ghost"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>{{ __('locale.buttons.export') }}</button>
        </div>
    @endcan

    <table class="table datatables-basic">
        <thead>
        <tr>
            <th></th>
            <th></th>
            <th>{{ __('locale.labels.id') }}</th>
            <th>{{__('locale.menu.Contacts')}}</th>
            <th>{{__('locale.labels.updated_at')}}</th>
            <th>{{__('locale.labels.status')}}</th>
            @foreach ($contact->getFields as $key => $field)
                @if ($field->tag != "PHONE")
                    <th>{{ $field->label }}</th>
                @endif
            @endforeach
            <th>{{__('locale.labels.actions')}}</th>
        </tr>
        </thead>
    </table>
</div>


<div class="modal fade" id="exportContactModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{{\App\Library\CrmRouting::route('contact.export', $contact->uid)}}">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Overview</h5>
                </div>
                <div class="modal-body">

                    @csrf
                    <div class="col-12">
                        <div class="mb-1">
                            <x-button type="button" id="select-all-btn" size="sm" icon="check-square">{{__('locale.labels.select_all')}}</x-button>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="mb-1">
                            <label for="contact_fields"
                                   class="form-label required">{{ __('locale.contacts.contact_fields') }}</label>
                            <select class="select2 form-select" name="contact_fields[]" multiple="multiple"
                                    id="contact_fields" required>
                                @foreach ($fields as $field)
                                    <option value="{{ $field->tag }}"> {{ucwords($field->label)}}</option>
                                @endforeach
                            </select>

                            @error('contact_fields')
                            <p><small class="text-danger">{{ $message }}</small></p>
                            @enderror
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="mb-1">
                            <div class="form-check form-check-inline">
                                <input type="checkbox" id="include_phone" name="include_phone" class="form-check-input"
                                       value="true" checked>
                                <label class="form-check-label" for="include_phone">
                                    {{ __('locale.contacts.force_export_phone_number') }}
                                </label> &nbsp;

                                <x-tooltip role="button" :text="__('locale.contacts.force_export_phone_number_help')"
                                      data-tippy-id="customer.contacts.show.force_export_phone_number"><x-ds-icon
                                            name="info" /></x-tooltip>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="closeExportContact" type="button" class="btn btn-secondary" data-bs-dismiss="modal"><x-ds-icon
                                name="x" /> {{ __('locale.buttons.close') }}</button>
                    <x-button type="submit"
                            id="finalExportContact">{{ __('locale.buttons.export') }} <x-ds-icon name="download" />
                    </x-button>

                </div>
            </form>
        </div>
    </div>
</div>
