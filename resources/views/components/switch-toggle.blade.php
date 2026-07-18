<div class='form-check form-switch form-check-primary'>
    <input type='checkbox' class='form-check-input get_status' id='status_{{$id}}' data-id='{{$id}}' name='status' {{ $checked ? 'checked' : '' }}>
    <label class='form-check-label' for='status_{{$id}}'>
        <span class='switch-icon-left'><i data-feather='check'></i> </span>
        <span class='switch-icon-right'><i data-feather='x'></i> </span>
    </label>
</div>
