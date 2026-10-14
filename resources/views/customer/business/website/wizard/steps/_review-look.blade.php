{{--
    "Choose your website's look": the four real template previews (the actual renderer, fed this business), and the
    brand colour / logo / hero image / legal links. One form posts to website.look.update (WebsiteLookController) — the
    same validated path Studio uses — so choosing a design never touches an answer. Expects $templateCards,
    $templateLocked, $brandColor, $logo, $hero, $privacyNoticeUrl, $termsUrl, $number.
--}}
<section class="rv-card" id="rv-look" data-testid="look-panel" aria-labelledby="rv-look-title">
    <div class="rv-card-head">
        <span class="rv-num" aria-hidden="true">{{ $number }}</span>
        <div>
            <h2 class="rv-title" id="rv-look-title">Choose your website's look</h2>
            <p class="rv-sub">Each preview shows your own business. You can change it any time before you build your website; your answers stay exactly as they are.</p>
        </div>
    </div>
    <div class="rv-card-body">
        @if ($templateLocked)
            <div class="rv-notice mb-3" data-testid="template-locked">This website is already built, so its layout changes through "Rebuild with a different template" in the Website studio. Your brand settings below can still be saved.</div>
        @endif

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.look.update', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data" id="look-form" data-testid="look-form">
            @csrf
            {{-- First submit button in the form: pressing Enter in a field saves the look and never switches the design. --}}
            <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Save look</button>

            @error('template_key') <div class="rv-notice mb-3" role="alert">{{ $message }}</div> @enderror

            <div class="rv-templates" role="group" aria-label="Website design">
                @foreach ($templateCards as $card)
                    @php($design = $card['design'])
                    @continue($design === null)
                    <div class="rv-template @if ($card['selected']) is-selected @endif" data-template-card="{{ $card['key'] }}">
                        <div class="rv-template-frame">
                            @if ($card['selected'])
                                <span class="rv-template-flag" data-testid="template-selected">Selected</span>
                            @endif
                            <iframe srcdoc="{{ $card['html'] }}" title="Preview of the {{ $design->label }} template" sandbox tabindex="-1" aria-hidden="true" data-template-iframe></iframe>
                        </div>
                        <div class="rv-template-body">
                            <div>
                                <div class="rv-template-name">{{ $design->label }}</div>
                                <div class="rv-template-desc">{{ $design->tagline }}</div>
                                <div class="rv-template-desc mt-1"><span class="rv-muted">Best for:</span> {{ $design->bestFor }}</div>
                            </div>
                            <div class="rv-template-actions">
                                @if ($card['selected'])
                                    <button type="button" class="btn btn-sm btn-primary d-inline-flex align-items-center" disabled aria-pressed="true">Selected</button>
                                @else
                                    <button type="submit" name="template_key" value="{{ $card['key'] }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center" @disabled($templateLocked) aria-label="Use the {{ $design->label }} design" data-template-use>Use this design</button>
                                @endif
                                <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" href="{{ route('customer.workspaces.businesses.website.template-preview', [$workspaceUid, $businessUid, $card['key']]) }}" target="_blank" rel="noopener" aria-label="Open full preview of the {{ $design->label }} design (new tab)">Preview</a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="rv-brand">
                <div>
                    <label class="rv-field-label" for="look-brand-color">Brand colour</label>
                    <div class="rv-color">
                        <input type="color" class="rv-swatch" id="look-brand-picker" value="{{ $brandColor ?: '#1a56db' }}" title="Pick a colour" aria-label="Pick a brand colour">
                        <input type="text" class="form-control form-control-sm" id="look-brand-color" name="brand_color" value="{{ old('brand_color', $brandColor) }}" placeholder="Template colour" maxlength="20" inputmode="text" autocomplete="off">
                    </div>
                    @error('brand_color') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="website-look-upload">
                    <span class="rv-field-label">Logo</span>
                    <div class="rv-upload">
                        @if ($logo)
                            <img class="rv-thumb rv-thumb--logo" src="{{ $logo['url'] }}" alt="{{ $logo['alt'] ?: 'Current logo' }}" data-testid="look-logo-preview">
                        @endif
                        <span class="rv-upload-name" data-look-filename>{{ $logo ? 'Current logo' : 'No logo yet' }}</span>
                        <label class="btn btn-sm btn-outline-secondary rv-file">{{ $logo ? 'Replace' : 'Upload logo' }}
                            <input type="file" class="visually-hidden" id="look-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" data-look-file>
                        </label>
                    </div>
                    @if ($logo)
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="look-remove-logo"><label class="form-check-label small" for="look-remove-logo">Remove logo</label></div>
                    @endif
                    @error('logo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="website-look-upload">
                    <span class="rv-field-label">Hero image</span>
                    <div class="rv-upload">
                        @if ($hero)
                            <img class="rv-thumb" src="{{ $hero['url'] }}" alt="{{ $hero['alt'] ?: 'Current hero image' }}" data-testid="look-hero-preview">
                        @endif
                        <span class="rv-upload-name" data-look-filename>{{ $hero ? 'Current hero image' : 'No hero image yet' }}</span>
                        <label class="btn btn-sm btn-outline-secondary rv-file">{{ $hero ? 'Replace' : 'Upload hero image' }}
                            <input type="file" class="visually-hidden" id="look-hero" name="hero" accept="image/png,image/jpeg,image/webp,image/gif" data-look-file>
                        </label>
                    </div>
                    @if ($hero)
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_hero" value="1" id="look-remove-hero"><label class="form-check-label small" for="look-remove-hero">Remove hero image</label></div>
                    @endif
                    @error('hero') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <details class="rv-alt">
                <summary>Describe your logo and hero image (alt text)</summary>
                <div class="rv-alt-grid">
                    <input type="text" class="form-control form-control-sm" name="logo_alt" placeholder="Logo description (e.g. {{ $logo['alt'] ?? 'Your business logo' }})" maxlength="160" aria-label="Logo alt text">
                    <input type="text" class="form-control form-control-sm" name="hero_alt" placeholder="Hero image description" maxlength="160" aria-label="Hero image alt text">
                </div>
            </details>

            <div class="rv-brand-links" data-testid="legal-links">
                <div>
                    <label class="rv-field-label" for="look-privacy-url">Privacy notice link</label>
                    <input type="url" class="form-control form-control-sm" id="look-privacy-url" name="privacy_notice_url" value="{{ old('privacy_notice_url', $privacyNoticeUrl ?? '') }}" placeholder="https://your-business.example/privacy" maxlength="500" inputmode="url" autocomplete="off">
                    <div class="form-text small">Your website collects inquiries, so link to your own published privacy notice. We never write or copy a policy for you; leave blank if you don't have one yet.</div>
                    @error('privacy_notice_url') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="rv-field-label" for="look-terms-url">Terms link (optional)</label>
                    <input type="url" class="form-control form-control-sm" id="look-terms-url" name="terms_url" value="{{ old('terms_url', $termsUrl ?? '') }}" placeholder="https://your-business.example/terms" maxlength="500" inputmode="url" autocomplete="off">
                    @error('terms_url') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-sm btn-outline-primary" data-testid="look-save">Save look</button>
            </div>
        </form>
    </div>
</section>

@include('customer.business.website._brand-fields-script')
