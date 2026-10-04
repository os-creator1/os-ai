{{--
    Website V1 final — Studio's compact look card: which template the website
    uses, the one route to change it (the rebuild flow, with its layout-change
    warning), and the safe brand tokens. Template changes never happen here
    directly — a generated website changes layout only through the rebuild flow.
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h6 class="mb-0">Your website's look</h6>
                <div class="text-caption" data-testid="studio-template">Template {{ $currentDesign->number }} &mdash; {{ $currentDesign->label }}</div>
            </div>
            <x-button variant="outline" href="{{ route('customer.workspaces.businesses.website.rebuild.form', [$workspaceUid, $businessUid]) }}">Change template or rebuild</x-button>
        </div>

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.look.update', [$workspaceUid, $businessUid]) }}" enctype="multipart/form-data">
            @csrf
            @include('customer.business.website._brand-fields')
            <div class="mt-3">
                <button type="submit" class="btn btn-outline-primary" data-testid="look-save">Save look</button>
                <span class="text-caption ms-2">Saved changes appear in Preview now and on your live website after you publish.</span>
            </div>
        </form>
    </x-card>
@endif
