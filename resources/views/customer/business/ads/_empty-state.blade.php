{{--
    Google Ads Module V1 (contract 23 §16) — the standard empty state for an
    Ads page that has no account to show. Every data page includes it when
    ResolvesAdsBusinessTenancy::adsViewData()['adsState'] is not 'ready':

        @if($adsState !== 'ready')
            @include('customer.business.ads._empty-state')
        @else ... the page ... @endif

    Not connected -> Connect button (manage permission) or "ask the owner";
    connected without an account -> a link to the account chooser (manage
    permission) or "ask the owner". The Connect button is a CSRF-protected
    POST. Copy is fixed; nothing here is provider text.
--}}
@php
    $revoked = $connection !== null && $connection->state->value === 'revoked';
@endphp

<x-card :padded="true" data-role="ads-empty-state" data-state="{{ $adsState }}">
    @if($adsState === 'not_connected')
        <x-empty-state icon="megaphone"
                       :title="$revoked ? 'Google Ads needs to be reconnected' : 'Connect Google Ads'"
                       :description="$revoked
                           ? 'Google has revoked this connection, so we cannot refresh your figures. Reconnect Google Ads to continue; your earlier figures are kept.'
                           : 'Connect Google Ads to see where your ad budget is generating results.'">
            <x-slot:action>
                @if($adsCanManage)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.ads.connect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" icon="link" data-role="connect-google-ads">{{ $revoked ? 'Reconnect Google Ads' : 'Connect Google Ads' }}</x-button>
                    </form>
                @else
                    <p class="text-caption mb-0" data-role="ask-owner">Ask the owner of this account to connect Google Ads.</p>
                @endif
            </x-slot:action>
        </x-empty-state>
    @elseif($adsState === 'no_account')
        <x-empty-state icon="list-checks" title="Choose your Google Ads account"
                       description="Google Ads is connected, but no advertising account has been chosen yet. Choose the account whose results you want to see.">
            <x-slot:action>
                @if($adsCanManage)
                    <x-button :href="route('customer.workspaces.businesses.ads.accounts', [$workspaceUid, $businessUid])" icon="list-checks" data-role="choose-account">Choose account</x-button>
                @else
                    <p class="text-caption mb-0" data-role="ask-owner">Ask the owner of this account to choose the Google Ads account.</p>
                @endif
            </x-slot:action>
        </x-empty-state>
    @endif
</x-card>
