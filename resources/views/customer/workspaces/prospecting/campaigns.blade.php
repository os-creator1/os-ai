@extends('layouts/contentLayoutMaster')

@section('title', 'Outreach campaigns')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'campaigns'])

    <div class="row">
        <div class="col-md-4 mb-2">
            <x-card title="Create a campaign" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.campaigns.managed.store', $workspaceUid) }}">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="opening_message">First text</label>
                        <textarea id="opening_message" name="opening_message" class="form-control @error('opening_message') is-invalid @enderror" rows="4">{{ old('opening_message', $defaultOpener) }}</textarea>
                        @error('opening_message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <p class="text-caption mb-0">The first text each prospect gets. It starts from your Message 1; you can change it here.</p>
                    </div>
                    <fieldset class="mb-1" data-role="recipients-section">
                        <legend class="form-label">Recipients <span class="text-danger">*</span></legend>
                        <p class="text-caption">Every campaign texts a contact group. Choose one or create a new group.</p>
                        @if(! $groupBusinessReady)
                            <x-alert variant="warning">Your Outreach business is not set up yet, so contact groups are unavailable.</x-alert>
                        @endif
                        @php $mode = old('group_mode', $contactGroups->isEmpty() ? 'new' : 'existing'); @endphp
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="group_mode" id="group_mode_existing" value="existing" @checked($mode === 'existing')>
                            <label class="form-check-label" for="group_mode_existing">Select existing group</label>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="group_mode" id="group_mode_new" value="new" @checked($mode === 'new')>
                            <label class="form-check-label" for="group_mode_new">Create new group</label>
                        </div>
                        @error('group_mode')<div class="text-danger small mb-1">{{ $message }}</div>@enderror

                        <div data-role="group-existing" @if($mode !== 'existing') hidden @endif>
                            <input type="search" class="form-control mb-50" data-role="group-search" placeholder="Search groups…" aria-label="Search groups" autocomplete="off">
                            <select name="contact_group_uid" id="contact_group_uid" class="form-select @error('contact_group_uid') is-invalid @enderror"
                                    data-summary-url="{{ route('customer.workspaces.prospecting.recipient-groups.summary', $workspaceUid) }}">
                                <option value="">Select a group…</option>
                                @foreach($contactGroups as $group)
                                    <option value="{{ $group->uid }}" data-name="{{ \Illuminate\Support\Str::lower($group->name) }}" @selected(old('contact_group_uid') === $group->uid)>
                                        {{ $group->name }} ({{ number_format($group->subscribers_count) }} {{ \Illuminate\Support\Str::plural('contact', $group->subscribers_count) }})
                                    </option>
                                @endforeach
                            </select>
                            @error('contact_group_uid')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div data-role="group-new" @if($mode !== 'new') hidden @endif>
                            <input type="text" name="new_group_name" id="new_group_name" class="form-control @error('new_group_name') is-invalid @enderror"
                                   value="{{ old('new_group_name') }}" placeholder="New group name" maxlength="255">
                            @error('new_group_name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <p class="text-caption mb-0">Creates an empty group in Contacts. Add contacts to it before you start the campaign.</p>
                        </div>

                        <div class="mt-1" data-role="recipient-summary" hidden aria-live="polite"></div>
                    </fieldset>
                    <div class="mb-1">
                        <label class="form-label" for="context">Notes (only you see these)</label>
                        <textarea id="context" name="context" class="form-control" rows="2">{{ old('context') }}</textarea>
                    </div>
                    <x-button type="submit" variant="primary">Create draft campaign</x-button>
                </form>
            </x-card>
        </div>

        <div class="col-md-8 mb-2">
            <x-card title="Campaigns" :padded="true">
                @if($pausedForFunds)
                    <x-alert variant="warning" data-role="paused-for-funds">
                        <div class="d-flex justify-content-between align-items-center w-100 gap-2">
                            <span>Paused — add funds. Some texts are waiting for your balance.</span>
                            <form method="post" action="{{ route('customer.workspaces.prospecting.sending.resume', $workspaceUid) }}">
                                @csrf
                                <x-button type="submit" variant="primary" size="sm">Resume sending</x-button>
                            </form>
                        </div>
                    </x-alert>
                @endif

                @if($campaigns->isEmpty())
                    <x-empty-state icon="send" title="No campaigns yet"
                                    description="Create a draft campaign to start organizing prospects." />
                @else
                    <div class="table-responsive">
                        <table class="table" data-role="campaigns-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Prospects</th>
                                    <th>Sending number</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Replies</th>
                                    <th>Booked calls</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($campaigns as $campaign)
                                    @php $summary = $summaries[$campaign->id]; @endphp
                                    <tr data-campaign="{{ $campaign->uid }}">
                                        <td>{{ $campaign->name }}</td>
                                        <td>{{ $summary['prospects'] }}</td>
                                        <td>{{ $campaign->isManaged() ? ($sendingNumber ?? '—') : 'Own channel' }}</td>
                                        <td>
                                            <x-badge variant="neutral">{{ ucfirst($campaign->status->value) }}</x-badge>
                                            @if($summary['paused_for_funds'])
                                                <x-badge variant="warning">Paused — add funds</x-badge>
                                            @endif
                                        </td>
                                        <td>{{ $campaign->created_at?->format('M j, Y') }}</td>
                                        <td>{{ $summary['replies'] }}</td>
                                        <td>{{ $summary['booked'] }}</td>
                                        <td>
                                            <x-button variant="outline" size="sm"
                                                      :href="route('customer.workspaces.prospecting.campaigns.show', [$workspaceUid, $campaign->uid])">
                                                Manage
                                            </x-button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="text-caption mt-2 mb-0">
                    Campaigns created before Outreach use your own connected channel.
                    <a href="{{ route('customer.workspaces.prospecting.channels.index', $workspaceUid) }}">Manage connected channels</a>
                </p>
            </x-card>
        </div>
    </div>
@endsection

@section('page-script')
<script>
(function () {
    var form = document.getElementById('contact_group_uid');
    if (!form) { return; }
    var root = document.querySelector('[data-role="recipients-section"]');
    var existing = root.querySelector('[data-role="group-existing"]');
    var created = root.querySelector('[data-role="group-new"]');
    var summary = root.querySelector('[data-role="recipient-summary"]');
    var search = root.querySelector('[data-role="group-search"]');
    var token = 0;

    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    function mode() { return root.querySelector('input[name="group_mode"]:checked'); }

    function sync() {
        var m = mode() ? mode().value : 'existing';
        existing.hidden = m !== 'existing';
        created.hidden = m !== 'new';
        if (m === 'new') {
            summary.hidden = false;
            summary.innerHTML = '<ul class="list-unstyled mb-0"><li><strong>0</strong> contacts in group (new, empty)</li></ul><span class="text-caption">Add contacts to it before starting.</span>';
        } else {
            load();
        }
    }

    function load() {
        var uid = form.value;
        if (!uid) { summary.hidden = true; return; }
        var my = ++token;
        summary.hidden = false;
        summary.textContent = 'Calculating…';
        fetch(form.dataset.summaryUrl + '?group=' + encodeURIComponent(uid), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); })
            .then(function (d) {
                if (my !== token) { return; }
                if (d.too_large) { summary.innerHTML = '<span class="text-danger">This group is too large for one campaign. Split it into smaller groups.</span>'; return; }
                var why = d.reasons.map(function (x) { return '<li class="text-caption">' + esc(x.label) + ': ' + x.count + '</li>'; }).join('');
                summary.innerHTML = '<strong>' + esc(d.name) + '</strong><ul class="mb-0">'
                    + '<li data-role="summary-total">' + d.group_total + ' contacts in group</li>'
                    + '<li data-role="summary-eligible">' + d.eligible + ' eligible</li>'
                    + '<li data-role="summary-excluded">' + d.excluded + ' excluded</li></ul>'
                    + (why ? '<ul class="list-unstyled ms-1 mb-0">' + why + '</ul>' : '');
            })
            .catch(function () { if (my === token) { summary.textContent = 'Could not calculate recipients.'; } });
    }

    root.querySelectorAll('input[name="group_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
    form.addEventListener('change', load);
    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        Array.prototype.forEach.call(form.options, function (o) {
            if (!o.value) { return; }
            o.hidden = q !== '' && o.dataset.name.indexOf(q) === -1;
        });
    });
    sync();
})();
</script>
@endsection
