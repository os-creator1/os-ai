@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@extends('layouts/fullLayoutMaster')

@section('title', __('locale.http.403.title'))
@section('code', '403')

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/page-misc.css')) }}">
@endsection
@section('content')
    <!-- Error page-->
    <div class="misc-wrapper">

        <a class="brand-logo" href="{{route('login')}}">
            <x-branding-logo variant="full" background="light" />
        </a>

        <div class="misc-inner p-2 p-sm-3">
            <div class="w-100 text-center">
                <h2 class="mb-1">{{__('locale.http.403.title')}}!️</h2>
                <p class="mb-2">{{ __($exception->getMessage() ?: __('locale.http.403.description')) }}</p>
                <x-button variant="primary" href="{{ route('login') }}" class="mb-2 btn-sm-block">{{__('locale.labels.back_to_home')}}</x-button>
                @if($configData['theme'] === 'dark')
                    <img class="img-fluid" src="{{asset('images/pages/error-dark.svg')}}" alt="Error page" />
                @else
                    <img class="img-fluid" src="{{asset('images/pages/error.svg')}}" alt="Error page" />
                @endif
            </div>
        </div>
    </div>
    <!-- / Error page-->
@endsection
