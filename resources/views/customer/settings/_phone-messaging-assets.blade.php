{{-- Shared chrome for the Phone & Messaging pages (registration, usage & billing, get a number). --}}
@include('customer.settings._redesign-assets')
<link rel="stylesheet" href="{{ asset('css/phone-messaging.css') }}?v={{ is_file(public_path('css/phone-messaging.css')) ? filemtime(public_path('css/phone-messaging.css')) : 1 }}">
