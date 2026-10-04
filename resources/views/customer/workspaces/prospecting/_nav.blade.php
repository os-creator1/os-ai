@php
    $prospectingActive = $prospectingActive ?? null;
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
</div>
