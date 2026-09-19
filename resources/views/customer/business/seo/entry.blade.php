{{--
    Contract 18, Sub-slice 18A — the bare /seo Business chooser.
    Zero accessible shows an empty state, exactly one redirects through (so
    this view is never rendered for that case), several list them. Never
    guesses a Business.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">SEO</h4>
        </div>
    </div>

    @if(count($accessible) === 0)
        <x-card :padded="true">
            <x-empty-state icon="search" title="No Business available yet"
                           description="SEO is organized by Business. You don't have access to a Business with SEO available yet." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-2">Choose a Business to continue</p>
            <div class="list-group">
                @foreach($accessible as [$workspace, $business])
                    <a href="{{ route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>
                            <strong>{{ $business->name }}</strong>
                            <span class="text-caption d-block">{{ $workspace->name }}</span>
                        </span>
                        <x-ds-icon name="chevron-right" size="18" />
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
