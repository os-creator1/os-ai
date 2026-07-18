<div class="text_sample">
    <table>
        <tr>
            <td>
                <input type="hidden" name="fields[__index__][uid]" value="__index__"/>
            </td>

            <td>
                <div class="input-group">
                    <input type="text" class="form-control" name="fields[__index__][label]"
                           value="" aria-describedby="labelNtype">
                    <span class="input-group-text" id="labelNtype">{{ $type }}</span>
                </div>
                <input type="hidden"
                       value="{{ $type }}"
                       name="fields[__index__][type]"
                />
            </td>
            <td>
                <div class="form-check form-switch form-check-primary">
                    <input type="checkbox"
                           class="form-check-input"
                           id="required_[__index__]"
                           name="fields[__index__][required]"
                           value='1'
                    />
                    <label class="form-check-label" for="required_[__index__]">
                        <span class="switch-icon-left"><i data-feather="check"></i></span>
                        <span class="switch-icon-right"><i data-feather="x"></i></span>
                    </label>
                </div>
            </td>
            <td>
                <div class="form-check form-switch form-check-primary">
                    <input type="checkbox"
                           class="form-check-input"
                           id="visible_[__index__]"
                           name="fields[__index__][visible]"
                           value="1"
                           checked
                    />
                    <label class="form-check-label" for="visible_[__index__]">
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
                           name="fields[__index__][tag]"
                    />
                    <span class="input-group-text">}</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-merge">
                    <input type="{{\App\Models\ContactGroupFields::getControlNameByType($type)}}"
                           class="form-control {{\App\Models\ContactGroupFields::getControlNameByType($type)}}"
                           name="fields[__index__][default_value]"
                    />
                    @if($type == 'date' || $type == 'datetime')
                        <span class="input-group-text"><i data-feather="clock"></i> </span>
                    @endif
                </div>
            </td>
            <td>
                <span class="remove-not-saved-field text-danger cursor-pointer" data-bs-toggle="tooltip"
                      data-bs-placement="top" title="{{ __('locale.buttons.delete') }}">
                        <i data-feather="trash-2" class="feather-20"></i></span>
            </td>
        </tr>
    </table>
</div>
