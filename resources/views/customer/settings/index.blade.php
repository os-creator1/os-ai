{{--
    Settings hub — the one place the sidebar's Settings entry leads.

    A page of cards, one per group of settings, each module linking to its own
    existing screen. The modules are built by CustomerMenuBuilder::
    settingsSections() with the same gates as every menu entry, so a module the
    actor cannot use is simply not here. Nothing on this page is a second menu:
    it holds no controls of its own except a Core or Growth Business's feature
    switches, which have no other home.

    @param string $heading
    @param string $subheading
    @param array<int, array{key: string, title: string, items: array<int, \App\Library\Navigation\MenuItem>}> $sections
    @param array|null $featureSwitches  see customer.workspaces.partials.business-feature-switches
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $heading)

@section('content')
    <section data-role="settings-hub">
        <div class="row mb-2">
            <div class="col-12">
                <h4 class="mb-0">{{ $heading }}</h4>
                <p class="text-caption mb-0">{{ $subheading }}</p>
            </div>
        </div>

        <div class="row">
            @foreach ($sections as $section)
                <div class="col-12 col-lg-6" data-role="settings-section" data-section="{{ $section['key'] }}">
                    <x-card :title="$section['title']">
                        <ul class="list-unstyled mb-0">
                            @foreach ($section['items'] as $module)
                                @php
                                    $translationKey = 'locale.menu.' . $module->label;
                                    $label = \Illuminate\Support\Facades\Lang::has($translationKey) ? __($translationKey) : $module->label;
                                    $description = \App\Library\Navigation\CustomerMenuBuilder::SETTINGS_DESCRIPTIONS[$module->key] ?? null;
                                @endphp
                                <li class="{{ $loop->last ? '' : 'mb-1' }}" data-role="settings-module" data-module="{{ $module->key }}">
                                    <a href="{{ $module->url }}" class="d-flex align-items-start gap-1 text-body">
                                        <x-ds-icon :name="$module->icon" class="mt-25 flex-shrink-0" aria-hidden="true" />
                                        <span>
                                            <span class="d-block fw-bolder">{{ $label }}</span>
                                            @if ($description !== null)
                                                <span class="d-block text-caption">{{ $description }}</span>
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                </div>
            @endforeach

            @if ($featureSwitches !== null)
                <div class="col-12" data-role="settings-section" data-section="features">
                    <x-card title="Features">
                        @include('customer.workspaces.partials.business-feature-switches', [
                            'workspaceUid' => $featureSwitches['workspaceUid'],
                            'businesses' => $featureSwitches['businesses'],
                            'featureSettings' => $featureSwitches['settings'],
                            'showHeading' => false,
                            'showBusinessNames' => $featureSwitches['showBusinessNames'],
                        ])
                    </x-card>
                </div>
            @endif
        </div>
    </section>
@endsection
