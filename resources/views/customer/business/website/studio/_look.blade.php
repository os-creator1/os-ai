{{--
    Website look — the owner's safe brand tokens: one colour, a logo and a hero image. The template keeps
    layout and fonts; changing the template is its own screen (Settings -> Change template or rebuild).
    Posts to website.look.update (WebsiteLookController), which returns here.
--}}
@if (! empty($currentDesign))
    @php
        $lookTheme = $website->theme ?? [];
        $lookAssets = $website->assets()->whereIn('uid', array_filter([$lookTheme['logo_asset_uid'] ?? null, $lookTheme['hero_asset_uid'] ?? null]))->get()->keyBy('uid');
        $brandColor = $lookTheme['brand_color'] ?? null;
        $logo = isset($lookTheme['logo_asset_uid'], $lookAssets[$lookTheme['logo_asset_uid']]) ? ['url' => $lookAssets[$lookTheme['logo_asset_uid']]->url(), 'alt' => $lookAssets[$lookTheme['logo_asset_uid']]->alt_text] : null;
        $hero = isset($lookTheme['hero_asset_uid'], $lookAssets[$lookTheme['hero_asset_uid']]) ? ['url' => \App\Library\Website\Media\WebsiteMediaPayload::thumbUrl($lookAssets[$lookTheme['hero_asset_uid']]), 'alt' => $lookAssets[$lookTheme['hero_asset_uid']]->alt_text] : null;
    @endphp
    <x-card class="mb-3" data-testid="studio-look">
        <h6 class="mb-3">Your website's look</h6>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.look.update', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data">
            @csrf
            @include('customer.business.website._brand-fields')
            <div class="mt-3">
                <button type="submit" class="btn btn-primary" data-testid="look-save">Save changes</button>
            </div>
        </form>
    </x-card>
@endif
