{{-- Forms V1 — shared status/error block for the Forms screens. `forms`
     carries a domain refusal exactly as FormManager worded it; the other
     errors are ordinary request-shape validation. --}}
@if (session('flash_success'))
    <div class="alert alert-success" role="status" data-role="forms-success">
        <div class="alert-body">{{ session('flash_success') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger" role="alert" data-role="forms-errors">
        <div class="alert-body">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    </div>
@endif
