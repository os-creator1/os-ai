@extends('marketing.layout', ['faqs' => $mg->faqs('home')])

@section('content')
@include('marketing.home.hero')
@include('marketing.home.trust')
@include('marketing.home.problem')
@include('marketing.home.system')
@include('marketing.home.capabilities')
@include('marketing.home.people')
@include('marketing.home.how')
@include('marketing.home.videos')
@include('marketing.home.comparison')
@include('marketing.home.pricing')
@include('marketing.home.commitment')
@include('marketing.home.faq')
@include('marketing.partials.final-cta')
@endsection

@section('footer')
<div class="hide-m">@include('marketing.partials.footer-full')</div>
<div class="show-m">@include('marketing.partials.footer-phone')</div>
@endsection
