@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.sub_accounts.accept_invitation'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
@endsection

@section('content')

    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}">
                <img src="{{asset(config('app.logo'))}}" alt="{{config('app.name')}}" />
            </a>
            <!-- /Brand logo-->

            <!-- Left Text-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
                    @if($configData['theme'] === 'dark')
                        <img class="img-fluid" src="{{asset('images/pages/not-authorized-dark.svg.svg')}}"
                             alt="{{config('app.name')}}" />
                    @else
                        <img class="img-fluid" src="{{asset('images/pages/not-authorized.svg')}}"
                             alt="{{config('app.name')}}" />
                    @endif
                </div>
            </div>
            <!-- /Left Text-->

            <!-- Accept Invitation-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h2 class="card-title fw-bold mb-1">{{ __('locale.sub_accounts.accept_invitation') }}</h2>
                    <p class="card-text mb-2">{{ __('locale.sub_accounts.accept_invitation_description') }}</p>
                    <form class="auth-forgot-password-form mt-2" method="POST"
                          action="{{ route('sub_account.accept.submit', $token) }}">
                        @csrf

                        <div class="col-12">
                            <div class="mb-1">
                                <label class="form-label required"
                                       for="password">{{ __('locale.labels.password') }}</label>
                                <div class="input-group input-group-merge form-password-toggle">
                                    <input type="password" id="password"
                                           class="form-control @error('password') is-invalid @enderror"
                                           name="password" />
                                    <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                </div>
                                @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="mb-1">
                                <label class="form-label required"
                                       for="password_confirmation">{{ __('locale.labels.password_confirmation') }}</label>
                                <div class="input-group input-group-merge form-password-toggle">
                                    <input type="password" id="password_confirmation"
                                           class="form-control @error('password_confirmation') is-invalid @enderror"
                                           name="password_confirmation" />
                                    <span class="input-group-text cursor-pointer"><x-ds-icon name="eye" /></span>
                                </div>
                            </div>
                        </div>


                        <button type="submit" class="btn btn-primary w-100"
                                tabindex="2">{{ __('locale.sub_accounts.active_account') }}</button>
                    </form>
                    <p class="text-center mt-2">
                        <a href="{{url('login')}}">
                            <x-ds-icon name="chevron-left" /> {{ __('locale.auth.back_to_login') }}
                        </a>
                    </p>
                </div>
            </div>
            <!-- /Accept Invitation-->

        </div>
    </div>
@endsection
