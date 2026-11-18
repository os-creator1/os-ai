@extends('marketing.layout')

{{--
    White Label "Consultation landing page" template. Every word comes from the site's editable `consultation.*` copy;
    the right-hand card is the real public scheduler (same engine, endpoints and script as /book/{uuid}) for the
    Agency's own booking type. No lead form comes before the calendar: contact details are asked only after a time
    is chosen, because the booking itself needs them.
--}}
@push('head')
@include('public.booking._scheduler-styles')
<link rel="stylesheet" href="{{ $mg->asset('css/book-demo.css') }}">
@endpush

@section('content')
@include('marketing.partials.consultation-body', ['tag' => 'h1'])
@endsection

@section('footer')
@include('marketing.partials.footer-compact')
@if ($embed)
@include('public.booking._scheduler-script')
@endif
@endsection
