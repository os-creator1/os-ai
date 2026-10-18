@php
    $prospectingActive = $prospectingActive ?? null;
    $needsPhoneSetup = false;
    if ($prospectingActive !== 'phone-messaging') {
        $navWorkspace = \App\Models\Workspace::query()->where('uid', $workspaceUid)->first();
        $needsPhoneSetup = $navWorkspace !== null && app(\App\Library\AgencyOutreach\PhoneSetupHint::class)->needed($navWorkspace);
    }
@endphp

<div class="d-flex flex-wrap gap-1 mb-2" data-role="outreach-tabs">
    <x-button :variant="$prospectingActive === 'overview' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.overview', $workspaceUid)">Overview</x-button>
    <x-button :variant="$prospectingActive === 'prospects' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.prospects.index', $workspaceUid)">Prospects</x-button>
    <x-button :variant="$prospectingActive === 'conversations' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.conversations.index', $workspaceUid)">Conversations</x-button>
    <x-button :variant="$prospectingActive === 'campaigns' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)">Campaigns</x-button>
    <x-button :variant="$prospectingActive === 'script' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.script.show', $workspaceUid)">Script &amp; Settings</x-button>
    <x-button :variant="$prospectingActive === 'phone-messaging' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.phone-messaging', $workspaceUid)">Phone &amp; Messaging</x-button>
</div>

@if($needsPhoneSetup)
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center mb-2" data-role="outreach-phone-setup">
        <span>Texting isn't set up yet. Connect your own Telnyx account or get a number through us.</span>
        <x-button variant="primary" size="sm" :href="route('customer.workspaces.prospecting.phone-messaging', $workspaceUid)">Set up phone</x-button>
    </div>
@endif
