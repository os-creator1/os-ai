{{-- Forms V1 — shared status/error block for the Forms screens. `forms`
     carries a domain refusal exactly as FormManager worded it; the other
     errors are ordinary request-shape validation. --}}
@if (session('flash_success'))
    <x-alert variant="success" :dismissible="true" role="status" data-role="forms-success">{{ session('flash_success') }}</x-alert>
@endif

@if ($errors->any())
    <x-alert variant="danger" :dismissible="true" role="alert" data-role="forms-errors">
        @foreach ($errors->all() as $message)
            <div>{{ $message }}</div>
        @endforeach
    </x-alert>
@endif
