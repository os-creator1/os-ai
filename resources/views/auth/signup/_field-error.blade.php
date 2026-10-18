@error($field)
    <x-alert variant="danger" class="mt-1 alert-validation-msg" id="{{ $field }}-error" role="alert">
        <div class="alert-body d-flex align-items-center">
            <x-ds-icon name="info" class="me-50" aria-hidden="true" />
            <span>{{ $message }}</span>
        </div>
    </x-alert>
@enderror
