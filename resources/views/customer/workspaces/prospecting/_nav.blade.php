@php
    $prospectingActive = $prospectingActive ?? null;
@endphp

<div class="d-flex flex-wrap gap-1 mb-2">
    <x-button :variant="$prospectingActive === 'overview' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.overview', $workspaceUid)">Overview</x-button>
    <x-button :variant="$prospectingActive === 'prospects' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.prospects.index', $workspaceUid)">Prospects</x-button>
    <x-button :variant="$prospectingActive === 'campaigns' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)">Campaigns</x-button>
    <x-button :variant="$prospectingActive === 'channels' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.channels.index', $workspaceUid)">Channels</x-button>
    <x-button :variant="$prospectingActive === 'settings' ? 'primary' : 'outline'" size="sm"
              :href="route('customer.workspaces.prospecting.settings.show', $workspaceUid)">Agent Setup</x-button>
</div>
