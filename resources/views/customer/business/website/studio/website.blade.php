{{--
    Website overview — "what website do I have, what unpublished version am I working on, and what
    should I do next?". The live site and the draft are shown as small, real previews (the same
    renderers visitors and the owner already see, framed and scaled — no screenshot service);
    the only actions are Preview, Publish and Edit (-> Settings). Everything else this screen used
    to list (pages, setup answers, history, domains, rebuild) lives under Website -> Settings.

    The previews are scaled client-side (studio/_scripts.blade.php); they are decorative frames around a
    real link, so they are not focusable and are hidden from assistive technology.
--}}
@php
    $previewUrl = route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid]);
    $settingsUrl = route('customer.workspaces.businesses.website.studio.show', [$workspaceUid, $businessUid, 'settings']);
    $domainsUrl = route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]);
@endphp

<div class="website-overview" data-testid="website-overview">
    {{--
        Actions first, exactly as in the design: with unpublished changes this is the amber "You have unpublished changes" banner
        carrying Preview draft + Publish update; otherwise a plain row. (Edit now lives in the header: data-testid="action-edit".)
    --}}
    <div class="website-actions @if ($hasDraftChanges) is-draft @endif">
        <div class="website-actions-text">
            <span class="website-dot {{ $hasDraftChanges ? 'is-draft' : 'is-live' }}" aria-hidden="true"></span>
            <div>
                @if ($hasDraftChanges)
                    <b>{{ $isPublished ? 'You have unpublished changes' : 'Your website is ready to publish' }}</b>
                    <span class="sub">{{ $isPublished ? 'Compare below, then publish when you\'re happy.' : 'Preview your draft, then publish when you\'re happy.' }}</span>
                @else
                    <b>Your draft matches what is live</b>
                    <span class="sub">Nothing to publish right now.</span>
                @endif
            </div>
        </div>

        <x-button variant="outline" icon="eye" href="{{ $previewUrl }}" data-testid="action-preview">Preview draft</x-button>

        @if ($pageCount > 0)
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
                @csrf
                <x-button variant="{{ $hasDraftChanges ? 'primary' : 'outline' }}" icon="upload" type="submit" data-testid="action-publish">{{ $isPublished ? ($hasDraftChanges ? 'Publish update' : 'Publish') : 'Publish' }}</x-button>
            </form>
        @endif
    </div>

    <div class="website-previews">
        {{-- LIVE --}}
        <div class="website-preview-col" data-testid="live-column">
            <div class="website-preview-label">
                <span class="website-dot {{ $isPublished ? 'is-live' : '' }}" aria-hidden="true"></span>
                <span>Live now</span>
                <span class="wb-label-note">What visitors see</span>
            </div>

            @if ($isPublished)
                <a class="website-preview-frame" href="{{ $liveOpenUrl }}" target="_blank" rel="noopener" data-testid="live-preview" aria-label="Open your live website in a new tab">
                    <iframe data-site-preview src="{{ $liveFrameUrl }}" title="Your live website" sandbox="allow-same-origin" tabindex="-1" aria-hidden="true" loading="lazy"></iframe>
                </a>
                @if ($liveHost)
                    <a class="website-preview-meta" href="{{ $liveOpenUrl }}" target="_blank" rel="noopener">{{ $liveHost }}</a>
                @endif
            @else
                <div class="website-preview-frame is-empty" data-testid="live-empty">
                    <span class="text-caption">Not published yet</span>
                </div>
            @endif
        </div>

        {{-- DRAFT / PREVIEW --}}
        <div class="website-preview-col" data-testid="draft-column">
            <div class="website-preview-label">
                <span class="website-dot {{ $hasDraftChanges ? 'is-draft' : '' }}" aria-hidden="true"></span>
                <span>Draft / Preview</span>
                @if ($isPublished && $hasDraftChanges)
                    <span class="website-chip" data-testid="draft-changes-chip">Unpublished changes</span>
                @endif
            </div>

            @if ($hasDraftChanges)
                <a class="website-preview-frame" href="{{ $previewUrl }}" data-testid="draft-preview" aria-label="Preview your draft website">
                    <iframe data-site-preview src="{{ $previewUrl }}" title="Your draft website" sandbox="allow-same-origin" tabindex="-1" aria-hidden="true" loading="lazy"></iframe>
                </a>
            @else
                <a class="website-preview-frame is-empty is-quiet" href="{{ $previewUrl }}" data-testid="draft-matches-live">
                    <span class="text-caption">No unpublished changes &mdash; your draft matches what is live.</span>
                </a>
            @endif
        </div>
    </div>

    @if ($website->presentation_changes_pending_at !== null)
        {{--
            Editing the FAQ, the custom section or the photo order / cover after the pages exist updates the
            setup data and the real assets, but never the generated page text itself; this is the one honest
            signal that the change has not reached the pages yet, and the one existing action that applies it.
        --}}
        <x-alert variant="warning" class="mt-3">
            Some recent changes (FAQ, custom section, or photo order/cover) haven't reached your pages yet.
            <form method="POST" data-generation-form action="{{ route('customer.workspaces.businesses.website.generate', [$workspaceUid, $businessUid]) }}" class="d-inline">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <button type="submit" class="btn btn-sm btn-warning">Rebuild now</button>
            </form>
        </x-alert>
    @endif

    @if (! empty($catalogSync['out_of_sync']))
        {{-- Packages & Products is the one pricing truth; a published revision is immutable, so a catalog change since the last publish is flagged, with the existing safe path (publish again). --}}
        <x-alert variant="warning" class="mt-3" data-catalog-sync>
            Your packages changed after you last published
            @if (! empty($catalogSync['changed']))
                (updated: {{ implode(', ', $catalogSync['changed']) }})@endif
            @if (! empty($catalogSync['removed']))
                (removed: {{ implode(', ', $catalogSync['removed']) }})@endif.
            Your live website still shows the older package details.
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-warning">Publish update</button>
            </form>
        </x-alert>
    @endif

    @if (! empty($mediaWarnings))
        <x-alert variant="warning" class="mt-3">
            <strong>Missing media checklist:</strong>
            <ul class="mb-0">
                @foreach ($mediaWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @php
        $showDomainCard = $isPublished && $domain === null;
    @endphp
    @if ($showDomainCard || ! empty($health))
        <div class="wb-split">
            @if ($showDomainCard)
                {{-- The natural next step after publishing. Gone for good once a domain is connected; the domain itself stays manageable under Settings. --}}
                <x-card class="website-next-step" data-testid="connect-domain-card">
                    <div class="wb-domain-next">
                        <span class="wb-tile" aria-hidden="true"><x-ds-icon name="globe" size="20" /></span>
                        <div class="wb-grow">
                            <h6>{{ $pendingDomain ? 'Finish connecting your domain' : 'Connect your domain' }}</h6>
                            <p class="wb-muted">{{ $pendingDomain ? $pendingDomain->domain . ' isn\'t live yet. Finish the steps to put your website on it.' : 'Use your own address, like www.yourbusiness.com.' }}</p>
                        </div>
                        <x-button variant="primary" href="{{ $domainsUrl }}">{{ $pendingDomain ? 'Continue' : 'Connect domain' }}</x-button>
                    </div>
                </x-card>
            @endif

            @if (! empty($health))
                @php
                    $hOk = (int) ($health['summary']['ok'] ?? 0);
                    $hWarn = (int) ($health['summary']['warn'] ?? 0);
                    $hFail = (int) ($health['summary']['fail'] ?? 0);
                @endphp
                {{-- Open by default only when something needs fixing now (e.g. a quote form that turns visitors away): never hidden. --}}
                <details class="website-health-fold" data-testid="website-health-fold" @if ($hFail > 0) open @endif>
                    <summary>
                        <span class="wb-health-main">
                            <span class="wb-health-line">
                                <b>Website health</b>
                                <span class="wb-muted">{{ $hOk }} good @if ($hWarn > 0)&middot; {{ $hWarn }} need attention @endif @if ($hFail > 0)&middot; {{ $hFail }} to fix now @endif</span>
                            </span>
                            <span class="wb-health-bar" aria-hidden="true">
                                @if ($hOk > 0)<span class="ok" style="flex: {{ $hOk }}"></span>@endif
                                @if ($hWarn > 0)<span class="warn" style="flex: {{ $hWarn }}"></span>@endif
                                @if ($hFail > 0)<span class="fail" style="flex: {{ $hFail }}"></span>@endif
                            </span>
                        </span>
                        <span class="wb-link">See details <x-ds-icon name="chevron-right" size="14" aria-hidden="true" /></span>
                    </summary>
                    <div class="wb-health-body">
                        @include('customer.business.website.studio._health')
                    </div>
                </details>
            @endif
        </div>
    @endif
</div>
