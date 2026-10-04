@if (session('message'))
    <div class="alert alert-{{ session('status') === 'error' ? 'danger' : 'success' }}" role="status">
        <div class="alert-body">{{ session('message') }}</div>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <div class="alert-body">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    </div>
@endif
