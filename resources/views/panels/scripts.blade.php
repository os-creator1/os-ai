<!-- BEGIN: Vendor JS-->
<script src="{{ asset(mix('vendors/js/vendors.min.js')) }}"></script>
<!-- BEGIN Vendor JS-->

<!-- BEGIN: Page Vendor JS-->
<script src="{{asset(mix('vendors/js/ui/jquery.sticky.js'))}}"></script>
@yield('vendor-script')
<!-- END: Page Vendor JS-->

<!-- BEGIN: Theme JS-->
<script src="{{ asset(mix('js/core/app-menu.js')) }}"></script>
@php
    // Design System M2 Slice 1 contract §9 item 71. Deliberately not
    // mix('js/core/theme-tokens.js'): Illuminate\Foundation\Mix::__invoke()
    // caches the decoded manifest in a `static $manifests` array scoped to
    // that one method, populated once on first use and never re-read again
    // for the rest of the PHP process. A process whose first Mix lookup
    // anywhere happens before this asset's entry is visible (e.g. a test
    // run whose first HTTP request lands before a rebuild is fully visible
    // to that process) keeps serving that stale snapshot to every later
    // request or test in the same process, even once the file on disk is
    // correct -- exactly the failure mode this reference must not have,
    // since theme-tokens.js is brand new and every other cached entry in
    // the same manifest predates it. This reads the manifest fresh on
    // every render instead, reproducing mix()'s own lookup and
    // debug-mode-aware failure behavior exactly, without the stale cache.
    // Every other asset reference in this file keeps using mix() normally.
    $themeTokensManifestPath = public_path('mix-manifest.json');
    $themeTokensManifest = is_file($themeTokensManifestPath)
        ? json_decode(file_get_contents($themeTokensManifestPath), true)
        : null;
    $themeTokensKey = '/js/core/theme-tokens.js';
    if (! is_array($themeTokensManifest) || ! isset($themeTokensManifest[$themeTokensKey])) {
        $themeTokensException = new \Illuminate\Foundation\MixFileNotFoundException("Unable to locate Mix file: {$themeTokensKey}.");
        if (config('app.debug')) {
            throw $themeTokensException;
        }
        report($themeTokensException);
    }
    $themeTokensSrc = $themeTokensManifest[$themeTokensKey] ?? $themeTokensKey;
@endphp
<script src="{{ asset($themeTokensSrc) }}"></script>
<script src="{{ asset(mix('js/core/app.js')) }}"></script>

<!-- custom scripts file for user -->
<script src="{{ asset(mix('js/core/scripts.js')) }}"></script>

<!-- END: Theme JS-->


<script src="{{ asset(mix('vendors/js/extensions/toastr.min.js')) }}"></script>

<script>
    let isRtl = $('html').attr('data-textdirection') === 'rtl';
</script>


@if(session('check_subscription') && Auth::check() && Auth::user()->active_portal === 'customer' && Auth::user()->is_customer == 1)
    @if(Auth::user()->customer->activeSubscription() === null)
        <script>
          toastr['warning']("{!! __('locale.customer.no_active_subscription') !!}", 'Warning!', {
            closeButton: true,
            positionClass: 'toast-top-right',
            progressBar: true,
            newestOnTop: true,
            rtl: isRtl
          });
        </script>
    @endif
@endif



{{-- page script --}}
@yield('page-script')
<script>
    $(document).on('select2:open', () => {
        document.querySelector('.select2-search__field').focus();
    });
</script>

@if(Session::has('message'))
    <script>
        let type = "{{ Session::get('status', 'success') }}";
        switch (type) {
            case 'info':
                toastr['info']("{!! Session::get('message') !!}", "{{ __('locale.labels.information') }}!", {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });

                break;

            case 'warning':
                toastr['warning']("{!! Session::get('message') !!}", "{{ __('locale.labels.warning') }}!", {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });
                break;

            case 'success':
                toastr['success']("{!! Session::get('message') !!}", "{{ __('locale.labels.success') }}!", {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });
                break;

            case 'error':
                toastr['error']("{!! Session::get('message') !!}", "{{ __('locale.labels.ops') }}..!!", {
                    closeButton: true,
                    positionClass: 'toast-top-right',
                    progressBar: true,
                    newestOnTop: true,
                    rtl: isRtl
                });
                break;
        }
    </script>
@endif

@if (isset($errors) && $errors->any())
    <script>
        $(document).ready(function () {
            toastr["error"]("{{ $errors->first() }}", "{{ __('locale.labels.opps') }}", {
                closeButton: true,
                positionClass: "toast-top-right",
                progressBar: true,
                newestOnTop: true,
                rtl: isRtl
            });
        });
    </script>
@endif

