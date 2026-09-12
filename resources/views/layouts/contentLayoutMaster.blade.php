@isset($pageConfigs)
{!! \App\Helpers\Helper::updatePageConfig($pageConfigs) !!}
@endisset
        <!DOCTYPE html>
@php
    $configData = \App\Helpers\Helper::applClasses();
@endphp

<html class="loading {{ ($configData['theme'] === 'light') ? '' : $configData['layoutTheme']}}"
      lang="@if(Session::has('locale')){{Session::get('locale')}}@else{{config('app.locale')}}@endif"
      data-textdirection="{{ config('app.locale_direction') === 'rtl' ? 'rtl' : 'ltr' }}"
      @if($configData['theme'] === 'dark') data-layout="dark-layout" @endif>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="keywords" content="{{config('app.keyword')}}" />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        Customer Experience Slice 2 (brief §6): the document title names the
        page and, inside the customer shell, the current Business or
        account (the Slice 1B context stored on the request by
        App\Http\Middleware\ResolveCustomerContext) before the platform
        title, so browser tabs and history stay understandable.
    --}}
    @php
        $shellContext = request()->attributes->get('customerContext');
        $shellContextLabel = $shellContext instanceof \App\Library\Navigation\CustomerContext && ($shellContext->isBusinessFrame() || $shellContext->frameWorkspace() !== null)
            ? trim((string) $shellContext->contextName())
            : '';
    @endphp
    <title>@yield('title')@if($shellContextLabel !== '') · {{ $shellContextLabel }}@endif - {{config('app.title')}}</title>
    <x-branding-favicon />
    {{-- Design System Contract, Milestone 1, §9 item 36 — Geist Sans is
    self-hosted (resources/scss/base/tokens/_typography.scss), compiled
    into core.css below; the Montserrat Google Fonts link is removed. --}}

    {{-- Include core + vendor Styles --}}
    @include('panels/styles')

</head>
<!-- END: Head-->

<!-- BEGIN: Body-->
@isset($configData["mainLayoutType"])
    @extends((( $configData["mainLayoutType"] === 'horizontal') ? 'layouts.horizontalLayoutMaster' : 'layouts.verticalLayoutMaster' ))
@endisset
