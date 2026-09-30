@extends('layouts/contentLayoutMaster')

@section('title', 'Website Studio')

@section('content')
    <div class="row mb-2 align-items-center">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $website->name }}</h4>
            <x-badge :variant="$website->status->value === 'published' ? 'success' : ($website->status->value === 'archived' ? 'neutral' : 'warning')">
                {{ ucfirst($website->status->value) }}
            </x-badge>
        </div>
    </div>

    <ul class="nav nav-pills mb-3">
        @foreach (['website' => 'Website', 'packages' => 'Packages', 'forms' => 'Forms', 'questionnaires' => 'Questionnaires'] as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link @if($tab === $tabKey) active @endif" href="{{ route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, $tabKey]) }}">{{ $tabLabel }}</a>
            </li>
        @endforeach
    </ul>

    <x-flash-alert class="mb-3" />

    @switch($tab)
        @case('packages')
            @include('customer.business.website.studio.packages')
            @break

        @case('forms')
            @include('customer.business.website.studio.forms')
            @break

        @case('questionnaires')
            @include('customer.business.website.studio.questionnaires')
            @break

        @default
            @include('customer.business.website.studio.website')
    @endswitch
@endsection
