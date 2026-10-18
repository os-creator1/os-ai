@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Home'))

@section('content')
    <section id="admin-staff-home">
        <div class="row">
            <div class="col-12">
                <x-card title="Welcome, {{ auth()->user()->displayName() }}">
                    <p class="text-muted mb-0">
                        These are the sections of the platform your role can open. Access is decided per section; anything not listed here is not part of your role.
                    </p>
                </x-card>
            </div>

            <div class="col-12" data-testid="staff-home-sections">
                <x-card title="Your sections">
                    @if ($sections->isEmpty())
                        <p class="text-muted mb-0">Your role has no platform sections yet. Ask a Platform Owner to grant access.</p>
                    @else
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($sections as $section)
                                @if (!empty($section->submenu))
                                    @foreach ($section->submenu as $child)
                                        <x-button variant="secondary" size="sm" :href="$child->url">{{ $child->name }}</x-button>
                                    @endforeach
                                @else
                                    <x-button variant="secondary" size="sm" :href="$section->url">{{ $section->name }}</x-button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
