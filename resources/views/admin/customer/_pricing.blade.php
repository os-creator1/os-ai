<div class="row">
    <div class="col-12">
        <div class="mb-1 mt-1">

            @can('edit customer')
                <div class="btn-group">
                    <a href="{{route('admin.customers.coverage', $customer->uid)}}" class="btn btn-primary waves-light waves-effect fw-bold mx-1"> {{__('locale.buttons.add_coverage')}} <i data-feather="plus-circle"></i></a>
                </div>
            @endcan
        </div>
    </div>
</div>

<!-- Basic table -->
<section id="datatables-basic">

    <div class="row">
        <div class="col-12">
            <div class="card">
                <table class="table pricing_table datatables-basic">
                    <thead>
                    <tr>
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
