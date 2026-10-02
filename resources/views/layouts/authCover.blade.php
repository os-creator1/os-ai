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
        .auth-bg {
            position: relative;
            min-height: 100vh;
        }
        /* A tall form column (the plan cards) must not push the brand panel
           off-screen: it stays pinned to the viewport, centred, exactly where
           it sits beside the short login form. */
        .auth-brand-col {
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
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
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
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
