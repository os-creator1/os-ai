@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@extends('layouts/fullLayoutMaster')

@section('title', __('locale.http.503.title'))
@section('code', '503')

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/page-misc.css')) }}">
@endsection
@section('content')
    <!-- Error page-->
    <div class="misc-wrapper">

        <a class="brand-logo" href="{{route('login')}}">
            <img src="{{asset(config('app.logo'))}}" alt="{{config('app.name')}}"/>
        </a>

        <div class="misc-inner p-2 p-sm-3">
            <div class="w-100 text-center">
                <h2 class="mb-1">{{__('locale.http.503.title')}} 🛠</h2>
                <p class="mb-3">{{ __('locale.http.503.description') }}</p>

                @if(request('notified'))
                    <div class="alert alert-primary" role="alert">
                        <div class="alert-body">
                            <span class="align-middle"> ✅ {{ __('locale.http.503.notify') }}</span>
                        </div>
                    </div>
                @endif

                <form class="row row-cols-md-auto row justify-content-center align-items-center m-0 mb-2 gx-3" method="POST" action="{{ route('maintenance.notify') }}">
                    @csrf
                    <div class="col-12 m-0 mb-1">
                        <input class="form-control" name="email" type="email" placeholder="john@example.com" required />
                        @error('email')
                        <p><small class="text-danger">{{ $message }}</small></p>
                        @enderror
                    </div>
                    <div class="col-12 d-md-block d-grid ps-md-0 ps-auto">
                        <button class="btn btn-primary mb-1 btn-sm-block" type="submit">Notify</button>
                    </div>
                </form>

            @if($configData['theme'] === 'dark')
                    <img class="img-fluid" src="{{asset('images/pages/under-maintenance-dark.svg')}}" alt="Under maintenance page" />
                @else
                    <img class="img-fluid" src="{{asset('images/pages/under-maintenance.svg')}}" alt="Under maintenance page" />
                @endif
            </div>
        </div>
    </div>
    <!-- / Error page-->
@endsection
