@extends('layouts.contentLayoutMaster')

@section('title', 'AI SMS Analytics')

@section('content')

<div class="container-fluid py-4">

    <h1 class="mb-3">AI SMS Analytics</h1>

    @if(!$campaignFilterEnabled)
        <div class="alert alert-warning">
            Campaign filtering is disabled (no campaign_id column found).
        </div>
    @endif

    <!-- Campaign Filter -->
    <form method="GET" class="mb-4">
        <select name="campaign_id" class="form-control w-auto" onchange="this.form.submit()">
            <option value="">All Campaigns</option>
            @foreach($campaigns as $camp)
                <option value="{{ $camp->id }}" {{ $campaignId == $camp->id ? 'selected' : '' }}>
                    {{ $camp->name }}
                </option>
            @endforeach
        </select>
    </form>

    <!-- Stage Cards -->
    <div class="row">
    @foreach([1,2,3,4,5,6,99] as $s)
            <div class="col-md mb-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3>{{ $stageCounts[$s] ?? 0 }}</h3>
                        <p class="mb-0">
                            @if($s == 99)
    Rejected
@elseif($s == 6)
    Booked
@else
    Stage {{ $s }}
@endif
                        </p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Recent Boxes -->
    <h4 class="mt-4">Recent Conversations</h4>

    <div class="table-responsive">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Phone</th>
                    <th>Stage</th>
                   <th>Updated</th>
<th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentBoxes as $box)
                    <tr>
                        <td>{{ $box->id }}</td>
                        <td>{{ $box->to }}</td>
                        <td>
                           @if($box->ai_stage == 99)
    Rejected
@elseif($box->ai_stage == 6)
    Booked
@else
    Stage {{ $box->ai_stage }}
@endif
                        </td>
                        <td>{{ $box->updated_at }}</td>

<td>
    @if($box->ai_stage != 6)
        <form method="POST" action="{{ route('admin.ai.booked', $box->id) }}">
            @csrf

            <button type="submit" class="btn btn-success btn-sm">
                Mark Booked
            </button>
        </form>
    @else
        <span class="badge badge-success">Booked</span>
    @endif
</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center">
                            No data found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>

@endsection