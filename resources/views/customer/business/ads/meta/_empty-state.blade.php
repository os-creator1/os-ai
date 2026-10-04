{{--
    Meta Ads Module V1 (contract 24 §3/§4) — the standard empty state for a Meta
    page that has no account to show. Every data page includes it when
    ResolvesMetaAdsBusinessTenancy::metaAdsViewData()['metaState'] is not 'ready':

        @if($metaState !== 'ready')
            @include('customer.business.ads.meta._empty-state')
        @else ... the page ... @endif

    not_connected -> Connect Meta; expired (expired or revoked token) -> a
    Reconnect Meta call to action (the owner must re-authorise: Meta has no
    refresh token); no_account -> the account chooser. Without manage_meta_ads
    the page says whom to ask. Connect is a CSRF-protected POST. Copy is fixed;
    nothing here is provider text.
--}}
<x-card :padded="true" data-role="ads-empty-state" data-state="{{ $metaState }}">
    @if($metaState === 'not_connected')
        <x-empty-state icon="megaphone" title="Connect Meta Ads"
                       description="Connect Meta to see where your Facebook and Instagram ad budget is turning into results.">
            <x-slot:action>
                @if($metaCanConnect)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.connect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="connect-meta-ads">Connect Meta</x-button>
                    </form>
                @else
                    <p class="text-caption mb-0" data-role="ask-owner">Ask the owner of this account to connect Meta.</p>
                @endif
            </x-slot:action>
        </x-empty-state>
    @elseif($metaState === 'expired')
        <x-empty-state icon="megaphone" title="Meta needs to be reconnected"
                       description="Your Meta connection has expired or was revoked, so we cannot refresh your figures. Reconnect Meta to continue; your earlier figures are kept.">
            <x-slot:action>
                @if($metaCanConnect)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.ads.meta.connect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="reconnect-meta-ads">Reconnect Meta</x-button>
                    </form>
                @else
                    <p class="text-caption mb-0" data-role="ask-owner">Ask the owner of this account to reconnect Meta.</p>
                @endif
            </x-slot:action>
        </x-empty-state>
    @elseif($metaState === 'no_account')
        <x-empty-state icon="list-checks" title="Choose your Meta ad account"
                       description="Meta is connected, but no ad account has been chosen yet. Choose the account whose results you want to see.">
            <x-slot:action>
                @if($metaCanConnect)
                    <x-button :href="route('customer.workspaces.businesses.ads.meta.accounts', [$workspaceUid, $businessUid])" icon="list-checks" data-role="choose-account">Choose ad account</x-button>
                @else
                    <p class="text-caption mb-0" data-role="ask-owner">Ask the owner of this account to choose the Meta ad account.</p>
                @endif
            </x-slot:action>
        </x-empty-state>
    @endif
</x-card>
