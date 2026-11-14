{{--
    Contract 18, Sub-slice 18E — Citations, in the approved MotionGrove Citations design (cg- classes).

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
@section('page-heading', 'own')

@section('page-style')
    @include('customer.business.seo._citations_styles')
    @include('customer.business.seo._citations_drawer_styles')
@endsection

@php
    use App\Library\Seo\SeoCitationLocationSection;
    use App\Library\Seo\SeoCitationNextAction;
    use App\Library\Seo\SeoCitationPresentation;
    use App\Library\Seo\SeoLinkSafety;

    $rel = SeoLinkSafety::EXTERNAL_REL;
    $gbpUrl = route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
    $settingsUrl = route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]);
    $citationsUrl = route('customer.workspaces.businesses.seo.citations.index', [$workspaceUid, $businessUid]);
@endphp

@section('content')
<div class="cg-page" data-role="citations-page">
    @include('customer.business.seo._citations_icons')
    <div class="cg-head">
        <div>
            <h1>Citations</h1>
            <p data-role="citations-subtitle">Keep your business details the same everywhere customers look.</p>
        </div>
        @if($section !== null && count($sections) > 1)
            <div class="cg-loc" data-role="location-switcher">
                <button type="button" id="cgLocBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="cgLocMenu">
                    <span class="cg-pin"><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-pin"/></svg></span>
                    <span class="cg-t"><small>Location</small><span>{{ $section->location->name }}</span></span>
                    <svg class="cg-ic" viewBox="0 0 24 24" style="color:#8A817C"><use href="#cg-i-down"/></svg>
                </button>
                <div class="cg-menu-pop" id="cgLocMenu" role="menu">
                    @foreach($sections as $option)
                        <a class="cg-loc-menu-item" role="menuitemradio" aria-checked="{{ $option->location->uid === $section->location->uid ? 'true' : 'false' }}" data-location-option="{{ $option->location->uid }}" href="{{ $citationsUrl }}?location={{ $option->location->uid }}">
                            <span class="cg-tile" style="width:32px;height:32px;border-radius:9px;background:var(--brand-tint);color:var(--brand-dk)"><svg class="cg-ic" viewBox="0 0 24 24" style="width:15px;height:15px"><use href="#cg-i-pin"/></svg></span>
                            <span><b>{{ $option->location->name }}</b><small>{{ $option->location->is_primary ? 'Primary location' : 'Location' }}</small></span>
                            <svg class="cg-ic cg-chk" viewBox="0 0 24 24"><use href="#cg-i-check"/></svg>
                        </a>
                    @endforeach
                </div>
            </div>
        @elseif($section !== null)
            <div class="cg-loc"><span class="cg-pill cg-p-grey" data-role="location-chip" style="height:36px;padding:0 14px;font-size:13px"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-pin"/></svg>{{ $section->location->name }}</span></div>
        @endif
        @if($section !== null && $section->googleState() === SeoCitationLocationSection::GOOGLE_NOT_LINKED)
            <a class="cg-btn cg-ghost cg-sm" href="{{ $gbpUrl }}" data-role="header-connect-google">Connect Google Business Profile</a>
        @endif
    </div>

    <x-flash-alert />

    @if($section === null)
        <section class="cg-card cg-empty" data-section="citation-empty" data-role="citation-empty" style="padding:44px 24px">
            <svg class="cg-ic" viewBox="0 0 24 24" style="width:26px;height:26px;color:var(--brand)"><use href="#cg-i-pin"/></svg>
            <div style="margin-top:10px;font-weight:700;color:var(--ink);font-size:17px">Track how your business information appears across important directories.</div>
            <p style="max-width:520px;margin:6px auto 0">There are no locations you can track citations for yet. Once a location is available, you can record what each directory shows and compare it with your business profile.</p>
            @can('access_backend')
                <p style="margin-top:14px"><a class="cg-btn cg-ghost cg-sm" href="{{ $settingsUrl }}">Open business settings</a></p>
            @endcan
        </section>
    @else
        @php
            $location = $section->location;
            $summary = $section->summary();
            $googleState = $section->googleState();
            $nicheName = $section->nicheLabel;
            $groups = $section->groups();
            $canAdd = $canManage && $section->writable;
            $best = SeoCitationNextAction::for($section, (bool) $canManage);
            $stats = SeoCitationPresentation::counts($section);
            $counts = $stats['counts'];
            $opps = SeoCitationPresentation::opportunities($section, $best['row']);
            $nicheCount = collect($section->rows)->filter(fn ($r) => $r->nicheLabel !== null && ! $r->isHistoryOnly() && ! $r->isNotApplicable())->count();
            $napPct = $summary['napCompared'] > 0 ? (int) round($summary['napMatched'] / $summary['napCompared'] * 100) : 0;
            $groupTitles = [
                SeoCitationLocationSection::GROUP_ESSENTIAL => ['Essential listings', 'The places most worth getting right first.'],
                SeoCitationLocationSection::GROUP_SETUP => ['Needs setup', 'No details recorded for these yet.'],
                SeoCitationLocationSection::GROUP_RECOMMENDED => ['Recommended' . ($nicheName ? ' · incl. ' . $nicheName . ' picks' : ''), 'Worth setting up once the essentials are done.'],
                SeoCitationLocationSection::GROUP_OPTIONAL => ['Optional', 'Skip any that do not fit.'],
                SeoCitationLocationSection::GROUP_CUSTOM => ['Your custom directories', 'Added by you.'],
            ];
            $inventoryTotal = $stats['total'] + $summary['notApplicable'];
        @endphp

        <div class="cg-contents" data-section="citation-location" data-location="{{ $location->uid }}">
            @include('customer.business.seo._citation_next_best')

            {{-- Directory progress: counts of real rows for this location. No score, no visibility %. --}}
            <section class="cg-card cg-prog-card" id="cgProgress" data-role="citation-summary" aria-label="Directory progress">
                <div class="cg-big"><b data-stat="listed">{{ $counts['listed'] }} <span style="font-size:18px;font-weight:600;color:var(--faint);letter-spacing:0">of {{ $stats['total'] }}</span></b>
                    <span>directories listed</span></div>
                <div class="cg-bar">
                    <div class="cg-track" role="img" aria-label="{{ collect(SeoCitationPresentation::BUCKETS)->map(fn ($b, $k) => $counts[$k] . ' ' . $b[0])->implode(', ') }}">
                        @foreach(SeoCitationPresentation::BUCKETS as $bucketKey => $bucketDef)
                            @if($counts[$bucketKey] > 0)<span style="flex:{{ $counts[$bucketKey] }};background:{{ $bucketDef[2] }}"></span>@endif
                        @endforeach
                    </div>
                    <div class="cg-legend">
                        @foreach(SeoCitationPresentation::BUCKETS as $bucketKey => $bucketDef)
                            @if($counts[$bucketKey] > 0)<button type="button" data-jump="{{ $bucketKey }}" data-count="{{ $counts[$bucketKey] }}"><i style="background:{{ $bucketDef[2] }}"></i><b>{{ $counts[$bucketKey] }}</b> {{ $bucketDef[0] }}</button>@endif
                        @endforeach
                    </div>
                </div>
                <div class="cg-how"><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-info"/></svg><span>“Listed” means connected (Google) or recorded by you. Not checked isn't the same as missing — it just means nobody has looked yet.</span></div>
                <div class="cg-stat-nap" data-stat="nap-consistency"><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-check"/></svg>@if($summary['napCompared'] > 0)<span>{{ $summary['napMatched'] }} of {{ $summary['napCompared'] }} match — recorded details compared with your business profile.</span>@else<span>Not checked yet — nothing recorded to compare with your business profile.</span>@endif</div>
            </section>

            <section class="cg-flex-col" aria-labelledby="cgOppH" data-role="worth-doing-next">
                <div class="cg-sec-h">
                    <div><h2 id="cgOppH">Worth doing next</h2><p>{{ $nicheName ? 'Picked for ' . strtolower($nicheName) . ' businesses in ' . $location->name . '.' : 'Picked for ' . $location->name . '.' }}</p></div>
                </div>
                <div class="cg-opps" id="cgOpps">
                    @forelse($opps as $oRow)
                        @php
                            [$oLetter, $oBg, $oFg] = SeoCitationPresentation::tile($oRow->directory->key, $oRow->directory->name);
                            $oAction = SeoCitationNextAction::directoryAction($oRow, (bool) $canManage);
                        @endphp
                        <article class="cg-card cg-opp" data-opportunity="{{ $oRow->directory->key }}">
                            <div class="cg-top-r">
                                <span class="cg-tile" style="background:{{ $oBg }};color:{{ $oFg }}" aria-hidden="true">{{ $oLetter }}</span>
                                <div style="min-width:0"><b class="cg-n">{{ $oRow->directory->name }}</b>
                                    <div class="cg-tags" style="margin-top:4px">
                                        @if($oRow->nicheLabel !== null)<span class="cg-pill cg-p-brand">Popular for {{ strtolower($oRow->nicheLabel) }}</span>@else<span class="cg-pill cg-p-grey">{{ $oRow->isCustom() ? 'Custom' : $oRow->importance()->label() }}</span>@endif
                                    </div>
                                </div>
                            </div>
                            <p>{{ SeoCitationPresentation::why($oRow) }}</p>
                            @if($oAction['kind'] === 'drawer' || $oAction['kind'] === 'setup' || $oAction['kind'] === 'listing')
                                @if($oAction['url'] !== null)
                                    <a class="cg-btn cg-ghost cg-sm" href="{{ $oAction['url'] }}" target="_blank" rel="{{ $rel }}">{{ $oAction['label'] }}<svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-ext"/></svg></a>
                                @else
                                    <button type="button" class="cg-btn cg-ghost cg-sm" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-{{ $oRow->directory->key }}"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-edit"/></svg>{{ $oAction['label'] }}</button>
                                @endif
                            @else
                                <span class="cg-hint">{{ $oAction['hint'] }}</span>
                            @endif
                        </article>
                    @empty
                        <div class="cg-card cg-empty" style="grid-column:1/-1" data-role="action-empty">
                            <svg class="cg-ic" viewBox="0 0 24 24" style="width:22px;height:22px;color:var(--green)"><use href="#cg-i-party"/></svg>
                            <div style="margin-top:8px;font-weight:600;color:var(--ink)">No more suggestions right now</div>
                            Nothing else needs your attention. You're covering the directories that matter most for your business.
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- The full inventory: secondary to the next move above. --}}
            <details class="cg-card cg-inv" id="cgInv" data-role="directory-inventory">
                <summary>
                    <div><h2>All directories</h2><p>{{ $inventoryTotal }} for {{ $location->name }} · search, filter and record what you find</p></div>
                    <svg class="cg-ic cg-chev" viewBox="0 0 24 24" style="width:20px;height:20px"><use href="#cg-i-down"/></svg>
                </summary>
                <div class="cg-inv-tools" data-role="citation-filters">
                    <div class="cg-chips" role="group" aria-label="Filter directories">
                        <button type="button" class="cg-chip" data-filter="all" aria-pressed="true">All<span>{{ $stats['total'] }}</span></button>
                        @foreach(SeoCitationPresentation::BUCKETS as $bucketKey => $bucketDef)
                            @if($counts[$bucketKey] > 0 && $bucketKey !== 'listed' || $bucketKey === 'listed' && $counts['listed'] > 0)
                                <button type="button" class="cg-chip" data-filter="{{ $bucketKey }}" aria-pressed="false">{{ $bucketDef[0] }}<span>{{ $counts[$bucketKey] }}</span></button>
                            @endif
                        @endforeach
                        @if($nicheCount > 0)<button type="button" class="cg-chip" data-filter="niche" aria-pressed="false">{{ $nicheName }} picks<span>{{ $nicheCount }}</span></button>@endif
                        @if(collect($section->rows)->contains(fn ($r) => $r->isCustom()))<button type="button" class="cg-chip" data-filter="custom" aria-pressed="false">Custom<span>{{ collect($section->rows)->filter(fn ($r) => $r->isCustom())->count() }}</span></button>@endif
                    </div>
                    <label class="cg-isearch"><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-search"/></svg><input type="search" placeholder="Search directories" aria-label="Search directories" data-role="citation-search"></label>
                    @if($canAdd)
                        <button type="button" class="cg-btn cg-ghost cg-sm" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-new-custom" data-role="add-custom"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-edit"/></svg>Add custom directory</button>
                    @endif
                </div>
                <div class="cg-rows" data-role="citation-list">
                    @foreach($groups as $groupKey => $groupRows)
                        <div class="cg-group" data-group="{{ $groupKey }}">
                            <div class="cg-grp">{{ $groupTitles[$groupKey][0] }}<span style="text-transform:none;letter-spacing:0;font-weight:500;margin-left:10px;color:var(--faint)">{{ $groupTitles[$groupKey][1] }}</span></div>
                            @if($groupKey === SeoCitationLocationSection::GROUP_ESSENTIAL && $googleState !== null)
                                @include('customer.business.seo._citation_google_row')
                            @endif
                            @foreach($groupRows as $row)
                                @include('customer.business.seo._citation_row', ['row' => $row])
                            @endforeach
                        </div>
                    @endforeach
                    @if(count($groups) === 0)
                        <div class="cg-filter-empty" data-role="citation-no-directories">No directories are tracked yet.</div>
                    @endif
                    <div class="cg-filter-empty" style="display:none" data-role="citation-filter-empty">No directories match this filter.</div>
                </div>
            </details>

            <div class="cg-foot" data-role="citations-note">
                <svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-info"/></svg>
                <span><b style="color:var(--ink-2)">How tracking works.</b> Directories other than Google are <strong>manually tracked</strong> by you — MotionGrove saves what you record and compares it with your business profile, but does not read from the directories.
                    @if($googleState !== null) Google Business Profile is the exception: it is checked automatically through your connection.@endif
                    Opening a directory link doesn't create or verify a listing.
                    @unless($section->writable)<span style="display:block;margin-top:4px" data-role="location-archived">Archived location — read only.</span>@endunless
                </span>
            </div>

            @foreach($section->rows as $row)
                @include('customer.business.seo._citation_drawer', ['row' => $row])
            @endforeach

            @if($canAdd)
                @include('customer.business.seo._citation_custom_drawer')
            @endif
        </div>
    @endif
    <div class="cg-toast" id="cgToast" role="status" aria-live="polite"><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-check"/></svg><span></span></div>
</div>
@endsection

@section('page-script')
    @include('customer.business.seo._citations_scripts')
@endsection
