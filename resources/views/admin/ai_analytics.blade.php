@extends('layouts.contentLayoutMaster')

@section('title', 'AI SMS Analytics')

@section('content')

<div class="container-fluid py-4">

    <h1 class="mb-3">AI SMS Analytics</h1>

    @if(!$campaignFilterEnabled)
        <x-alert variant="warning">
            Campaign filtering is disabled (no campaign_id column found).
        </x-alert>
    @endif

    <!-- Campaign Filter -->
    <form method="GET" class="mb-4">
        @php
            $campaignOptions = ['' => 'All Campaigns'];
            foreach ($campaigns as $camp) {
                $campaignOptions[$camp->id] = $camp->name;
            }
        @endphp
        <x-select name="campaign_id" :options="$campaignOptions" :selected="$campaignId" class="w-auto" onchange="this.form.submit()" />
    </form>

    <!-- Stage Cards -->
    <div class="row">
    @foreach([1,2,3,4,5,6,99] as $s)
            <div class="col-md mb-2">
                <x-card class="text-center">
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
                </x-card>
            </div>
        @endforeach
    </div>

    <!-- Recent Boxes -->
    <h4 class="mt-4">Recent Conversations</h4>

    @if($recentBoxes->isEmpty())
        <x-empty-state title="No data found" />
    @else
        <x-table :headers="['ID', 'Phone', 'Stage', 'Updated', 'Action']">
                @foreach($recentBoxes as $box)
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
        <x-badge variant="success">Booked</x-badge>
    @endif
</td>
                    </tr>
                @endforeach
        </x-table>
    @endif

</div>

@endsection