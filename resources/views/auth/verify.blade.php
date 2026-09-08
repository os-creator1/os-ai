@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.verify_email_address'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
@endsection

@section('content')

    {{-- Customer Experience Slice 2: neutral/Agency identity through the branding seam; no login-v2* fallback. --}}
    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}" aria-label="{{ $authBrand->displayName }}">
                <x-branding-illustration surface="auth-mark" />
            </a>
            <!-- /Brand logo-->


            <!-- Brand panel-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
                    <x-branding-illustration surface="auth" :dark="$configData['theme'] === 'dark'" />
                </div>
            </div>
            <!-- /Brand panel-->

            <!-- Verify email-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">

                    @if (session('status'))
                        <x-alert variant="success" role="status">
                            {{ __('locale.auth.fresh_verification_link') }}
                        </x-alert>
                    @endif

                    <h1 class="card-title fw-bold mb-1 h2">{{ __('locale.auth.verify_email_address') }}</h1>
                    <p class="card-text mb-2">{{ __('locale.auth.resend_verification_link') }}</p>
                    <form id="resend-form" action="{{ route('verification.send') }}" method="POST" class="mt-2">
                        {{ csrf_field() }}
                        <p class="card-text mb-1">{{ __('locale.auth.did_not_receive_email') }}</p>
                        <button type="submit" class="btn btn-outline-primary w-100">{{ __('locale.auth.request_another_link') }}</button>
                    </form>
                </div>
            </div>
            <!-- /Verify email-->

        </div>
    </div>
@endsection
