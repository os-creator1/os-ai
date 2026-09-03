@if($contact->cache)
    <div class="row match-height">

        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">{{ $contact->readCache('TotalSubscribers') }}</h2>
                        <p class="card-text">{{ __('locale.labels.total') }}</p>
                    </div>

                    <div>
                        <x-ds-icon name="users" class="font-large-3 text-primary" />
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">{{ $contact->readCache('SubscribersCount') }}</h2>
                        <p class="card-text">{{ __('locale.contacts.active_contacts') }}</p>
                    </div>

                    <div>
                        <x-ds-icon name="user-check" class="font-large-3 text-success" />
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-sm-6 col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="fw-bolder mb-0">{{ $contact->readCache('UnsubscribesCount') }}</h2>
                        <p class="card-text">{{ __('locale.contacts.inactive_contacts') }}</p>
                    </div>
                    <div>
                        <x-ds-icon name="user-x" class="font-large-3 text-danger" />
                    </div>

                </div>
            </div>
        </div>
    </div>
@endif


<div id="datatables-basic">

    <div class="mb-3 mt-2">
        @can('view_contact')
            <div class="btn-group">
                <x-button
                        class="fw-bold dropdown-toggle me-1"
                        type="button"
                        id="bulk_actions"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                >
                    {{ __('locale.labels.actions') }}
                </x-button>
                <div class="dropdown-menu" aria-labelledby="bulk_actions">
                    <a class="dropdown-item bulk-subscribe" href="#"><x-ds-icon
                                name="check" /> {{ __('locale.labels.subscribe') }}</a>
                    <a class="dropdown-item bulk-unsubscribe" href="#"><x-ds-icon
                                name="stop-circle" /> {{ __('locale.labels.unsubscribe') }}</a>
                    <a class="dropdown-item bulk-copy" href="#"><x-ds-icon
                                name="copy" /> {{ __('locale.buttons.copy') }}</a>
                    <a class="dropdown-item bulk-move" href="#"><x-ds-icon
                                name="move" /> {{ __('locale.buttons.move') }}</a>
                    @if(Auth::user()->can('delete_contact'))
                        <a class="dropdown-item bulk-delete" href="#"><x-ds-icon
                                    name="trash" /> {{ __('locale.datatables.bulk_delete') }}</a>
                    @endif
                </div>
            </div>
        @endcan

        @can('create_contact')
            <div class="btn-group">
                <a href="{{route('customer.contact.create', $contact->uid)}}"
                   class="btn btn-success waves-light waves-effect fw-bold me-1"> {{__('locale.buttons.add_new')}} <x-ds-icon
                            name="plus-circle" /></a>
            </div>
        @endcan

        @can('view_contact')
            <div class="btn-group">
                <a href="{{ route('customer.contact.import', $contact->uid) }}"
                   class="btn btn-secondary waves-light waves-effect fw-bold me-1"> {{__('locale.buttons.import')}} <x-ds-icon
                            name="upload" /></a>
            </div>

            <div class="btn-group  me-1">
                <button id="export-contact"
                        class="btn btn-info waves-light waves-effect fw-bold"> {{__('locale.buttons.export')}} <x-ds-icon
                            name="download" /></button>
            </div>


            <div class="btn-group">
                <x-button
                        variant="outline"
                        class="fw-bold dropdown-toggle"
                        type="button"
                        id="columns"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                >
                    {{ __('locale.labels.columns') }}
                </x-button>
                <div class="dropdown-menu" aria-labelledby="columns">
                    @php
                        $key = 6;
                    @endphp
                    @foreach ($contact->getFields as  $field)
                        @if ($field->tag != "PHONE")
                            <a class="dropdown-item toggle-vis" href="#" data-column="{{$key++}}"><x-ds-icon class="toggle-icon"
                                                                                                     name="eye" /> {{ $field->label }}
                            </a>
                        @endif
                    @endforeach

                </div>
            </div>
        @endcan


    </div>

    <div>
    </div>

    <div class="row">
        <div class="col-12">
            <x-card :padded="false">
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
            </x-card>
        </div>
    </div>

</div>


<div class="modal fade" id="exportContactModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" action="{{route('customer.contact.export', $contact->uid)}}">
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
