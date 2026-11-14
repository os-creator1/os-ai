{{-- One best next move + the reusable listing kit. Inputs: $section, $canManage, $gbpUrl, $settingsUrl.
     All text is escaped; external links only from SeoLinkSafety-checked catalog URLs. Opening a link never
     marks a directory Listed. --}}
@php
    use App\Library\Seo\SeoCitationNextAction;
    use App\Library\Seo\SeoLinkSafety;

    $best = SeoCitationNextAction::for($section, (bool) $canManage);
    $bestRow = $best['row'];
    $dir = $best['directory'];
    $bestHint = $dir['hint'] ?? null;
    $kit = SeoCitationNextAction::listingKit($section);
    $canOpenDrawer = $bestRow !== null && $canManage && $bestRow->writable;
    $drawerPrimary = $dir !== null && $dir['kind'] === 'drawer';
@endphp
<div class="cz-split mb-2">
    <x-card :padded="true" data-section="citation-next-best" data-kind="{{ $best['kind'] }}">
        <p class="text-section-heading mb-25">Your best next move</p>
        <h5 class="mb-50" data-role="next-best-title">{{ $best['title'] }}</h5>
        <p class="mb-1" data-role="next-best-why">{{ $best['why'] }}</p>
        @if($bestHint)<p class="text-caption" data-role="next-best-hint">{{ $bestHint }}</p>@endif
        @if($best['google_note'])<p class="text-caption" data-role="next-best-google-note">{{ $best['google_note'] }}</p>@endif
        @if($best['evidence'] !== [])
            <p class="text-caption mb-25"><strong>What we know</strong></p>
            <ul class="text-caption ps-2 mb-1" data-role="next-best-evidence">
                @foreach($best['evidence'] as $line)<li>{{ $line }}</li>@endforeach
            </ul>
        @endif
        <div class="d-flex flex-wrap gap-50" data-role="next-best-actions">
            @if($best['target'] === 'settings')
                @can('access_backend')<x-button :href="$settingsUrl" variant="primary" size="sm" data-role="next-best-action">{{ $best['action'] }}</x-button>@else<span class="text-caption">Ask an account owner to complete the business profile.</span>@endcan
            @elseif($best['target'] === 'google')
                <x-button :href="$gbpUrl" variant="primary" size="sm" data-role="next-best-action">{{ $best['action'] }}</x-button>
            @elseif($best['target'] === 'directory' && $dir !== null && $dir['url'] !== null)
                <a class="btn btn-sm btn-primary d-inline-flex align-items-center gap-50" href="{{ $dir['url'] }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="next-best-action" data-action-kind="{{ $dir['kind'] }}"><x-ds-icon name="external-link" size="14" />{{ $dir['label'] }}</a>
            @endif
            @if($canOpenDrawer)
                <button type="button" class="btn btn-sm {{ $drawerPrimary ? 'btn-primary' : 'btn-outline-secondary' }}" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-{{ $bestRow->directory->key }}" data-role="{{ $drawerPrimary ? 'next-best-action' : 'next-best-record' }}" @if($drawerPrimary) data-action-kind="drawer" @endif>{{ $drawerPrimary ? $dir['label'] : 'Record what you find' }}</button>
            @endif
        </div>
        @if($best['target'] === 'directory' && $dir !== null && $dir['url'] !== null)
            <p class="text-caption mt-1 mb-0" data-role="next-best-note">Opening the page does not change your status here. Come back and record the result once you have finished.</p>
        @endif
    </x-card>

    <x-card :padded="true" data-section="listing-kit">
        <p class="text-section-heading mb-0">Listing kit</p>
        <p class="text-caption">Your confirmed details, ready to paste into any directory form.</p>
        <dl class="cz-profile mb-0" data-role="listing-kit">
            @foreach($kit as $field)
                <div data-kit="{{ $field['key'] }}">
                    <dt>{{ $field['label'] }}</dt>
                    @if($field['value'] !== null && $field['value'] !== '')
                        <dd class="d-flex align-items-start justify-content-between gap-50">
                            <span data-kit-value>{{ $field['value'] }}</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" data-copy="{{ $field['value'] }}" aria-label="Copy {{ $field['label'] }}">Copy</button>
                        </dd>
                    @else
                        <dd class="cz-muted" data-kit-missing>Not set</dd>
                    @endif
                    @if($field['note'])<dd class="cz-muted">{{ $field['note'] }}</dd>@endif
                </div>
            @endforeach
        </dl>
    </x-card>
</div>
