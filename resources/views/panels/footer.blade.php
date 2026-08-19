<!-- BEGIN: Footer-->
<footer
        class="footer footer-light transition-base {{ $configData['footerType'] === 'footer-hidden' ? 'd-none' : '' }} {{ $configData['footerType'] }}">
    <p class="clearfix mb-0">
        <span class="float-md-left d-block d-md-inline-block mt-25"> <x-branding-footer />
            <span class="d-none d-sm-inline-block">{{ __('locale.labels.all_rights_reserved') }}</span>
        </span>


        @php
            $hasPrivacyPolicy = !empty(config('app.privacy_policy'));
            $hasTermsOfUse = !empty(config('app.terms_of_use'));
            $hasCustomScript = \App\Helpers\Helper::app_config('custom_script') ? \App\Helpers\Helper::app_config('custom_script') : null;
        @endphp
        @if($hasPrivacyPolicy || $hasTermsOfUse)

            <span class="float-md-end text-capitalize">
                @if($hasTermsOfUse)
                    <a class="ms-25 text-success" target="_blank"
                       href="{{ route('terms-of-use') }}">{{ __('locale.labels.terms_of_use') }}</a>
                @endif

                @if($hasPrivacyPolicy)
                    <a class="ms-25 text-info" target="_blank"
                       href="{{ route('privacy-policy') }}">{{ __('locale.labels.privacy_policy') }}</a>
                @endif
        </span>
        @endif

        @if($hasCustomScript !== null)
            {!! $hasCustomScript !!}
        @endif
    </p>
</footer>
<button class="btn btn-primary btn-icon scroll-top transition-fast" type="button"><x-ds-icon name="arrow-up" /></button>
<!-- END: Footer-->
