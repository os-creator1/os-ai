<div id="datatables-basic">

    <div class="mb-2 mt-2">
        @can('view announcement')
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

                    <a class="dropdown-item bulk-delete" href="#"><x-ds-icon
                                name="trash" /> {{ __('locale.datatables.bulk_delete') }}</a>
                </div>
            </div>
        @endcan

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
                        <th>{{__('locale.labels.title')}} </th>
                        <th>{{__('locale.labels.created_at')}}</th>
                        <th>{{__('locale.labels.actions')}}</th>
                    </tr>
                    </thead>
                </table>
            </x-card>
        </div>
    </div>

</div>
