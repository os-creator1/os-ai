@extends('layouts/contentLayoutMaster')

{{--
    One CRM deal: its details, where it is, whether the Business is in contact,
    won / lost / reopen, and its history (crm_opportunity_history, newest first).
    Every change is an ordinary form post, so it all works from the keyboard and
    without JavaScript; the board's drag and drop uses the same move endpoint.
--}}

@section('title', $opportunity->title)

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $canManage = Gate::allows(\App\Http\Controllers\Customer\Business\CrmOpportunitiesController::MANAGE_PERMISSION);
        $routeArgs = [$workspaceUid, $businessUid, $opportunity->uid];
        $Status = \App\Enums\Crm\CrmOpportunityStatus::class;
        $ContactStatus = \App\Enums\Crm\CrmContactStatus::class;
    @endphp

    <section id="crm-opportunity" data-role="crm-opportunity">
        <div class="mb-1">
            <a href="{{ route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid]) }}">&larr; {{ $pipeline->name }}</a>
        </div>

        <div class="row">
            <div class="col-12 col-lg-5">
                <x-card>
                    <h1 class="h3 mb-50" data-role="crm-opportunity-title">{{ $opportunity->title }}</h1>
                    <div class="d-flex flex-wrap gap-50 mb-2">
                        <x-badge :variant="$opportunity->status === $Status::Open ? 'accent' : ($opportunity->status === $Status::Won ? 'success' : 'danger')" data-role="crm-opportunity-status">{{ $opportunity->status->label() }}</x-badge>
                        <x-badge :variant="$opportunity->contact_status === $ContactStatus::InContact ? 'success' : 'warning'" data-role="crm-opportunity-contact-status">{{ $opportunity->contact_status->label() }}</x-badge>
                    </div>

                    <dl class="row mb-0">
                        <dt class="col-5">Contact</dt>
                        <dd class="col-7">
                            @if ($contact !== null)
                                <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contact['uid']]) }}" data-role="crm-opportunity-contact">{{ $contact['name'] ?? $contact['phone'] }}</a>
                            @else
                                <span class="text-muted">Contact removed</span>
                            @endif
                        </dd>
                        <dt class="col-5">Stage</dt>
                        <dd class="col-7" data-role="crm-opportunity-stage">{{ $stage->name }}@if ($stage->isArchived()) <span class="text-muted">(archived)</span>@endif</dd>
                        <dt class="col-5">Value</dt>
                        <dd class="col-7 text-numeric">{{ \App\Library\Crm\CrmMoney::format($opportunity->value_minor, $opportunity->currency_code) ?? '—' }}</dd>
                        @if ($opportunity->status === $Status::Lost && $opportunity->lost_reason)
                            <dt class="col-5">Lost because</dt>
                            <dd class="col-7">{{ $opportunity->lost_reason }}</dd>
                        @endif
                        <dt class="col-5">Added</dt>
                        <dd class="col-7">{{ $opportunity->created_at?->format('M j, Y') }}</dd>
                    </dl>
                </x-card>

                @if ($canManage)
                    <x-card title="Update">
                        @if ($opportunity->isOpen())
                            <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.move', $routeArgs) }}" class="d-flex gap-1 align-items-end mb-2" data-role="crm-move-form">
                                @csrf
                                <div class="flex-grow-1">
                                    <label for="crm-move-stage" class="form-label text-label">Move to stage</label>
                                    <select id="crm-move-stage" name="stage" class="form-select">
                                        @foreach ($stages as $option)
                                            <option value="{{ $option->uid }}" @selected($option->is($stage))>{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-button type="submit" variant="outline">Move</x-button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.contact-status', $routeArgs) }}" class="d-flex gap-1 align-items-end mb-2" data-role="crm-contact-status-form">
                            @csrf
                            <div class="flex-grow-1">
                                <label for="crm-contact-status-value" class="form-label text-label">Contact status</label>
                                <select id="crm-contact-status-value" name="contact_status" class="form-select">
                                    @foreach ($ContactStatus::cases() as $option)
                                        <option value="{{ $option->value }}" @selected($opportunity->contact_status === $option)>{{ $option->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-button type="submit" variant="outline">Save</x-button>
                        </form>

                        <div class="d-flex flex-wrap gap-1" data-role="crm-outcome-actions">
                            @if ($opportunity->isOpen())
                                <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.won', $routeArgs) }}">
                                    @csrf
                                    <x-button type="submit" variant="primary" icon="trophy">Mark won</x-button>
                                </form>
                                <details data-role="crm-lost">
                                    <summary class="btn btn-outline-danger">Mark lost</summary>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.lost', $routeArgs) }}" class="mt-1">
                                        @csrf
                                        <label for="crm-lost-reason" class="form-label text-label">Reason (optional)</label>
                                        <input id="crm-lost-reason" name="lost_reason" class="form-control mb-1" maxlength="255">
                                        <x-button type="submit" variant="danger" size="sm">Mark lost</x-button>
                                    </form>
                                </details>
                            @else
                                <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.reopen', $routeArgs) }}">
                                    @csrf
                                    <x-button type="submit" variant="outline">Reopen</x-button>
                                </form>
                            @endif
                        </div>
                    </x-card>

                    <x-card title="Details">
                        <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.update', $routeArgs) }}" data-role="crm-details-form">
                            @csrf
                            <x-input name="title" label="Opportunity name" maxlength="150" required :value="old('title', $opportunity->title)" :error="$errors->first('title')" />
                            <x-input name="value" type="number" label="Value" min="0" step="0.01" :value="old('value', \App\Library\Crm\CrmMoney::toInput($opportunity->value_minor))" :error="$errors->first('value')" />
                            <x-button type="submit" variant="outline">Save details</x-button>
                        </form>
                    </x-card>
                @endif
            </div>

            <div class="col-12 col-lg-7">
                <x-card title="History">
                    <ol class="list-unstyled mb-0" data-role="crm-history">
                        @foreach ($history as $entry)
                            <li class="d-flex justify-content-between gap-1 py-50 border-bottom" data-role="crm-history-entry" data-event="{{ $entry->event->value }}">
                                @php $Event = \App\Enums\Crm\CrmOpportunityHistoryEvent::class; @endphp
                                <span>
                                    @if ($entry->event === $Event::Created)
                                        Added in <strong>{{ $entry->to_stage_name }}</strong>
                                    @elseif ($entry->event === $Event::StageChanged)
                                        Moved from <strong>{{ $entry->from_stage_name }}</strong> to <strong>{{ $entry->to_stage_name }}</strong>
                                    @elseif ($entry->event === $Event::Won)
                                        Marked <strong>won</strong>
                                    @elseif ($entry->event === $Event::Lost)
                                        Marked <strong>lost</strong>
                                    @elseif ($entry->event === $Event::Reopened && $entry->to_stage_name)
                                        Reopened in <strong>{{ $entry->to_stage_name }}</strong> (its stage was archived)
                                    @elseif ($entry->event === $Event::Reopened)
                                        Reopened
                                    @elseif ($entry->event === $Event::ContactStatusChanged)
                                        Contact status set to <strong>{{ \App\Enums\Crm\CrmContactStatus::tryFrom((string) $entry->to_value)?->label() ?? $entry->to_value }}</strong>
                                    @endif
                                    @if ($entry->actor)
                                        <span class="text-caption text-muted">· {{ trim($entry->actor->first_name . ' ' . $entry->actor->last_name) }}</span>
                                    @endif
                                </span>
                                <time class="text-caption text-muted text-nowrap" datetime="{{ $entry->created_at?->toIso8601String() }}">{{ $entry->created_at?->format('M j, Y g:i A') }}</time>
                            </li>
                        @endforeach
                    </ol>
                </x-card>
            </div>
        </div>

        {{-- Revenue journey: offer a booking, send a proposal, see what is attached. Every action posts to the canonical
             Calendar / Documents endpoints, which keep their own permission, entitlement and Location checks. --}}
        <div class="row mt-1" data-role="crm-revenue">
            <div class="col-12">
                <x-card>
                    <h2 class="h5 mb-1">Booking, proposal and payment</h2>
                    @if ($opportunity->status === $Status::Open && $canManage && ($revenue['contactUid'] ?? null) && ($revenue['locationUid'] ?? null))
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <a class="btn btn-outline-primary" data-role="crm-offer-booking"
                               href="{{ route('customer.workspaces.businesses.calendar.appointments.create', [$workspaceUid, $businessUid, $revenue['locationUid'], 'contact_uid' => $revenue['contactUid'], 'opportunity_uid' => $opportunity->uid]) }}">Book an appointment</a>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.documents.store', [$workspaceUid, $businessUid]) }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="contact_uid" value="{{ $revenue['contactUid'] }}">
                                <input type="hidden" name="opportunity_uid" value="{{ $opportunity->uid }}">
                                <input type="hidden" name="kind" value="proposal">
                                <input type="hidden" name="via" value="editor">
                                <input type="hidden" name="title" value="{{ \Illuminate\Support\Str::limit('Proposal: ' . $opportunity->title, 200, '') }}">
                                <button type="submit" class="btn btn-outline-primary" data-role="crm-new-proposal">Create a proposal</button>
                            </form>
                        </div>
                    @endif

                    @if (($revenue['documents'] ?? collect())->isEmpty() && ($revenue['appointments'] ?? collect())->isEmpty())
                        <p class="text-muted mb-0" data-role="crm-revenue-empty">No appointment or proposal is attached to this deal yet.</p>
                    @else
                        <ul class="list-unstyled mb-0" data-role="crm-revenue-list">
                            @foreach ($revenue['documents'] as $doc)
                                <li data-role="crm-revenue-document">
                                    <a href="{{ route('customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $doc->uid]) }}">{{ $doc->title }}</a>
                                    <span class="text-muted">· {{ ucfirst($doc->kind->value) }} · {{ ucfirst($doc->status->value) }}</span>
                                    @if ($doc->status === \App\Enums\Documents\DocumentStatus::Paid && $opportunity->status === $Status::Open)
                                        <span class="text-success">· Paid in full — mark the deal won above</span>
                                    @endif
                                </li>
                            @endforeach
                            @foreach ($revenue['appointments'] as $appt)
                                <li data-role="crm-revenue-appointment">
                                    Appointment · {{ \Carbon\Carbon::parse($appt->start_at)->format('M j, Y g:i A') }} UTC
                                    <span class="text-muted">· {{ ucfirst(str_replace('_', ' ', $appt->status->value ?? $appt->status)) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
