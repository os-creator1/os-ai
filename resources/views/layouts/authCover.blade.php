@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
    @yield('auth-style')

    <style>
        /*
         * Auth branding is sized to the AVAILABLE VIEWPORT, not the image's
         * intrinsic pixels. Both the owner's logo and any uploaded illustration
         * must fit even when the browser is resized or the device is rotated.
         * Scope these rules to the auth cover so in-app branding is unaffected.
         */
        .auth-wrapper.auth-cover .auth-inner {
            height: 100vh;
            height: 100dvh;
        }

        .auth-wrapper.auth-cover > .auth-inner > .brand-logo {
            position: absolute;
            z-index: 2;
            max-inline-size: min(36vw, calc(100vw - 4rem));
            min-inline-size: 0;
        }

        /* The branding-logo component can emit an ordinary, unclassed <img>.
           Constrain THAT image, not only the agency-specific mark class. */
        .auth-wrapper.auth-cover > .auth-inner > .brand-logo img {
            display: block;
            inline-size: auto;
            block-size: auto;
            max-inline-size: 100%;
            max-block-size: 7vh;
            max-block-size: 7dvh;
            object-fit: contain;
        }

        .auth-wrapper.auth-cover .auth-bg {
            position: relative;
            min-height: 100vh;
            min-height: 100dvh;
        }

        /* The brand panel remains in its own viewport-height column even
           when long signup forms scroll on the right. The top inset reserves
           space for the separate corner logo. */
        .auth-wrapper.auth-cover .auth-brand-col {
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
            height: 100dvh;
            min-width: 0;
            min-height: 0;
            overflow: hidden;
            padding-top: max(5rem, 11vh) !important;
            padding-top: max(5rem, 11dvh) !important;
        }

        .auth-wrapper.auth-cover .auth-brand-media {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
            min-height: 0;
            height: 100%;
        }

        /* Width AND height are bounded by the real available area.
           object-fit preserves aspect ratio: panoramas, portraits and square
           artwork all scale down instead of overflowing into the form. */
        .auth-wrapper.auth-cover .auth-brand-col .auth-brand-illustration {
            display: block;
            inline-size: auto;
            block-size: auto;
            max-inline-size: 100%;
            max-block-size: min(56vh, 100%);
            max-block-size: min(56dvh, 100%);
            object-fit: contain;
        }

        .auth-wrapper.auth-cover .auth-brand-col .auth-brand-panel {
            max-height: 100%;
            overflow-y: auto;
        }

        /* The illustration panel is hidden at tablet/phone widths. Only the
           small corner identity remains, and the form reserves its space. */
        @media (max-width: 991.98px) {
            .auth-wrapper.auth-cover > .auth-inner > .brand-logo {
                max-inline-size: min(74vw, calc(100vw - 3rem));
            }

            .auth-wrapper.auth-cover > .auth-inner > .brand-logo img {
                max-block-size: 8vh;
                max-block-size: 8dvh;
            }

            .auth-wrapper.auth-cover .auth-bg {
                padding-top: max(5rem, 12vh) !important;
                padding-top: max(5rem, 12dvh) !important;
                box-sizing: border-box;
            }
        }

        @media (max-width: 575.98px) {
            .auth-wrapper.auth-cover > .auth-inner > .brand-logo {
                inset-block-start: 1rem;
                inset-inline-start: 1rem;
                max-inline-size: calc(100vw - 2rem);
            }
        }
    </style>
@endsection

@section('content')
    {{--
        THE ONE AUTH SHELL. Login, email verification and every V1 signup
        step render inside this single layout, so they are literally the
        same product: corner identity (the branding seam — an authorized
        Agency brand or the neutral platform mark), the branded presentation
        panel on the left, and the form column on the right.

        A page supplies only `@section('auth-form')` (and optionally
        `@section('auth-style')` for page-level <head> additions). Nothing
        about the shell is repeated per page.
    --}}
    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{ route('login') }}" aria-label="{{ $authBrand->displayName }}">
                <x-branding-illustration surface="auth-mark" />
            </a>
            <!-- /Brand logo-->

            <!-- Brand panel-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5 auth-brand-col">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5 auth-brand-media">
                    <x-branding-illustration surface="auth" :dark="$configData['theme'] === 'dark'" />
                </div>
            </div>
            <!-- /Brand panel-->

            <!-- Auth form-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    @yield('auth-form')
                </div>
            </div>
            <!-- /Auth form-->
        </div>
    </div>
@endsection
