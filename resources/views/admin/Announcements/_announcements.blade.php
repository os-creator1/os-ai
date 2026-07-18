<div id="datatables-basic">

    <div class="mb-2 mt-2">
        @can('view announcement')
            <div class="btn-group">
                <button
                        class="btn btn-primary fw-bold dropdown-toggle me-1"
                        type="button"
                        id="bulk_actions"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                >
                    {{ __('locale.labels.actions') }}
                </button>
                <div class="dropdown-menu" aria-labelledby="bulk_actions">

                    <a class="dropdown-item bulk-delete" href="#"><i
                                data-feather="trash"></i> {{ __('locale.datatables.bulk_delete') }}</a>
                </div>
            </div>
        @endcan

    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
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
            </div>
        </div>
    </div>

</div>
