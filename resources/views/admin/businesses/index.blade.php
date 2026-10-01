@extends('layouts/contentLayoutMaster')

@section('title', 'Businesses')

@section('content')
    <section id="admin-businesses-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Businesses">
                    <form method="GET" action="{{ route('admin.businesses.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <x-input name="search" type="text" placeholder="Search name, uid, email, domain" value="{{ $filters['search'] }}" />
                        </div>
                        <div class="col-md-3">
                            <x-select
                                name="status"
                                :options="['' => 'All statuses'] + collect($statuses)->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->value)])->all()"
                                :selected="$filters['status'] ?? ''"
                            />
                        </div>
                        <div class="col-md-3">
                            <x-select
                                name="industry"
                                :options="['' => 'All industries'] + collect($industries)->mapWithKeys(fn ($industry) => [$industry->value => ucfirst(str_replace('_', ' ', $industry->value))])->all()"
                                :selected="$filters['industry'] ?? ''"
                            />
                        </div>
                        <div class="col-md-2">
                            <x-button type="submit" variant="primary" class="w-100">Filter</x-button>
                        </div>
                    </form>

                    <x-table :headers="['Name', 'Owner', 'Workspace', 'Industry', 'Status', '']">
                        @forelse ($businesses as $business)
                            <tr>
                                <td>{{ $business->name }}<div class="text-muted small">{{ $business->uid }}</div></td>
                                <td>{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</td>
                                <td>
                                    @if ($business->workspace)
                                        @can('view workspace')
                                            <a href="{{ route('admin.workspaces.show', $business->workspace) }}">{{ $business->workspace->name }}</a>
                                        @else
                                            {{ $business->workspace->name }}
                                        @endcan
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ ucfirst(str_replace('_', ' ', $business->industry?->value ?? '')) }}</td>
                                <td>{{ ucfirst($business->status->value) }}</td>
                                <td>
                                    <a href="{{ route('admin.businesses.show', $business) }}">View</a>
                                    @can('edit business')
                                        | <a href="{{ route('admin.businesses.edit', $business) }}">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-empty-state icon="inbox" title="No businesses found." /></td>
                            </tr>
                        @endforelse
                    </x-table>

                    <x-pagination :paginator="$businesses->appends(array_filter($filters))" />
                </x-card>
            </div>
        </div>
    </section>
@endsection
