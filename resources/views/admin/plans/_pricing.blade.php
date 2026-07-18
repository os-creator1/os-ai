<!-- Basic table -->
<section id="datatables-basic">

    <div class="row">
        <div class="col-12">
            <div class="mb-1 mt-1">

                @can('edit plans')

                    <div class="btn-group">
                        <button
                                class="btn btn-primary fw-bold dropdown-toggle"
                                type="button"
                                id="bulk_actions"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                        >
                            {{ __('locale.labels.actions') }}
                        </button>
                        <div class="dropdown-menu" aria-labelledby="bulk_actions">
                            <a class="dropdown-item bulk-enable" href="#"><i
                                        data-feather="check"></i> {{ __('locale.datatables.bulk_enable') }}</a>
                            <a class="dropdown-item bulk-disable" href="#"><i
                                        data-feather="stop-circle"></i> {{ __('locale.datatables.bulk_disable') }}</a>
                            <a class="dropdown-item bulk-delete" href="#"><i
                                        data-feather="trash"></i> {{ __('locale.datatables.bulk_delete') }}</a>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="{{route('admin.plans.settings.coverage', $plan->uid)}}"
                           class="btn btn-success waves-light waves-effect fw-bold mx-1"> {{__('locale.buttons.add_coverage')}}
                            <i data-feather="plus-circle"></i></a>
                    </div>
                @endcan
            </div>
        </div>
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
                        <th>{{__('locale.labels.name')}} </th>
                        <th>{{__('locale.labels.iso_code')}}</th>
                        <th>{{__('locale.labels.country_code')}}</th>
                        <th>{{__('locale.labels.status')}}</th>
                        <th>{{__('locale.labels.actions')}}</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</section>
<!--/ Basic table -->
