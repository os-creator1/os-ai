{{--
    Contract 18, Sub-slice 18E — Citations (dashboard, V1 complete).

    The Business's OWN record of where it is listed, per Location, beside the
    business profile those listings should match. One unified list: platform
    CORE directories, the Business niche's RECOMMENDED directories, and the
    Business's own CUSTOM directories, grouped by importance.

    Nothing on this page is observed from a directory: the platform never
    fetches a listing URL and never contacts a directory, and no status is ever
    changed by a NAP difference — the comparison and the "Needs attention" list
    are display only. The page therefore says "manually tracked" and never
    "synced", "live" or "monitored". The one automatic row is Google, whose
    state and facts come from the existing Google Business Profile read model
    and are compared at read time, never stored.

    One Location is shown at a time (?location=<uid>, chosen only among the
    Locations the manager already filtered to the actor's access), so values
    from different Locations are never combined.

    Every value is rendered with escaped Blade output only; raw, unescaped
    output is forbidden in this view. An external link is rendered ONLY from a
    value that has passed SeoLinkSafety (https, no userinfo, valid host) and
    always carries rel="noopener noreferrer nofollow". A street address is
    rendered only where the Location is permitted to expose one.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'SEO — Citations')

@section('page-style')
    @include('customer.business.seo._citations_styles')
@endsection

@php
    use App\Enums\Seo\SeoNapFieldResult;
    use App\Library\Seo\SeoCitationLocationSection;
    use App\Library\Seo\SeoLinkSafety;

    $rel = SeoLinkSafety::EXTERNAL_REL;
    $gbpUrl = route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
    $settingsUrl = route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]);
@endphp

@section('content')
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-2">
        <div>
            <h4 class="mb-25">Citations</h4>
            <p class="text-caption mb-0" data-role="citations-subtitle">Keep your business information accurate and consistent across the places customers search.</p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-1">
            @if($section !== null && count($sections) > 1)
                <form method="GET" action="{{ route('customer.workspaces.businesses.seo.citations.index', [$workspaceUid, $businessUid]) }}" class="d-flex align-items-center gap-50" data-role="location-switcher">
                    <label class="text-caption mb-0" for="citation-location">Location</label>
                    <select id="citation-location" name="location" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach($sections as $option)
                            <option value="{{ $option->location->uid }}" @selected($option->location->uid === $section->location->uid)>{{ $option->location->name }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Show</button></noscript>
                </form>
            @elseif($section !== null)
                <x-badge variant="neutral" class="cz-badge" data-role="location-chip"><x-ds-icon name="map-pin" size="12" />{{ $section->location->name }}</x-badge>
            @endif
            @if($section !== null && $section->googleState() === SeoCitationLocationSection::GOOGLE_NOT_LINKED)
                <x-button :href="$gbpUrl" variant="primary" size="sm" icon="plug" data-role="header-connect-google">Connect Google Business Profile</x-button>
            @endif
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($section === null)
        <x-card :padded="true" data-section="citation-empty">
            <x-empty-state icon="map-pin" title="Track how your business information appears across important directories." data-role="citation-empty">
                <x-slot:description>There are no locations you can track citations for yet. Once a location is available, you can record what each directory shows and compare it with your business profile.</x-slot:description>
                @can('access_backend')
                    <x-slot:action><x-button :href="$settingsUrl" variant="outline" size="sm" icon="building-2">Open business settings</x-button></x-slot:action>
                @endcan
            </x-empty-state>
        </x-card>
    @else
        @php
            $location = $section->location;
            $summary = $section->summary();
            $googleState = $section->googleState();
            $googleNap = $section->googleNap;
            $napFraction = $summary['napCompared'] > 0 ? $summary['napMatched'] . ' / ' . $summary['napCompared'] : null;
            $canonical = $section->canonical;
            $websiteUrl = $section->canonicalWebsite;
            $safeWebsite = SeoLinkSafety::safeHttpsUrl($websiteUrl);
            $next = $section->attentionItems();
            $groups = $section->groups();
            $canAdd = $canManage && $section->writable;
            $nicheName = $section->nicheLabel;
            $groupTitles = [
                SeoCitationLocationSection::GROUP_ESSENTIAL => ['Essential listings', 'The places most worth getting right first.'],
                SeoCitationLocationSection::GROUP_RECOMMENDED => ['Recommended' . ($nicheName ? ' · incl. ' . $nicheName . ' picks' : ''), 'Worth setting up once the essentials are done.'],
                SeoCitationLocationSection::GROUP_OPTIONAL => ['Optional', 'Useful in some cases; skip any that do not fit.'],
                SeoCitationLocationSection::GROUP_CUSTOM => ['Your custom directories', 'Added by you. Manually tracked.'],
            ];
        @endphp

        <div data-section="citation-location" data-location="{{ $location->uid }}">
            <div class="cz-note mb-2" data-role="citations-note">
                <x-ds-icon name="clipboard-list" size="16" />
                <span>
                    <strong>Manually tracked.</strong> You record what each directory shows and when you last checked; Business OS compares it with your business profile below but does not read from the directories, and a difference never changes a status.
                    @if($googleState !== null) Google's status{{ $section->googleCheckedAutomatically() ? ' and details are checked automatically from' : ' comes from' }} your Google Business Profile connection.@endif
                    Consistent business information helps customers and platforms identify your business correctly.
                    @unless($section->writable)
                        <span class="d-block mt-25" data-role="location-archived">Archived location — read only.</span>
                    @endunless
                </span>
            </div>

            {{-- Summary: counts of stored rows only. No score, no visibility %. --}}
            <div class="cz-stats mb-1" data-role="citation-summary">
                <div class="cz-stat" data-stat="tracked">
                    <p class="cz-stat-label"><x-ds-icon name="list-checks" size="14" />Listings tracked</p>
                    <p class="cz-stat-value">{{ $summary['tracked'] }}</p>
                    <p class="cz-stat-sub">{{ $summary['notApplicable'] > 0 ? $summary['notApplicable'] . ' marked not applicable' : 'Directories for this location' }}</p>
                </div>
                <div class="cz-stat cz-stat--ok" data-stat="linked">
                    <p class="cz-stat-label"><x-ds-icon name="circle-check" size="14" />Completed</p>
                    <p class="cz-stat-value">{{ $summary['completed'] }}</p>
                    <p class="cz-stat-sub">Listed with details recorded, or connected</p>
                </div>
                <div class="cz-stat {{ $summary['attention'] > 0 ? 'cz-stat--warn' : '' }}" data-stat="attention">
                    <p class="cz-stat-label"><x-ds-icon name="triangle-alert" size="14" />Needs attention</p>
                    <p class="cz-stat-value">{{ $summary['attention'] }}</p>
                    <p class="cz-stat-sub">A listing that differs, was marked for correction, or is due a review</p>
                </div>
                <div class="cz-stat" data-stat="nap-consistency">
                    <p class="cz-stat-label"><x-ds-icon name="shield-check" size="14" />NAP matches</p>
                    @if($napFraction !== null)
                        <p class="cz-stat-value">{{ $napFraction }} <small>match</small></p>
                        <p class="cz-stat-sub">Recorded details vs. your profile</p>
                    @else
                        <p class="cz-stat-value cz-muted" style="font-size:1.125rem">Not checked yet</p>
                        <p class="cz-stat-sub">Record what a directory shows to compare</p>
                    @endif
                </div>
            </div>
            <div class="cz-progress mb-2" data-role="citation-progress">
                <span data-progress="essential"><strong>Essential</strong> {{ $summary['essentialDone'] }} / {{ $summary['essentialTotal'] }} completed</span>
                <span data-progress="recommended"><strong>Recommended</strong> {{ $summary['recommendedDone'] }} / {{ $summary['recommendedTotal'] }} completed</span>
                <span data-progress="needs-setup"><strong>Needs setup</strong> {{ $summary['needsSetup'] }}</span>
                <span data-progress="not-checked"><strong>Not checked</strong> {{ $summary['notChecked'] }}</span>
            </div>

            {{-- Canonical business profile: the data directories SHOULD match. --}}
            <x-card :padded="true" class="mb-2" data-section="business-profile">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                    <div>
                        <p class="text-section-heading mb-0">Business information</p>
                        <p class="text-caption mb-0">Directory listings should match this information. Edit it once in your business profile — it is not copied into Citations.</p>
                    </div>
                    @can('access_backend')
                        <x-button :href="$settingsUrl" variant="secondary" size="sm" icon="pencil" data-role="edit-business-profile">Edit business profile</x-button>
                    @endcan
                </div>
                <dl class="cz-profile" data-role="canonical-nap">
                    <div><dt><x-ds-icon name="building-2" size="12" />Name</dt><dd data-canonical="name">{{ $canonical['name'] ?? 'Not set' }}</dd></div>
                    <div>
                        <dt><x-ds-icon name="phone" size="12" />{{ $section->phoneComparable ? 'Phone' : 'Business phone' }}</dt>
                        <dd data-canonical="phone">{{ $canonical['phone'] ?? 'Not set' }}</dd>
                        @unless($section->phoneComparable)
                            <dd class="cz-muted" data-role="phone-not-compared">One number for the whole business, so it is not compared with this location's listings.</dd>
                        @endunless
                    </div>
                    <div>
                        <dt><x-ds-icon name="map-pin" size="12" />Address</dt>
                        @if($section->addressPermitted)
                            <dd data-canonical="address">{{ $canonical['address'] ?? 'Not set' }}</dd>
                        @else
                            <dd class="cz-muted" data-role="address-withheld">Not published for this location, so it is not compared.</dd>
                        @endif
                    </div>
                    <div>
                        <dt><x-ds-icon name="globe" size="12" />Website</dt>
                        <dd data-canonical="website">
                            @if($safeWebsite !== null)
                                <a href="{{ $safeWebsite }}" target="_blank" rel="{{ $rel }}">{{ $websiteUrl }}</a>
                            @else
                                {{ $websiteUrl ?: 'Not set' }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>

            {{-- "What should I do next?": the real actions, most important first; absent when none. --}}
            @if(count($next) > 0)
                <x-card :padded="true" class="mb-2" data-section="citation-actions">
                    <p class="text-section-heading mb-0">What to do next</p>
                    <ul class="cz-actions" data-role="action-center">
                        @foreach(array_slice($next, 0, 5) as $item)
                            @include('customer.business.seo._citation_next_item', ['item' => $item])
                        @endforeach
                    </ul>
                    @if(count($next) > 5)
                        <details class="cz-more" data-role="action-more">
                            <summary>Show {{ count($next) - 5 }} more</summary>
                            <ul class="cz-actions">
                                @foreach(array_slice($next, 5) as $item)
                                    @include('customer.business.seo._citation_next_item', ['item' => $item])
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </x-card>
            @endif

            {{-- Directory listings --}}
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-1 mb-1">
                <div>
                    <p class="text-section-heading mb-0">Directory listings</p>
                    <p class="text-caption mb-0">Directories worth checking for your business{{ $nicheName ? ', including ' . $nicheName . ' picks' : '' }}. You track them by hand: record what each one shows to compare it.</p>
                </div>
                @if($canAdd)
                    <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-50" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-new-custom" data-role="add-custom">
                        <x-ds-icon name="plus" size="14" />Add custom directory
                    </button>
                @endif
            </div>

            <div class="cz-toolbar mb-1" data-role="citation-filters">
                <div class="cz-filters" role="group" aria-label="Filter listings">
                    @foreach(['all' => 'All', 'essential' => 'Essential', 'recommended' => 'Recommended', 'setup' => 'Needs setup', 'attention' => 'Needs attention', 'notchecked' => 'Not checked', 'accurate' => 'Accurate', 'custom' => 'Custom'] as $filterKey => $filterLabel)
                        <button type="button" class="cz-filter @if($filterKey === 'all') is-active @endif" data-filter="{{ $filterKey }}">{{ $filterLabel }}</button>
                    @endforeach
                </div>
                <input type="search" class="form-control form-control-sm cz-search" placeholder="Search directories" aria-label="Search directories" data-role="citation-search">
            </div>

            <div class="cz-list mb-2" data-role="citation-list">
                <div class="cz-row cz-row--head" aria-hidden="true">
                    <div class="cz-c-dir">Directory</div><div class="cz-c-status">Status</div><div class="cz-c-name">Name</div>
                    <div class="cz-c-phone">Phone</div><div class="cz-c-addr">Address</div><div class="cz-c-checked">Last checked</div><div class="cz-c-actions"></div>
                </div>

                @foreach($groups as $groupKey => $groupRows)
                    <div class="cz-group" data-group="{{ $groupKey }}">
                        <div class="cz-group-head">
                            <span class="cz-group-title">{{ $groupTitles[$groupKey][0] }}</span>
                            <span class="cz-group-sub">{{ $groupTitles[$groupKey][1] }}</span>
                        </div>

                        @if($groupKey === SeoCitationLocationSection::GROUP_ESSENTIAL && $googleState !== null)
                            @php
                                $google = $section->google;
                                [$gLabel, $gVariant, $gIcon, $gCopy] = match ($googleState) {
                                    SeoCitationLocationSection::GOOGLE_CONNECTED => $section->googleHealthProblem() !== null
                                        ? ['Needs attention', 'warning', 'triangle-alert', $section->googleHealthProblem()]
                                        : ['Connected', 'success', 'circle-check', $section->googleCheckedAutomatically() ? 'Checked automatically from your Google connection.' : 'Linked to your Google listing.'],
                                    SeoCitationLocationSection::GOOGLE_CONNECTION_LOST => ['Needs attention', 'warning', 'triangle-alert', 'The Google connection is not active.'],
                                    default => ['Not linked', 'neutral', 'circle-dashed', 'Not linked to a Google listing yet. Nothing to do if this business has no Google listing.'],
                                };
                                // The row's flags come from the SAME section methods the summary counts use.
                                $gDifferingFields = $section->googleDifferingFields();
                                $gDiffering = array_merge(array_map('ucfirst', $gDifferingFields), $section->googleOtherDifferences());
                                $gAttention = $section->googleNeedsAttention();
                                $gAccurate = $googleNap !== null && $gDifferingFields === [] && ! $gAttention && in_array(SeoNapFieldResult::Consistent, $googleNap, true);
                                $gDataState = $gAccurate ? 'accurate' : ($gAttention ? 'needs_attention' : ($googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED ? 'not_started' : 'listed'));
                                $gAsOf = $google?->healthAsOf;
                            @endphp
                            <div class="cz-row cz-row--wide" data-role="google-row" data-google-state="{{ $googleState }}" data-importance="essential" data-custom="0" data-notchecked="{{ $googleNap === null ? '1' : '0' }}" data-attention="{{ $gAttention ? '1' : '0' }}" data-setup-needed="0" data-state="{{ $gDataState }}" data-name="google business profile">
                                <div class="cz-c-dir cz-dir">
                                    <span class="cz-dir-icon"><x-ds-icon name="map-pin" size="18" /></span>
                                    <span>
                                        <span class="cz-dir-name" style="cursor:default">Google Business Profile</span>
                                        <span class="cz-dir-kind"><x-badge variant="accent">Essential</x-badge> <span class="cz-mode" data-role="tracking-mode">{{ $section->googleCheckedAutomatically() ? 'Checked automatically' : ($googleState === SeoCitationLocationSection::GOOGLE_CONNECTED ? 'Connected' : 'Not connected') }}</span></span>
                                    </span>
                                </div>
                                <div class="cz-c-status">
                                    <x-badge :variant="$gVariant" class="cz-badge" data-role="google-state"><x-ds-icon :name="$gIcon" size="12" />{{ $gLabel }}</x-badge>
                                    <p class="cz-helper">{{ $gCopy }}</p>
                                </div>
                                <div class="cz-c-detail">
                                    @if($googleState === SeoCitationLocationSection::GOOGLE_NOT_LINKED)
                                        <span class="cz-muted">Connect to link this location to Google Business Profile.</span>
                                    @else
                                        @if($google->health !== null)
                                            <span class="d-block" data-role="google-health">{{ $google->health->label() }}@if($gAsOf !== null) <span class="text-caption" data-role="google-health-as-of">as of {{ $gAsOf->format('M j, Y') }}</span>@endif</span>
                                        @endif
                                        @if($google->healthIsStale)
                                            <span class="text-caption d-block text-warning" data-role="google-stale">Google data may be out of date. Reconnect or refresh it on the Google Business Profile page.</span>
                                        @endif
                                        @if($googleNap !== null)
                                            <span class="d-flex flex-wrap gap-1 mt-25" data-role="google-nap">
                                                @foreach(['name' => 'Name', 'phone' => 'Phone', 'website' => 'Website'] as $gField => $gLabelField)
                                                    @php $gResult = $googleNap[$gField]; @endphp
                                                    <span class="cz-field cz-field--{{ $gResult === SeoNapFieldResult::Consistent ? 'ok' : ($gResult === SeoNapFieldResult::Mismatch ? 'diff' : 'none') }}" data-google-field="{{ $gField }}" data-result="{{ $gResult->value }}">
                                                        <x-ds-icon :name="$gResult === SeoNapFieldResult::Consistent ? 'circle-check' : ($gResult === SeoNapFieldResult::Mismatch ? 'triangle-alert' : 'circle-dashed')" size="14" />
                                                        {{ $gLabelField }}: {{ match ($gResult) { SeoNapFieldResult::Consistent => 'matches', SeoNapFieldResult::Mismatch => 'differs', default => 'not compared' } }}
                                                    </span>
                                                @endforeach
                                            </span>
                                        @endif
                                        @if($googleNap !== null)
                                            <span class="text-caption d-block" data-role="google-mismatch-count">{{ count($gDiffering) }} {{ count($gDiffering) === 1 ? 'detail differs' : 'details differ' }} from Google.</span>
                                            @if($gDiffering !== [])
                                                <span class="text-caption d-block" data-role="google-differing-fields">Differs: {{ implode(', ', $gDiffering) }}.</span>
                                            @endif
                                            @if($gAsOf !== null)
                                                <span class="text-caption d-block" data-role="google-details-as-of">Google details as of {{ $gAsOf->format('M j, Y') }}.</span>
                                            @endif
                                        @endif
                                        <span class="text-caption d-block">Read-only. Managed in Google Business Profile. Street address is not compared.</span>
                                    @endif
                                </div>
                                <div class="cz-c-actions cz-row-actions">
                                    <x-button :href="$gbpUrl" variant="{{ $googleState === SeoCitationLocationSection::GOOGLE_CONNECTED ? 'secondary' : 'outline' }}" size="sm" data-role="google-action">
                                        {{ match ($googleState) { SeoCitationLocationSection::GOOGLE_CONNECTED => 'Manage', SeoCitationLocationSection::GOOGLE_CONNECTION_LOST => 'Reconnect', default => 'Connect' } }}
                                    </x-button>
                                </div>
                            </div>
                        @endif

                        @foreach($groupRows as $row)
                            @include('customer.business.seo._citation_row', ['row' => $row])
                        @endforeach
                    </div>
                @endforeach

                @if(count($groups) === 0)
                    <div class="p-2 text-center" data-role="citation-no-directories">
                        <p class="mb-0 text-caption">No directories are tracked yet.</p>
                    </div>
                @endif

                <div class="p-2 text-center d-none" data-role="citation-filter-empty">
                    <p class="mb-0 text-caption">No directories match this filter.</p>
                </div>
            </div>

            @foreach($section->rows as $row)
                @include('customer.business.seo._citation_drawer', ['row' => $row])
            @endforeach

            @if($canAdd)
                @include('customer.business.seo._citation_custom_drawer')
            @endif
        </div>
    @endif
@endsection

@section('page-script')
    @include('customer.business.seo._citations_scripts')
@endsection
