{{--
    Website V1 final — the owner's safe look tokens: ONE brand colour, a logo
    and a hero image. Shared by the Review screen and Studio; posts to
    website.look.update (WebsiteLookController). Expects $brandColor, $logo, $hero.
--}}
<h6 class="mb-1">Brand colour, logo and hero image</h6>
<p class="text-caption mb-3">The template keeps control of layout and fonts. Your brand colour is applied to buttons and accents, with text colours adjusted automatically so everything stays readable.</p>

<div class="row g-3 align-items-start">
    <div class="col-md-4">
        <label class="form-label" for="look-brand-color">Brand colour</label>
        <div class="input-group">
            <input type="color" class="form-control form-control-color" id="look-brand-picker" value="{{ $brandColor ?: '#1a56db' }}" title="Pick a colour" aria-label="Pick a brand colour">
            <input type="text" class="form-control" id="look-brand-color" name="brand_color" value="{{ old('brand_color', $brandColor) }}" placeholder="Template colour" maxlength="20" inputmode="text" autocomplete="off">
        </div>
        <div class="form-text">Leave blank to use the template's own colour.</div>
        @error('brand_color') <div class="text-danger small">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="look-logo">Logo</label>
        @if ($logo)
            <div class="mb-2"><img src="{{ $logo['url'] }}" alt="{{ $logo['alt'] ?: 'Current logo' }}" style="max-height:48px; max-width:100%;" data-testid="look-logo-preview"></div>
        @endif
        <input type="file" class="form-control" id="look-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
        <input type="text" class="form-control mt-2" name="logo_alt" placeholder="Alt text (e.g. {{ $logo['alt'] ?? 'Your business logo' }})" maxlength="160" aria-label="Logo alt text">
        @if ($logo)
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="look-remove-logo"><label class="form-check-label" for="look-remove-logo">Remove logo</label></div>
        @endif
        @error('logo') <div class="text-danger small">{{ $message }}</div> @enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="look-hero">Hero image</label>
        @if ($hero)
            <div class="mb-2"><img src="{{ $hero['url'] }}" alt="{{ $hero['alt'] ?: 'Current hero image' }}" style="max-height:72px; max-width:100%; border-radius:6px;" data-testid="look-hero-preview"></div>
        @endif
        <input type="file" class="form-control" id="look-hero" name="hero" accept="image/png,image/jpeg,image/webp,image/gif">
        <input type="text" class="form-control mt-2" name="hero_alt" placeholder="Alt text (describe the photo)" maxlength="160" aria-label="Hero image alt text">
        @if ($hero)
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_hero" value="1" id="look-remove-hero"><label class="form-check-label" for="look-remove-hero">Remove hero image</label></div>
        @endif
        <div class="form-text">Optional. Without one, the template shows its own designed hero.</div>
        @error('hero') <div class="text-danger small">{{ $message }}</div> @enderror
    </div>
</div>


<script>
    (function () {
        var picker = document.getElementById('look-brand-picker');
        var text = document.getElementById('look-brand-color');
        if (!picker || !text) { return; }
        picker.addEventListener('input', function () { text.value = picker.value; });
        text.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { picker.value = text.value; } });
    })();
</script>
