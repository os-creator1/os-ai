{{-- Contract 16 §12.E — shared status/error block for the catalog screens.
     `catalog` carries a domain refusal exactly as the manager worded it;
     the other errors are ordinary request-shape validation. --}}
@if (session('flash_success'))
    <x-alert variant="success" :dismissible="true" role="status" data-role="catalog-success">{{ session('flash_success') }}</x-alert>
@endif

@if ($errors->any())
    <x-alert variant="danger" :dismissible="true" role="alert" data-role="catalog-errors">
        @foreach ($errors->all() as $message)
            <div>{{ $message }}</div>
        @endforeach
    </x-alert>
@endif
