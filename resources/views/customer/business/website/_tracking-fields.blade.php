{{-- Shared fields of one tracking setting. Expects $setting (WebsiteTrackingSetting|null). Values are validated server-side; the AI never fills these. --}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="google_tag_id_{{ $idSuffix }}">Google tag / Analytics ID</label>
        <input id="google_tag_id_{{ $idSuffix }}" type="text" name="google_tag_id" class="form-control" placeholder="G-ABC123XYZ or GTM-ABC123" value="{{ old('google_tag_id', $setting?->google_tag_id) }}" maxlength="32" autocomplete="off">
    </div>
    <div class="col-md-6">
        <label class="form-label" for="meta_pixel_id_{{ $idSuffix }}">Meta Pixel ID</label>
        <input id="meta_pixel_id_{{ $idSuffix }}" type="text" name="meta_pixel_id" class="form-control" placeholder="123456789012345" value="{{ old('meta_pixel_id', $setting?->meta_pixel_id) }}" maxlength="24" autocomplete="off">
    </div>
    <div class="col-md-6">
        <label class="form-label" for="google_ads_id_{{ $idSuffix }}">Google Ads conversion ID</label>
        <input id="google_ads_id_{{ $idSuffix }}" type="text" name="google_ads_id" class="form-control" placeholder="AW-123456789" value="{{ old('google_ads_id', $setting?->google_ads_id) }}" maxlength="32" autocomplete="off">
    </div>
    <div class="col-md-6">
        <label class="form-label" for="google_ads_lead_label_{{ $idSuffix }}">Google Ads lead conversion label</label>
        <input id="google_ads_lead_label_{{ $idSuffix }}" type="text" name="google_ads_lead_label" class="form-control" placeholder="AbC-dEfGhIjKlM" value="{{ old('google_ads_lead_label', $setting?->google_ads_lead_label) }}" maxlength="64" autocomplete="off">
    </div>
    <div class="col-12">
        <label class="form-label" for="head_snippet_{{ $idSuffix }}">Custom code for the page &lt;head&gt; (optional)</label>
        <textarea id="head_snippet_{{ $idSuffix }}" name="head_snippet" class="form-control" rows="4" maxlength="{{ \App\Library\Website\Tracking\WebsiteTrackingSettings::MAX_SNIPPET }}" spellcheck="false">{{ old('head_snippet', $setting?->head_snippet) }}</textarea>
        <div class="form-text">Only script, noscript, meta, link, style and img tags, with https addresses. You are responsible for this code. It runs only on your own domain's published pages, after visitor consent when required — never in previews or this dashboard.</div>
    </div>
    <div class="col-12 d-flex flex-wrap gap-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="is_enabled_{{ $idSuffix }}" @checked(old('is_enabled', $setting?->is_enabled ?? true))>
            <label class="form-check-label" for="is_enabled_{{ $idSuffix }}">Enabled</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="require_consent" value="1" id="require_consent_{{ $idSuffix }}" @checked(old('require_consent', $setting?->require_consent ?? true))>
            <label class="form-check-label" for="require_consent_{{ $idSuffix }}">Ask visitors for cookie consent before loading</label>
        </div>
    </div>
</div>
