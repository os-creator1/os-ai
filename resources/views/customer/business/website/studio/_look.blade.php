{{--
    Website look - the owner's safe brand tokens: one colour, a logo and a hero image, laid out as the Settings
    "Your website's look" card. The fields, names and the saving endpoint are the canonical ones (website.look.update ->
    WebsiteLookController, the same media pipeline as the Review screen); only the presentation differs from
    _brand-fields. The template keeps layout and fonts; changing the template is its own screen.
--}}
@if (! empty($currentDesign))
    @php
        $lookTheme = $website->theme ?? [];
        $lookAssets = $website->assets()->whereIn('uid', array_filter([$lookTheme['logo_asset_uid'] ?? null, $lookTheme['hero_asset_uid'] ?? null]))->get()->keyBy('uid');
        $brandColor = $lookTheme['brand_color'] ?? null;
        $logo = isset($lookTheme['logo_asset_uid'], $lookAssets[$lookTheme['logo_asset_uid']]) ? ['url' => $lookAssets[$lookTheme['logo_asset_uid']]->url(), 'alt' => $lookAssets[$lookTheme['logo_asset_uid']]->alt_text] : null;
        $hero = isset($lookTheme['hero_asset_uid'], $lookAssets[$lookTheme['hero_asset_uid']]) ? ['url' => \App\Library\Website\Media\WebsiteMediaPayload::thumbUrl($lookAssets[$lookTheme['hero_asset_uid']]), 'alt' => $lookAssets[$lookTheme['hero_asset_uid']]->alt_text] : null;
    @endphp
    <x-card data-testid="studio-look">
        <h6 class="mb-25">Your website's look</h6>
        <p class="text-caption mb-2">Colour and images used across your site.</p>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.look.update', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data">
            @csrf

            <div class="website-look-field">
                <label class="website-look-label" for="look-brand-color">Brand colour</label>
                <div class="website-look-color">
                    <input type="color" class="website-look-swatch" id="look-brand-picker" value="{{ $brandColor ?: '#1a56db' }}" title="Pick a colour" aria-label="Pick a brand colour">
                    <input type="text" class="form-control" id="look-brand-color" name="brand_color" value="{{ old('brand_color', $brandColor) }}" placeholder="Template colour" maxlength="20" inputmode="text" autocomplete="off">
                </div>
                @error('brand_color') <div class="text-danger small mt-50">{{ $message }}</div> @enderror
            </div>

            <div class="website-look-field">
                <span class="website-look-label">Logo</span>
                <div class="website-look-upload">
                    @if ($logo)
                        <img class="website-look-thumb website-look-thumb--logo" src="{{ $logo['url'] }}" alt="{{ $logo['alt'] ?: 'Current logo' }}" data-testid="look-logo-preview">
                    @else
                        <span class="website-look-upload-icon" aria-hidden="true"><x-ds-icon name="upload" size="14" /></span>
                    @endif
                    <span class="website-look-upload-text">
                        <strong>{{ $logo ? 'Current logo' : 'No logo yet' }}</strong>
                        <span class="text-caption" data-look-filename>Upload an image file</span>
                    </span>
                    <label class="btn btn-outline-secondary btn-sm mb-0 website-look-choose">Choose file
                        <input type="file" class="visually-hidden" id="look-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" data-look-file>
                    </label>
                </div>
                <input type="text" class="form-control mt-50" name="logo_alt" placeholder="Alt text (e.g. {{ $logo['alt'] ?? 'Your business logo' }})" maxlength="160" aria-label="Logo alt text">
                @if ($logo)
                    <div class="form-check mt-50"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="look-remove-logo"><label class="form-check-label" for="look-remove-logo">Remove logo</label></div>
                @endif
                @error('logo') <div class="text-danger small mt-50">{{ $message }}</div> @enderror
            </div>

            <div class="website-look-field">
                <span class="website-look-label">Hero image</span>
                <div class="website-look-upload">
                    @if ($hero)
                        <img class="website-look-thumb" src="{{ $hero['url'] }}" alt="{{ $hero['alt'] ?: 'Current hero image' }}" data-testid="look-hero-preview">
                    @else
                        <span class="website-look-upload-icon" aria-hidden="true"><x-ds-icon name="image" size="14" /></span>
                    @endif
                    <span class="website-look-upload-text">
                        <strong>{{ $hero ? 'Current hero image' : 'No hero image yet' }}</strong>
                        <span class="text-caption" data-look-filename>The large photo at the top of your home page</span>
                    </span>
                    <label class="btn btn-outline-secondary btn-sm mb-0 website-look-choose">Choose file
                        <input type="file" class="visually-hidden" id="look-hero" name="hero" accept="image/png,image/jpeg,image/webp,image/gif" data-look-file>
                    </label>
                </div>
                <input type="text" class="form-control mt-50" name="hero_alt" placeholder="Alt text (describe the photo)" maxlength="160" aria-label="Hero image alt text">
                @if ($hero)
                    <div class="form-check mt-50"><input class="form-check-input" type="checkbox" name="remove_hero" value="1" id="look-remove-hero"><label class="form-check-label" for="look-remove-hero">Remove hero image</label></div>
                @endif
                @error('hero') <div class="text-danger small mt-50">{{ $message }}</div> @enderror
            </div>

            <div class="d-flex justify-content-end mt-2">
                <button type="submit" class="btn btn-primary" data-testid="look-save">Save changes</button>
            </div>
        </form>
    </x-card>
@endif
