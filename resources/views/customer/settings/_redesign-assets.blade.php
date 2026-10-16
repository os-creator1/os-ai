{{-- Shared Settings & Account page chrome (tokens-only stylesheet, no build step). --}}
<link rel="stylesheet" href="{{ asset('css/settings-redesign.css') }}?v={{ is_file(public_path('css/settings-redesign.css')) ? filemtime(public_path('css/settings-redesign.css')) : 1 }}">
