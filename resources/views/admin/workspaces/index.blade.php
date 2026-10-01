@extends('layouts/contentLayoutMaster')

@section('title', 'Workspaces')

@section('content')
    <section id="admin-workspaces-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Workspaces">
                    <form method="GET" action="{{ route('admin.workspaces.index') }}" class="row g-2 mb-3">
                        <div class="col-md-6">
                            <x-input name="search" type="text" placeholder="Search workspace, business, owner/member email, uid, Stripe customer or subscription id" value="{{ $filters['search'] }}" />
                        </div>
                        <div class="col-md-4">
                            <x-select
                                name="is_active"
                                :options="['' => 'All', '1' => 'Active', '0' => 'Inactive']"
                                :selected="$filters['is_active'] === null ? '' : ($filters['is_active'] ? '1' : '0')"
                            />
                        </div>
                        <div class="col-md-2">
                            <x-button type="submit" variant="primary" class="w-100">Filter</x-button>
                        </div>
                    </form>

                    <x-table :headers="['Name', 'Owner', 'Status', 'Plan', 'Subscription', 'Businesses', 'Active members', '']">
                        @forelse ($workspaces as $workspace)
                            @php
                                $assignment = $page['assignments']->get($workspace->id);
                                $subscription = $page['subscriptions']->get($workspace->id);
                            @endphp
                            <tr>
                                <td>{{ $workspace->name }}<div class="text-muted small">{{ $workspace->uid }}</div></td>
                                <td>{{ $workspace->owner?->displayName() ?? 'Unknown' }}<div class="text-muted small">{{ $workspace->owner?->email }}</div></td>
                                <td>
                                    @if ($workspace->is_active)
                                        <x-badge variant="success">Active</x-badge>
                                    @else
                                        <x-badge variant="neutral">Inactive</x-badge>
                                    @endif
                                </td>
                                <td data-testid="po-index-plan">
                                    @if ($assignment)
                                        {{ $assignment->catalog?->display_name ?? '—' }}
                                        <div class="text-muted small">{{ $assignment->status->value }}@if ($assignment->locked_at) · locked @elseif ($assignment->grace_started_at) · grace @endif</div>
                                    @else
                                        <span class="text-muted">No plan</span>
                                    @endif
                                </td>
                                <td data-testid="po-index-subscription">{{ $subscription?->status?->value ?? '—' }}</td>
                                <td>{{ $workspace->businesses_count }}</td>
                                <td>{{ $workspace->active_memberships_count }}</td>
                                <td>
                                    <a href="{{ route('admin.workspaces.show', $workspace) }}">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8"><x-empty-state icon="inbox" title="No workspaces found." /></td>
                            </tr>
                        @endforelse
                    </x-table>

                    <x-pagination :paginator="$workspaces->appends(array_filter($filters, fn ($value) => $value !== null))" />
                </x-card>
            </div>
        </div>
    </section>
@endsection
