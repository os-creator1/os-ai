<div class="text_sample">
    <table>
        <tr data-remove-id="__index__">
            <td>
                <input type="hidden" name="fields[__index__][uid]" value="__index__"/>
            </td>

            <td>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                    <input type="number" class="form-control unit_from"
                           name="fields[__index__][unit_from]"
                           value=""
                           aria-describedby="unit_from">
                </div>
            </td>
            <td>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                    <input type="number" class="form-control unit_to"
                           name="fields[__index__][unit_to]"
                           value=""
                           aria-describedby="unit_to">
                </div>
            </td>
            <td>
                <div class="input-group input-group-merge">
                    <span class="input-group-text">{{ str_replace('{PRICE}', '', $plan->currency->format) }}</span>
                    <input type="text" class="form-control per_credit_cost"
                           name="fields[__index__][per_credit_cost]"
                           value=""
                           aria-describedby="per_credit_cost">
                </div>
            </td>
            <td>
                <span class="number_of_units"></span>
            </td>
            <td>
                <span class="remove-not-saved-field text-danger cursor-pointer" data-bs-toggle="tooltip"
                      data-bs-placement="top" title="{{ __('locale.buttons.delete') }}">
                        <i data-feather="trash-2" class="feather-20"></i></span>
            </td>
        </tr>
    </table>
</div>
