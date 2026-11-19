@extends('layouts/contentLayoutMaster')

@section('title', $campaign->name)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $campaign->name }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.prospecting.campaigns.index', $workspaceUid)">
                Back
            </x-button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Campaign" :padded="true">
                <p class="text-caption">Status: <x-badge variant="neutral">{{ ucfirst($campaign->status->value) }}</x-badge></p>
                @if($campaign->context)
                    <p class="mb-2">{{ $campaign->context }}</p>
                @endif

                @if($campaign->status->value !== 'draft')
                    @if($campaign->isManaged())
                        @if(in_array($campaign->status->value, ['active', 'paused'], true))
                            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}" class="mb-1">
                                @csrf
                                @if($campaign->status->value === 'active')
                                    <input type="hidden" name="status" value="paused">
                                    <x-button type="submit" variant="outline" size="sm">Pause campaign</x-button>
                                @else
                                    <input type="hidden" name="status" value="active">
                                    <x-button type="submit" variant="primary" size="sm">Resume campaign</x-button>
                                @endif
                            </form>
                            <form method="post"
                                  action="{{ route('customer.workspaces.prospecting.campaigns.end', [$workspaceUid, $campaign->uid]) }}"
                                  onsubmit="return confirm('Permanently end this campaign? No more automatic messages will be sent, and its recipients can be used in another campaign. Message history is preserved. This cannot be undone.');">
                                @csrf
                                <x-button type="submit" variant="outline" size="sm">End campaign</x-button>
                                <p class="text-caption mt-50 mb-0">Stops this campaign and releases its contacts for new campaigns. Keeps SMS history.</p>
                            </form>
                        @else
                            <p class="text-caption mb-0">This campaign has ended permanently. No more messages will be sent from it. Its contacts are available for a new campaign; SMS history is preserved.</p>
                        @endif
                    @else
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.status', [$workspaceUid, $campaign->uid]) }}" class="d-flex gap-2">
                            @csrf
                            <select name="status" class="form-select" style="max-width: 200px;">
                                <option value="active" @selected($campaign->status->value === 'active')>Active</option>
                                <option value="paused" @selected($campaign->status->value === 'paused')>Paused</option>
                            </select>
                            <x-button type="submit" variant="primary" size="sm">Update status</x-button>
                        </form>
                    @endif
                @endif
            </x-card>

            @if($campaign->isManaged() && $readiness !== null)
                <x-card title="Ready to send?" :padded="true" class="mt-2" data-role="campaign-readiness">
                    <ul class="list-unstyled mb-0">
                        @foreach($readiness->items() as $item)
                            <li class="mb-1" data-readiness="{{ $item['key'] }}" data-ok="{{ $item['ok'] ? '1' : '0' }}">
                                <x-badge :variant="$item['ok'] ? 'success' : 'warning'">{{ $item['ok'] ? 'Ready' : 'Needs attention' }}</x-badge>
                                <strong>{{ $item['label'] }}</strong>
                                @if(! $item['ok'])
                                    <span class="d-block text-caption">{{ $item['reason'] }}
                                        @if(! empty($item['url']))<a href="{{ $item['url'] }}">Open</a>@endif
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            @if($campaign->status->value === 'draft')
                @if($campaign->isManaged())
                    <x-card title="First text &amp; start" :padded="true" class="mt-2">
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.config', [$workspaceUid, $campaign->uid]) }}">
                            @csrf
                            <div class="mb-1">
                                <label class="form-label" for="opening_message">First text</label>
                                <textarea id="opening_message" name="opening_message" class="form-control" rows="3">{{ old('opening_message', $campaign->opening_message) }}</textarea>
                            </div>
                            <x-button type="submit" variant="outline" size="sm">Save first text</x-button>
                        </form>

                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.start', [$workspaceUid, $campaign->uid]) }}" class="mt-2">
                            @csrf
                            <x-button type="submit" variant="primary">Start campaign</x-button>
                        </form>
                    </x-card>
                @else
                <x-card title="Configure &amp; start" :padded="true" class="mt-2">
                    <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.config', [$workspaceUid, $campaign->uid]) }}">
                        @csrf
                        <div class="mb-1">
                            <label class="form-label" for="channel_uid">Channel</label>
                            <select id="channel_uid" name="channel_uid" class="form-select">
                                <option value="">Select a channel…</option>
                                @foreach($availableChannels as $availableChannel)
                                    <option value="{{ $availableChannel->uid }}" @selected($campaign->channel_id === $availableChannel->id)>
                                        {{ $availableChannel->provider }} — {{ $availableChannel->sender_number }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-1">
                            <label class="form-label" for="opening_message">Opening message</label>
                            <textarea id="opening_message" name="opening_message" class="form-control" rows="3" placeholder="Hi @{{contact_name}}, this is @{{agency_name}}...">{{ old('opening_message', $campaign->opening_message) }}</textarea>
                            <p class="text-caption mb-0">Placeholders: @{{company_name}}, @{{contact_name}}, @{{agency_name}}</p>
                        </div>
                        <x-button type="submit" variant="outline" size="sm">Save configuration</x-button>
                    </form>

                    <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.start', [$workspaceUid, $campaign->uid]) }}" class="mt-2">
                        @csrf
                        <x-button type="submit" variant="primary">Start campaign</x-button>
                    </form>
                </x-card>
                @endif
            @endif

            @if($campaign->status->value === 'draft')
                <x-card :title="$campaign->usesContactGroup() ? 'Add one prospect (optional)' : 'Enroll a prospect'" :padded="true" class="mt-2">
                    @if($enrollableProspects->isEmpty())
                        <p class="text-caption mb-0">No eligible prospects to enroll.</p>
                    @else
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.members.store', [$workspaceUid, $campaign->uid]) }}" class="d-flex gap-2">
                            @csrf
                            <select name="prospect_uid" class="form-select">
                                @foreach($enrollableProspects as $prospect)
                                    <option value="{{ $prospect->uid }}">{{ $prospect->company_name }} ({{ $prospect->phone }})</option>
                                @endforeach
                            </select>
                            <x-button type="submit" variant="primary" size="sm">Enroll</x-button>
                        </form>
                    @endif
                </x-card>
            @else
                <x-card title="Enrolled prospects are frozen" :padded="true" class="mt-2">
                    <p class="text-caption mb-0">This campaign has started — its enrolled prospect set can no longer be changed.</p>
                </x-card>
            @endif
        </div>

        <div class="col-md-6 mb-2">
            @if($campaign->isManaged())
                @php
                    $isDraft = $campaign->status->value === 'draft';
                    $report = $campaign->recipient_report ?? [];
                    $groupLinkOk = $linkedGroup !== null && $groupBusiness !== null;
                @endphp
                <x-card title="Recipients" :padded="true" class="mb-2" data-role="campaign-recipients">
                    @if($campaign->usesContactGroup())
                        @if($linkedGroup === null)
                            <x-alert variant="warning" data-role="group-missing">This campaign's contact group is no longer available. Choose another group before starting.</x-alert>
                        @else
                            <p class="mb-1" data-role="linked-group">
                                <strong>{{ $linkedGroup->name }}</strong>
                                <span class="text-caption d-block">{{ number_format($linkedGroupCount) }} {{ \Illuminate\Support\Str::plural('contact', $linkedGroupCount) }} in group</span>
                                @if($groupBusiness !== null)
                                    <a href="{{ route('customer.workspaces.businesses.contacts.show', [$workspaceUid, $groupBusiness->uid, $linkedGroup->uid]) }}">Manage this group in Contacts</a>
                                @endif
                            </p>
                        @endif

                        <ul class="mb-1" data-role="recipient-counts">
                            <li data-role="count-enrolled">{{ $members->count() }} enrolled {{ \Illuminate\Support\Str::plural('recipient', $members->count()) }}</li>
                            @if(isset($report['group_total']))
                                <li data-role="count-excluded">{{ $report['excluded'] ?? 0 }} of {{ $report['group_total'] }} contacts excluded at last sync</li>
                            @endif
                        </ul>

                        @if($campaign->recipients_synced_at)
                            <p class="text-caption">Recipient list captured {{ $campaign->recipients_synced_at->diffForHumans() }}.
                                @unless($isDraft) It is frozen — changes to the group no longer affect this campaign. @endunless</p>
                        @endif

                        @if(! empty($report['reasons']))
                            <details class="mb-1" data-role="excluded-contacts">
                                <summary>Excluded contacts</summary>
                                <ul class="mb-0">
                                    @foreach($report['reasons'] as $reason => $count)
                                        <li>{{ \App\Library\AgencyOutreach\OutreachContactGroupBridge::reasonLabel($reason) }}: {{ $count }}</li>
                                    @endforeach
                                </ul>
                                @if(! empty($report['skipped']))
                                    <table class="table table-sm mt-1">
                                        <tbody>
                                            @foreach($report['skipped'] as $row)
                                                <tr><td>{{ $row['phone'] !== '' ? $row['phone'] : '(blank)' }}</td><td>{{ \App\Library\AgencyOutreach\OutreachContactGroupBridge::reasonLabel($row['reason']) }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if(count($report['skipped']) < ($report['excluded'] ?? 0))
                                        <p class="text-caption mb-0">Showing the first {{ count($report['skipped']) }}.</p>
                                    @endif
                                @endif
                            </details>
                        @endif

                        @if($isDraft && $groupLinkOk)
                            <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.recipients.refresh', [$workspaceUid, $campaign->uid]) }}" class="mb-2">
                                @csrf
                                <x-button type="submit" variant="outline" size="sm">Refresh recipients</x-button>
                                <span class="text-caption">Re-reads the group. Nothing is sent.</span>
                            </form>
                        @endif
                    @else
                        <p class="text-caption mb-1" data-role="legacy-recipients">This campaign was created before contact groups. Its recipients are the prospects enrolled individually.</p>
                    @endif

                    @if($isDraft && $groupBusiness !== null)
                        <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.recipients.group', [$workspaceUid, $campaign->uid]) }}" data-role="change-group"
                              onsubmit="return confirm('Changing the group replaces this campaign\'s group recipients with the new group\'s eligible contacts. Prospects you added individually are kept. Nothing is sent.');">
                            @csrf
                            <label class="form-label" for="change_group">{{ $campaign->usesContactGroup() ? 'Change group' : 'Use a contact group' }}</label>
                            <div class="d-flex gap-1">
                                <select id="change_group" name="contact_group_uid" class="form-select" required>
                                    <option value="">Select a group…</option>
                                    @foreach($contactGroups as $group)
                                        <option value="{{ $group->uid }}" @selected($linkedGroup?->id === $group->id)>{{ $group->name }} ({{ $group->subscribers_count }})</option>
                                    @endforeach
                                </select>
                                <x-button type="submit" variant="outline" size="sm">Apply</x-button>
                            </div>
                            <div class="form-check mt-50">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm_change" required>
                                <label class="form-check-label text-caption" for="confirm_change">I understand the group's recipients will be replaced by the new group's current eligible contacts.</label>
                            </div>
                        </form>
                    @elseif(! $isDraft)
                        <p class="text-caption mb-0">The group can no longer be changed because this campaign has started.</p>
                    @endif
                </x-card>
            @endif

            <x-card title="Enrolled prospects" :padded="true">
                @if($members->isEmpty())
                    <p class="text-caption mb-0">No prospects enrolled yet.</p>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach($members as $member)
                            <li class="mb-1 d-flex justify-content-between align-items-center">
                                <a href="{{ route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $member->prospect->uid]) }}">{{ $member->prospect->company_name }}</a>
                                <x-badge variant="neutral">{{ \App\Library\AgencyOutreach\OutreachProspectStatusPresenter::stageLabel($member->stage) }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
