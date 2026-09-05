@extends('layouts/contentLayoutMaster')

@section('title', 'Prospects')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects'])

    <div class="row">
        <div class="col-md-4 mb-2">
            <x-card title="Add a prospect" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.store', $workspaceUid) }}">
                    @csrf
                    <div class="mb-1">
                        <label class="form-label" for="company_name">Company name</label>
                        <input type="text" id="company_name" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name') }}">
                        @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="contact_name">Contact name</label>
                        <input type="text" id="contact_name" name="contact_name" class="form-control" value="{{ old('contact_name') }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="website">Website</label>
                        <input type="text" id="website" name="website" class="form-control" value="{{ old('website') }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="source">Source</label>
                        <input type="text" id="source" name="source" class="form-control" value="{{ old('source') }}">
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" value="{{ old('location') }}">
                    </div>
                    <x-button type="submit" variant="primary">Add prospect</x-button>
                </form>
            </x-card>
        </div>

        <div class="col-md-8 mb-2">
            <x-card title="Prospects" :padded="true">
                @if($prospects->isEmpty())
                    <x-empty-state icon="users" title="No prospects yet"
                                    description="Add a prospect manually to get started." />
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($prospects as $prospect)
                                    <tr>
                                        <td>{{ $prospect->company_name }}</td>
                                        <td>{{ $prospect->phone }}</td>
                                        <td>
                                            @if($prospect->status->value === 'active')
                                                <x-badge variant="success">Active</x-badge>
                                            @elseif($prospect->status->value === 'booked')
                                                <x-badge variant="accent">Booked</x-badge>
                                            @else
                                                <x-badge variant="neutral">Stopped</x-badge>
                                            @endif
                                        </td>
                                        <td>
                                            <x-button variant="outline" size="sm"
                                                      :href="route('customer.workspaces.prospecting.prospects.show', [$workspaceUid, $prospect->uid])">
                                                View
                                            </x-button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
@endsection
