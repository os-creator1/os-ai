@extends('marketing.layout', ['faqs' => $mg->faqs('home')])

@if (! empty($embed))
@push('head')
@include('public.booking._scheduler-styles')
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush
@endif

@section('content')
@foreach ($mg->homeSections() as $homeSection)
@include($mg->homeSectionView($homeSection))
@endforeach
@endsection

@section('footer')
<div class="hide-m">@include('marketing.partials.footer-full')</div>
<div class="show-m">@include('marketing.partials.footer-phone')</div>
@if (! empty($embed))
@include('public.booking._scheduler-script')
@endif
@endsection

