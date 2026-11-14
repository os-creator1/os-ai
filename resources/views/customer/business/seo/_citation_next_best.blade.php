{{-- Hero ("Your best next move") + Listing kit. Inputs: $section, $canManage, $gbpUrl, $settingsUrl.
     All text is escaped; external links only from SeoLinkSafety-checked catalog or recorded URLs. Opening a link
     never marks a directory Listed. --}}
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
    $mode = $best['mode'];
    $eyebrow = ['clear' => 'All clear', 'progress' => 'In progress', 'next' => 'Your best next move'][$mode];
    $eyebrowIcon = ['clear' => 'check', 'progress' => 'clock', 'next' => 'spark'][$mode];
    $heroClass = ['clear' => 'cg-clear', 'progress' => 'cg-prog', 'next' => ''][$mode];
    $kitAll = collect($kit)->filter(fn ($f) => filled($f['value']))->map(fn ($f) => $f['label'] . ': ' . $f['value'])->implode("\n");
    $privateAddress = ! $section->addressPermitted;
    $canEditProfile = \Illuminate\Support\Facades\Gate::allows('access_backend');
@endphp
<div class="cg-grid-top" data-role="citation-top">
    <section class="cg-card cg-hero {{ $heroClass }}" data-section="citation-next-best" data-kind="{{ $best['kind'] }}" data-mode="{{ $mode }}" @if($best['kind'] === SeoCitationNextAction::GOOGLE) data-action="google" @endif aria-live="polite">
        <span class="cg-eyebrow"><span class="cg-dot"><svg class="cg-ic" viewBox="0 0 24 24" style="width:13px;height:13px"><use href="#cg-i-{{ $eyebrowIcon }}"/></svg></span>{{ $eyebrow }}</span>
        <div style="display:flex;flex-direction:column;gap:10px">
            <h2 data-role="next-best-title">{{ $best['title'] }}</h2>
            <p class="cg-why" data-role="next-best-why">{{ $best['why'] }}</p>
        </div>
        <div class="cg-facts">
            <div class="cg-k">
                <h3>What we know</h3>
                <ul data-role="next-best-evidence">
                    @forelse($best['evidence'] as $line)
                        <li><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-check"/></svg>{{ $line }}</li>
                    @empty
                        <li><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-check"/></svg>No known differences in what you have recorded.</li>
                    @endforelse
                </ul>
            </div>
            <div class="cg-u">
                <h3>Not verified</h3>
                <ul data-role="next-best-unverified">
                    @foreach($best['unverified'] as $line)
                        <li @if($line === $best['google_note']) data-role="next-best-google-note" @endif><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-dash"/></svg>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="cg-cta-row" data-role="next-best-actions">
            @if($best['target'] === 'settings')
                @if($canEditProfile)
                    <a class="cg-btn cg-primary cg-lg" href="{{ $settingsUrl }}" data-role="next-best-action">{{ $best['action'] }}</a>
                @else
                    <span class="cg-hint">Ask an account owner to complete the business profile.</span>
                @endif
            @elseif($best['target'] === 'google')
                <a class="cg-btn cg-primary cg-lg" href="{{ $gbpUrl }}" data-role="next-best-action">{{ $best['action'] }}</a>
            @elseif($best['target'] === 'directory' && $dir !== null && $dir['url'] !== null)
                <a class="cg-btn cg-primary cg-lg" href="{{ $dir['url'] }}" target="_blank" rel="{{ SeoLinkSafety::EXTERNAL_REL }}" data-role="next-best-action" data-action-kind="{{ $dir['kind'] }}">{{ $dir['label'] }}<svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-ext"/></svg></a>
            @endif
            @if($canOpenDrawer)
                <button type="button" class="cg-btn {{ $drawerPrimary ? 'cg-primary cg-lg' : 'cg-ghost' }}" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-{{ $bestRow->directory->key }}" data-role="{{ $drawerPrimary ? 'next-best-action' : 'next-best-record' }}" @if($drawerPrimary) data-action-kind="drawer" @endif>{{ $drawerPrimary ? $dir['label'] : 'Record what you find' }}</button>
            @endif
            @if($bestHint)
                <span class="cg-hint" data-role="next-best-hint">{{ $bestHint }}</span>
            @elseif($best['target'] === 'directory' && $dir !== null && $dir['url'] !== null)
                <span class="cg-hint" data-role="next-best-note">Opening the page does not change your status here. Come back and record the result once you have finished.</span>
            @endif
        </div>
        @if($bestHint && $best['target'] === 'directory' && $dir !== null && $dir['url'] !== null)
            <span class="cg-hint" data-role="next-best-note">Opening the page does not change your status here. Come back and record the result once you have finished.</span>
        @endif
    </section>

    <section class="cg-card cg-kit" data-section="listing-kit" aria-label="Business listing kit">
        <div class="cg-kit-h">
            <div><h2>Listing kit</h2><p>Your details, ready to paste into any directory.</p></div>
            @if($kitAll !== '')
                <button type="button" class="cg-btn cg-ghost cg-sm" data-copy="{{ $kitAll }}" data-all="1" data-role="kit-copy-all"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-copy"/></svg><span>Copy all</span></button>
            @endif
        </div>
        <div data-role="listing-kit">
            @foreach($kit as $field)
                <div class="cg-kf" data-kit="{{ $field['key'] }}">
                    <div class="cg-grow">
                        <span class="cg-lab">{{ $field['label'] }}</span>
                        @if(filled($field['value']))
                            @php $first = $field['key'] === 'service_area' ? trim(explode(',', $field['value'])[0]) : null; @endphp
                            <span class="cg-val" data-kit-value>{{ $field['key'] === 'service_area' ? $first : \Illuminate\Support\Str::limit($field['value'], 68, '…') }}@if($field['key'] === 'service_area' && str_contains($field['value'], ','))<small>Also serves {{ trim(substr($field['value'], strlen($first) + 1), ' ,') }}</small>@endif</span>
                        @elseif($field['key'] === 'phone' && $field['note'])
                            <span class="cg-val cg-miss" data-kit-withheld data-role="phone-not-compared">{{ $field['note'] }}</span>
                        @else
                            <span class="cg-val cg-miss" data-kit-missing>Not set</span>
                        @endif
                    </div>
                    @if(filled($field['value']))
                        <button type="button" class="cg-copy" data-copy="{{ $field['value'] }}" aria-label="Copy {{ strtolower($field['label']) }}"><svg class="cg-ic" viewBox="0 0 24 24" style="width:14px;height:14px"><use href="#cg-i-copy"/></svg><span>Copy</span></button>
                    @elseif($field['key'] === 'phone' && $field['note'])
                        <span class="cg-pill cg-p-amber">Needs confirming</span>
                    @elseif($canEditProfile && in_array($field['key'], ['name', 'phone', 'website', 'address', 'description'], true))
                        <a href="{{ $settingsUrl }}" class="cg-add">Add</a>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="cg-priv" @if($privateAddress) data-role="address-withheld" @endif><svg class="cg-ic" viewBox="0 0 24 24"><use href="#cg-i-lock"/></svg><span>{{ $privateAddress ? 'Your street address is private for this location, so it is never shown or copied. Use your service area instead.' : 'Only the address this location publishes is shown here.' }}</span></div>
    </section>
</div>
