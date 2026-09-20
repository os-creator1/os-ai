{{-- Contract 16 §12.E — shared status/error block for the catalog screens.
     `catalog` carries a domain refusal exactly as the manager worded it;
     the other errors are ordinary request-shape validation. --}}
@if (session('flash_success'))
    <div class="alert alert-success" role="status" data-role="catalog-success">
        <div class="alert-body">{{ session('flash_success') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger" role="alert" data-role="catalog-errors">
        <div class="alert-body">
            @foreach ($errors->all() as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    </div>
@endif
