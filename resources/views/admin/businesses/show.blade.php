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
                        <form method="POST" action="{{ route('admin.businesses.status.update', $business) }}" class="d-flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select
                                name="status"
                                class="w-auto"
                                :options="collect(\App\Enums\Business\BusinessStatus::cases())->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->value)])->all()"
                                :selected="$business->status->value"
                            />
                            <x-button type="submit" variant="outline">Update status</x-button>
                        </form>
                    @endcan
                </x-card>
            </div>
        </div>
    </section>
@endsection
