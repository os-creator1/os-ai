@extends('marketing.layout', ['faqs' => $mg->faqs('home')])

@section('content')
@foreach ($mg->homeSections() as $homeSection)
@include(\App\Library\Marketing\MarketingSite::HOME_SECTIONS[$homeSection])
@endforeach
@endsection

@section('footer')
<div class="hide-m">@include('marketing.partials.footer-full')</div>
<div class="show-m">@include('marketing.partials.footer-phone')</div>
@endsection
