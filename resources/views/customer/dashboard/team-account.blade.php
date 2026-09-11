{{--
    Customer Experience Slice 4, Correction 1 decision D — a team member's
    route back to the main account (user.account.login_as, unchanged).
    Never rendered while viewing as a client or under admin impersonation.
--}}
<section class="mb-2" aria-labelledby="dashboard-team-account-heading" data-band="team_account">
    <x-card>
        <h2 class="h4 text-section-heading mb-50" id="dashboard-team-account-heading">{{ __('locale.sub_accounts.manage_account') }}</h2>
        <p class="mb-1">{{ $parent['message'] }}</p>
        <x-button variant="primary" size="sm" :href="$parent['url']" icon="log-in" data-role="login-as-parent">{{ $parent['label'] }}</x-button>
    </x-card>
</section>
