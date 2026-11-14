{{-- One best next move + the reusable listing kit. Inputs: $section, $canManage, $gbpUrl, $settingsUrl.
     All text is escaped; external links only from SeoLinkSafety-checked catalog URLs. Opening a link never
     marks a directory Listed. --}}
@php
    use App\Library\Seo\SeoCitationNextAction;
    use App\Library\Seo\SeoLinkSafety;

    $best = SeoCitationNextAction::for($section);
    $bestRow = $best['row'];
    $bestAction = $bestRow !== null ? SeoCitationNextAction::directoryAction($bestRow) : ['label' => $best['action'], 'hint' => null];
    $bestUrl = $bestRow !== null ? SeoLinkSafety::safeHttpsUrl($bestRow->directory->claim_url ?: $bestRow->directory->website_url) : null;
    $kit = SeoCitationNextAction::listingKit($section);
    $canOpenDrawer = $bestRow !== null && $canManage && $bestRow->writable;
@endphp
<div class="cz-split mb-2">
    <x-card :padded="true" data-section="citation-next-best" data-kind="{{ $best['kind'] }}">
        <p class="text-section-heading mb-25">Your best next move</p>
        <h5 class="mb-50" data-role="next-best-title">{{ $best['title'] }}</h5>
        <p class="mb-1" data-role="next-best-why">{{ $best['why'] }}</p>
        @if($bestAction['hint'])<p class="text-caption" data-role="next-best-hint">{{ $bestAction['hint'] }}</p>@endif
        @if($best['evidence'] !== [])
            <p class="text-caption mb-25"><strong>What we know</strong></p>
            <ul class="text-caption ps-2 mb-1" data-role="next-best-evidence">
                @foreach($best['evidence'] as $line)<li>{{ $line }}</li>@endforeach
            </ul>
        @endif
        <div class="d-flex flex-wrap gap-50" data-role="next-best-actions">
            @if($best['target'] === 'settings')
                @can('access_backend')<x-button :href="$settingsUrl" variant="primary" size="sm" data-role="next-best-action">{{ $best['action'] }}</x-button>@endcan
            @elseif($best['target'] === 'google')
                <x-button :href="$gbpUrl" variant="primary" size="sm" data-role="next-best-action">{{ $best['action'] }}</x-button>
            @elseif($best['target'] === 'directory' && $bestUrl !== null)
                <a class="btn btn-sm btn-primary d-inline-flex align-items-center gap-50" href="{{ $bestUrl }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="next-best-action"><x-ds-icon name="external-link" size="14" />{{ $bestAction['label'] }}</a>
            @endif
            @if($canOpenDrawer)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-{{ $bestRow->directory->key }}" data-role="next-best-record">Record what you find</button>
            @endif
        </div>
        @if($best['target'] === 'directory')
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
