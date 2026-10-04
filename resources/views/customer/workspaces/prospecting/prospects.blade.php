@extends('layouts/contentLayoutMaster')

@section('title', 'Prospects')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Outreach</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'prospects'])

    <div class="row">
        <div class="col-12 mb-2">
            <x-card title="Add a prospect" :padded="true">
                <form method="post" action="{{ route('customer.workspaces.prospecting.prospects.store', $workspaceUid) }}">
                    @csrf
                    <div class="row">
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="company_name">Company name</label>
                            <input type="text" id="company_name" name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name') }}">
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="contact_name">Contact name</label>
                            <input type="text" id="contact_name" name="contact_name" class="form-control" value="{{ old('contact_name') }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="website">Website</label>
                            <input type="text" id="website" name="website" class="form-control" value="{{ old('website') }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="source">Source</label>
                            <input type="text" id="source" name="source" class="form-control" value="{{ old('source') }}">
                        </div>
                        <div class="col-md-3 mb-1">
                            <label class="form-label" for="location">Location</label>
                            <input type="text" id="location" name="location" class="form-control" value="{{ old('location') }}">
                        </div>
                    </div>
                    <x-button type="submit" variant="primary">Add prospect</x-button>
                </form>
            </x-card>
        </div>

        <div class="col-12 mb-2">
            <x-card title="Prospects" :padded="true">
                @if($rows === [])
                    <x-empty-state icon="users" title="No prospects yet"
                                    description="Add a prospect manually to get started." />
                @else
                    <div class="table-responsive">
                        <table class="table" data-role="prospects-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Company</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Source</th>
                                    <th>Campaign</th>
                                    <th>Stage</th>
                                    <th>Last message</th>
                                    <th>Last activity</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rows as $row)
                                    @php $prospect = $row['prospect']; @endphp
                                    <tr data-prospect="{{ $prospect->uid }}">
                                        <td>{{ $prospect->contact_name ?? '—' }}</td>
                                        <td>{{ $prospect->company_name }}</td>
                                        <td>{{ $prospect->phone }}</td>
                                        <td>{{ $prospect->email ?? '—' }}</td>
                                        <td>{{ $prospect->source ?? '—' }}</td>
                                        <td>{{ $row['campaign'] }}</td>
                                        <td>{{ $row['stage'] }}</td>
                                        <td>{{ $row['last_message'] }}</td>
                                        <td>{{ $row['last_activity'] }}</td>
                                        <td><x-badge :variant="$row['status']['variant']" data-role="prospect-status">{{ $row['status']['label'] }}</x-badge></td>
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
