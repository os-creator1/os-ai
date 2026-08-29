@extends('layouts/contentLayoutMaster')

@section('title', $business->name)

@section('content')
    <section id="admin-business-show">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title">{{ $business->name }}</h4>
                        @can('edit business')
                            <a href="{{ route('admin.businesses.edit', $business) }}" class="btn btn-primary btn-sm">Edit</a>
                        @endcan
                        <a href="{{ route('admin.businesses.usage-billing.show', $business) }}" class="btn btn-outline-primary btn-sm">Usage Billing</a>
                    </div>
                    <div class="card-body">
                        @if (session('status'))
                            <div class="alert alert-{{ session('status') === 'success' ? 'success' : 'danger' }}">
                                {{ session('message') }}
                            </div>
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
                                <select name="status" class="form-select w-auto">
                                    @foreach (\App\Enums\Business\BusinessStatus::cases() as $status)
                                        <option value="{{ $status->value }}" @selected($business->status === $status)>
                                            {{ ucfirst($status->value) }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline-primary">Update status</button>
                            </form>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
