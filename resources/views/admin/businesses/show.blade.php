@extends('layouts/contentLayoutMaster')

@section('title', $business->name)

@section('content')
    <section id="admin-business-show">
        <div class="row">
            <div class="col-12">
                <x-card title="{{ $business->name }}">
                    <x-slot:actions>
                        @can('edit business')
                            <x-button :href="route('admin.businesses.edit', $business)" variant="primary" size="sm">Edit</x-button>
                        @endcan
                        <x-button :href="route('admin.businesses.usage-billing.show', $business)" variant="outline" size="sm">Usage Billing</x-button>
                    </x-slot:actions>

                    @if (session('status'))
                        <x-alert variant="{{ session('status') === 'success' ? 'success' : 'danger' }}">
                            {{ session('message') }}
                        </x-alert>
                    @endif

                    <dl class="row">
                        <dt class="col-sm-3">Owner</dt>
                        <dd class="col-sm-9">{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</dd>

                        <dt class="col-sm-3">Industry</dt>
                        <dd class="col-sm-9">{{ ucfirst(str_replace('_', ' ', $business->industry?->value ?? '')) }}</dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">{{ ucfirst($business->status->value) }}</dd>

                        <dt class="col-sm-3">Email</dt>
                        <dd class="col-sm-9">{{ $business->email ?? '—' }}</dd>

                        <dt class="col-sm-3">Phone</dt>
                        <dd class="col-sm-9">{{ $business->phone ?? '—' }}</dd>

                        <dt class="col-sm-3">Website</dt>
                        <dd class="col-sm-9">{{ $business->website_url ?? '—' }}</dd>

                        <dt class="col-sm-3">Country / Timezone</dt>
                        <dd class="col-sm-9">{{ $business->country_code }} / {{ $business->timezone }}</dd>

                        <dt class="col-sm-3">Primary location</dt>
                        <dd class="col-sm-9">
                            @if ($business->primaryLocation)
                                {{ collect([$business->primaryLocation->city, $business->primaryLocation->region, $business->primaryLocation->country_code])->filter()->implode(', ') }}
                            @else
                                Not set
                            @endif
                        </dd>

                        <dt class="col-sm-3">Active services</dt>
                        <dd class="col-sm-9">
                            {{ $business->services->where('status', \App\Enums\Business\BusinessServiceStatus::Active)->count() }}
                        </dd>

                        <dt class="col-sm-3">Onboarding status</dt>
                        <dd class="col-sm-9">
                            @if ($onboarding)
                                {{ ucfirst(str_replace('_', ' ', $onboarding->status->value)) }} (step: {{ $onboarding->current_step->value }})
                                @if ($onboarding->analysis_error)
                                    <br><span class="text-danger">{{ $onboarding->analysis_error }}</span>
                                @endif
                            @else
                                Not started
                            @endif
                        </dd>
                    </dl>

                    @can('edit business')
                        <hr>
                        <h5>Change status</h5>
                        <form method="POST" action="{{ route('admin.businesses.status.update', $business) }}" class="d-flex flex-wrap gap-2 align-items-start">
                            @csrf
                            @method('PATCH')
                            <x-select
                                name="status"
                                class="w-auto"
                                :options="collect(\App\Enums\Business\BusinessStatus::cases())->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->value)])->all()"
                                :selected="$business->status->value"
                            />
                            <input type="text" name="reason" class="form-control w-auto" maxlength="1000" placeholder="Reason (required for Inactive)" value="{{ old('reason') }}" aria-label="Reason">
                            <x-button type="submit" variant="outline">Update status</x-button>
                        </form>
                        @error('reason') <div class="text-danger mt-1">{{ $message }}</div> @enderror
                        <p class="text-muted small mt-1 mb-0">Recorded in the audit trail with your name. Deactivating requires a reason.</p>
                    @endcan
                </x-card>
            </div>
        </div>
    </section>

    {{-- Platform Owner / Admin V1 — the support view of this Business. --}}
    @if ($support !== null)
        <section id="admin-business-support">
            <div class="row">
                <div class="col-12">
                    @if (session('po_flash_success'))
                        <x-alert variant="success" role="status">{{ session('po_flash_success') }}</x-alert>
                    @endif
                    @if (session('po_flash_error'))
                        <x-alert variant="danger">{{ session('po_flash_error') }}</x-alert>
                    @endif

                    <p class="mb-2">
                        Workspace:
                        @can('view workspace')
                            <a href="{{ route('admin.workspaces.show', $support['workspace']) }}">{{ $support['workspace']->name }}</a>
                        @else
                            {{ $support['workspace']->name }}
                        @endcan
                    </p>

                    @include('admin.platform-owner.partials.access')

                    <x-card title="Locations">
                        @if ($locations->isEmpty())
                            <x-empty-state icon="inbox" title="This Business has no Locations." />
                        @else
                            <x-table :headers="['Location', 'Place', 'State']">
                                @foreach ($locations as $location)
                                    <tr data-testid="po-location-row">
                                        <td>{{ $location->name ?? 'Unnamed' }} @if ($location->is_primary) <x-badge variant="accent">Primary</x-badge> @endif</td>
                                        <td>{{ collect([$location->city, $location->region, $location->country_code])->filter()->implode(', ') ?: '—' }}</td>
                                        <td>{{ ucfirst($location->lifecycle_state?->value ?? 'active') }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                            <p class="text-muted mt-2 mb-0">Up to {{ \App\Library\PlatformOwner\WorkspaceSupportReader::MAX_LOCATIONS_PER_BUSINESS }} Locations are listed.</p>
                        @endif
                    </x-card>

                    @include('admin.platform-owner.partials.subscription')
                    @include('admin.platform-owner.partials.agency')
                    @include('admin.platform-owner.partials.businesses')
                    @include('admin.platform-owner.partials.audit', ['rows' => $support['recentActions'], 'actors' => $actors])
                </div>
            </div>
        </section>
    @endif
@endsection
