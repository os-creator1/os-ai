<section id="datatables-basic">

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header"></div>
                <div class="card-body">
                    <form action="{{ route('admin.plans.settings.update-credit-price', $plan->uid) }}" method="post">
                        @csrf

                        <div class="row d-flex align-items-end">


                            <div class="table-responsive">
                                <table class="table field-list">
                                    <thead>
                                    <tr>
                                        <th colspan="3" class="text-center">{{ __('locale.plans.recharge_volume') }}</th>
                                        <th>{{ __('locale.labels.per_unit_price') }}</th>
                                        <th class="text-center text-nowrap">{{ __('locale.plans.number_of_units') }}</th>
                                        <th>{{ __('locale.labels.actions') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @if($plan->getCreditPrices()->count() > 0)
                                        @foreach ($plan->getCreditPrices()->orderBy('unit_from')->get() as $key => $item)
                                            <tr data-remove-id="{{ $item->uid }}">
                                                <td>
                                                    <input type="hidden" name="fields[{{ $item->uid }}][uid]"
                                                           value="{{ $item->uid }}"/>
                                                </td>

                                                <td>
                                                    <div class="input-group input-group-merge">
                                                        <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                        <input type="number" class="form-control unit_from"
                                                               name="fields[{{$item->uid}}][unit_from]"
                                                               value="{{ $item->unit_from }}"
                                                               aria-describedby="unit_from">
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="input-group input-group-merge">
                                                        <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                        <input type="number" class="form-control unit_to"
                                                               name="fields[{{$item->uid}}][unit_to]"
                                                               value="{{ $item->unit_to }}"
                                                               aria-describedby="unit_to">
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="input-group input-group-merge">
                                                        <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                                                        <input type="text" class="form-control per_credit_cost"
                                                               name="fields[{{$item->uid}}][per_credit_cost]"
                                                               value="{{ $item->per_credit_cost }}"
                                                               aria-describedby="per_credit_cost">
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="number_of_units"> {{ $item->calculateUnits() }}</span>
                                                </td>
                                                <td>
                                                           <span class="action-delete-fields text-danger cursor-pointer"
                                                                 data-bs-toggle="tooltip" data-bs-placement="top"
                                                                 title="{{ __('locale.buttons.delete') }}"
                                                                 data-field-id='{{ $item->uid }}'><i
                                                                       data-feather="trash-2"
                                                                       class="feather-20"></i></span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                    </tbody>
                                </table>
                            </div>


                        </div>

                        <hr/>
                        <div class="row">
                            <div class="col-12">


                                <button class="btn btn-icon btn-primary me-1" type="submit">
                                    <i data-feather="save" class="me-25"></i>
                                    <span>{{ __('locale.buttons.save_changes') }}</span>
                                </button>

                                <span sample-url="{{ route('admin.plans.settings.add-credit-price-field', $plan->uid) }}"
                                      class="btn btn-relief-success me-1 add-custom-field-button"
                                      type_name="text">
                                    <i data-feather="plus" class="me-25"></i>
                                    {{ __('locale.buttons.add_new') }}
                                </span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
