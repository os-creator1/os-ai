{{-- Inside the calling shell's frame: the page-side bridge only (the shell owns the softphone). Renders nothing elsewhere. --}}
@if (strtolower((string) request()->header('Sec-Fetch-Dest', '')) === 'iframe')
    <script src="{{ asset('js/crm/mg-frame-bridge.js') }}?v=1"></script>
@endif
