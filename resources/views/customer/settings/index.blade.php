{{--
    Settings hub — the one place the sidebar's Settings entry leads.

    A page of cards, one per group of settings, each module linking to its own
    existing screen. The modules are built by CustomerMenuBuilder::
    settingsSections() with the same gates as every menu entry, so a module the
    actor cannot use is simply not here. Nothing on this page is a second menu:
    it holds no controls of its own except a Core or Growth Business's feature
    switches, which have no other home.

    PRESENTATION ONLY. Which sections and modules appear, where they lead and
    who may see them is decided elsewhere and is untouched here; this view only
    lays them out (two columns on wide screens, one on narrow ones). All styling
    is scoped to .mg-settings so no other page is affected.

    @param string $heading
    @param string $subheading
    @param array<int, array{key: string, title: string, items: array<int, \App\Library\Navigation\MenuItem>}> $sections
    @param array|null $featureSwitches  see customer.workspaces.partials.business-feature-switches
--}}
@extends('layouts/contentLayoutMaster')

@section('title', $heading)

@section('page-style')
    <style>
        .mg-settings{max-width:64rem}
        .mg-settings-title{font-size:1.6rem;font-weight:700;margin:0 0 .15rem}
        .mg-settings-sub{margin:0 0 1.5rem;color:var(--color-text-muted,#6f6d67)}
        .mg-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.25rem;align-items:start}
        .mg-settings-card{background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.9rem;overflow:hidden;min-width:0}
        .mg-settings-card-title{margin:0;padding:.85rem 1.25rem;font-size:.7rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted,#6f6d67);border-bottom:1px solid var(--color-border-neutral,#e5e1da)}
        .mg-settings-list{list-style:none;margin:0;padding:0}
        .mg-settings-list > li + li{border-top:1px solid var(--color-border-neutral,#e5e1da)}
        .mg-settings-row{display:flex;align-items:center;gap:.9rem;padding:.9rem 1.25rem;color:inherit;text-decoration:none;min-width:0}
        .mg-settings-row:hover{background:var(--color-surface-secondary,#fbfaf7)}
        .mg-settings-row:focus-visible{outline:2px solid var(--color-primary,#b5524c);outline-offset:-2px}
        .mg-settings-icon{flex:none;display:inline-flex;align-items:center;justify-content:center;width:2.1rem;height:2.1rem;border-radius:.55rem;background:#efece7;color:#6f6d67}
        .mg-settings-card[data-tone="business-setup"] .mg-settings-icon{background:var(--color-primary-soft-bg,#f4e6e4);color:var(--color-primary,#b5524c)}
        .mg-settings-card[data-tone="communication"] .mg-settings-icon{background:#e6eefb;color:#2b5fb8}
        .mg-settings-card[data-tone="billing-team"] .mg-settings-icon{background:#e3f1e8;color:#2f7a52}
        .mg-settings-text{flex:1 1 auto;min-width:0}
        .mg-settings .mg-settings-row,.mg-settings .mg-settings-row:hover{color:var(--color-text-primary,#262522)}
        .mg-settings-name{color:var(--color-text-primary,#262522);display:block;font-weight:600;line-height:1.3;overflow-wrap:anywhere}
        .mg-settings-desc{display:block;font-size:.8rem;color:var(--color-text-muted,#6f6d67);line-height:1.35;overflow-wrap:anywhere}
        .mg-settings-chev{flex:none;color:#a9a59d}
        .mg-settings-features{grid-column:1 / -1}
        .mg-settings-features-head{display:flex;justify-content:space-between;align-items:baseline;gap:.75rem 1.5rem;flex-wrap:wrap;padding:.9rem 1.25rem;border-bottom:1px solid var(--color-border-neutral,#e5e1da)}
        .mg-settings-features-head h2{margin:0;font-size:1rem;font-weight:700}
        .mg-settings-features-head p{margin:0;font-size:.8rem;color:var(--color-text-muted,#6f6d67)}
        /* the shared switches list, laid out as rows inside this card only */
        .mg-settings-features #business-feature-settings > p.text-caption{display:none}
        .mg-settings-features #business-feature-settings .list-group{margin:0 !important;border-radius:0}
        .mg-settings-features #business-feature-settings .list-group-item{border-width:0 0 1px;padding:.9rem 1.25rem;align-items:center !important}
        .mg-settings-features #business-feature-settings .list-group-item:last-child{border-bottom:0}
        @media (max-width:767.98px){
            .mg-settings-grid{grid-template-columns:minmax(0,1fr)}
            .mg-settings-title{font-size:1.35rem}
            .mg-settings-row{padding:.8rem 1rem}
            .mg-settings-card-title,.mg-settings-features-head{padding-left:1rem;padding-right:1rem}
            .mg-settings-features #business-feature-settings .list-group-item{padding-left:1rem;padding-right:1rem}
        }
    </style>
@endsection

@section('content')
    <section data-role="settings-hub" class="mg-settings">
        <h1 class="mg-settings-title">{{ $heading }}</h1>
        <p class="mg-settings-sub">{{ $heading === 'Settings' ? 'Manage ' . $subheading . ' and your own account.' : $subheading }}</p>

        <div class="mg-settings-grid">
            @foreach ($sections as $section)
                <div class="mg-settings-card" data-tone="{{ $section['key'] }}" data-role="settings-section" data-section="{{ $section['key'] }}">
                    <h2 class="mg-settings-card-title">{{ $section['title'] }}</h2>
                    <ul class="mg-settings-list">
                        @foreach ($section['items'] as $module)
                            @php
                                $translationKey = 'locale.menu.' . $module->label;
                                $label = \Illuminate\Support\Facades\Lang::has($translationKey) ? __($translationKey) : $module->label;
                                $description = \App\Library\Navigation\CustomerMenuBuilder::SETTINGS_DESCRIPTIONS[$module->key] ?? null;
                            @endphp
                            <li data-role="settings-module" data-module="{{ $module->key }}">
                                <a href="{{ $module->url }}" class="mg-settings-row">
                                    <span class="mg-settings-icon" aria-hidden="true"><x-ds-icon :name="$module->icon" size="17" aria-hidden="true" /></span>
                                    <span class="mg-settings-text">
                                        <span class="mg-settings-name">{{ $label }}</span>
                                        @if ($description !== null)
                                            <span class="mg-settings-desc">{{ $description }}</span>
                                        @endif
                                    </span>
                                    <x-ds-icon name="chevron-right" size="16" class="mg-settings-chev" aria-hidden="true" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            {{-- The person's own preferences, not a Business setting: their profile and the
                 application language (users.locale). It links to the one existing Profile
                 form rather than repeating it here, and is on every hub because it belongs
                 to the signed-in user, whichever account or Business they are looking at. --}}
            <div class="mg-settings-card" data-role="settings-account-link" data-tone="your-account">
                <h2 class="mg-settings-card-title">Your account</h2>
                <ul class="mg-settings-list">
                    <li>
                        <a href="{{ route('user.account') }}" class="mg-settings-row" data-role="settings-profile-language">
                            <span class="mg-settings-icon" aria-hidden="true"><x-ds-icon name="user" size="17" aria-hidden="true" /></span>
                            <span class="mg-settings-text">
                                <span class="mg-settings-name">Profile &amp; language</span>
                                <span class="mg-settings-desc">Your name, email, password and the language the app is shown in.</span>
                            </span>
                            <x-ds-icon name="chevron-right" size="16" class="mg-settings-chev" aria-hidden="true" />
                        </a>
                    </li>
                </ul>
            </div>

            @if ($featureSwitches !== null)
                <div class="mg-settings-card mg-settings-features" data-role="settings-section" data-section="features">
                    <div class="mg-settings-features-head">
                        <h2>Features</h2>
                        <p>Turn features on or off for {{ ($featureSwitches['showBusinessNames'] ?? true) ? 'each Business' : 'this Business' }}. Changes are saved straight away.</p>
                    </div>
                    @include('customer.workspaces.partials.business-feature-switches', [
                        'workspaceUid' => $featureSwitches['workspaceUid'],
                        'businesses' => $featureSwitches['businesses'],
                        'featureSettings' => $featureSwitches['settings'],
                        'showHeading' => false,
                        'showBusinessNames' => $featureSwitches['showBusinessNames'],
                    ])
                </div>
            @endif
        </div>
    </section>
@endsection
