{{--
    Full-page frame shared by the three Calendar tabs (Calendar view, Booking
    types, Staff availability). The Business OS shell, the Calendar title and
    the tab strip live OUTSIDE #calendar-content and are never replaced;
    #calendar-content is the only region the tab router swaps.

    A tab view `@extends` this frame for an ordinary request and
    `_fragment` when the router asks for just its section (?fragment=1), and
    supplies two sections either way:
      calendar-active   'schedule' | 'booking-types' | 'availability'
      calendar-section  the tab's own markup
--}}
@extends('layouts/contentLayoutMaster')

@section('page-style')
    @include('customer.business.calendar._styles')
@endsection

@section('content')
    @include('customer.business.calendar._module-nav', ['active' => trim($__env->yieldContent('calendar-active'))])

    <div id="calendar-content"
         data-calendar-section="{{ trim($__env->yieldContent('calendar-active')) }}"
         data-calendar-title="{{ trim($__env->yieldContent('title')) }}"
         data-fc-css="{{ asset('vendors/css/calendars/fullcalendar.min.css') }}"
         data-fc-js="{{ asset('vendors/js/calendar/fullcalendar.min.js') }}">
        @yield('calendar-section')
    </div>

    <div id="calendar-live-status" class="calendar-sr-only" role="status" aria-live="polite"></div>
@endsection

@section('page-script')
    @include('customer.business.calendar._scripts')
@endsection
