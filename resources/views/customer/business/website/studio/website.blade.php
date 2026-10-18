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
    <div class="website-previews">
        {{-- LIVE --}}
        <div class="website-preview-col" data-testid="live-column">
            <div class="website-preview-label">
                <span class="website-dot {{ $isPublished ? 'is-live' : '' }}" aria-hidden="true"></span>
                <span>Live</span>
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

    <div class="website-actions">
        <x-button variant="outline" icon="eye" href="{{ $previewUrl }}" data-testid="action-preview">Preview</x-button>

        @if ($pageCount > 0)
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
                @csrf
                <x-button variant="{{ $hasDraftChanges ? 'primary' : 'outline' }}" icon="globe" type="submit" data-testid="action-publish">{{ $isPublished ? ($hasDraftChanges ? 'Publish update' : 'Publish') : 'Publish' }}</x-button>
            </form>
        @endif

        <x-button variant="ghost" icon="pencil" href="{{ $settingsUrl }}" data-testid="action-edit" data-website-go="settings">Edit</x-button>
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

    @if ($isPublished && $domain === null)
        {{-- The natural next step after publishing. Gone for good once a domain is connected; the domain itself stays manageable under Settings. --}}
        <x-card class="website-next-step mt-3" data-testid="connect-domain-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h6 class="mb-1">{{ $pendingDomain ? 'Finish connecting your domain' : 'Connect your domain' }}</h6>
                    <p class="text-caption mb-0">{{ $pendingDomain ? $pendingDomain->domain . ' isn\'t live yet. Finish the steps to put your website on it.' : 'Put your website on your own address, like www.yourbusiness.com.' }}</p>
                </div>
                <x-button variant="primary" icon="link" href="{{ $domainsUrl }}">{{ $pendingDomain ? 'Continue' : 'Connect domain' }}</x-button>
            </div>
        </x-card>
    @endif

    @if (! empty($health))
        {{-- Open by default only when something needs fixing now (e.g. a quote form that turns visitors away): never hidden. --}}
        <details class="website-health-fold mt-3" data-testid="website-health-fold" @if (($health['summary']['fail'] ?? 0) > 0) open @endif>
            <summary>
                <span>Website health</span>
                <span class="text-caption ms-2">{{ $health['summary']['ok'] }} good @if ($health['summary']['warn'] > 0)&middot; {{ $health['summary']['warn'] }} need attention @endif @if ($health['summary']['fail'] > 0)&middot; {{ $health['summary']['fail'] }} to fix now @endif</span>
            </summary>
            <div class="mt-2">
                @include('customer.business.website.studio._health')
            </div>
        </details>
    @endif
</div>
