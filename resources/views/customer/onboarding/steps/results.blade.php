@php($payload = $onboarding->analysis_payload ?? ['profile_completeness_percent' => 0, 'findings' => []])

<h5>Profile completeness: {{ $payload['profile_completeness_percent'] ?? 0 }}%</h5>
<p class="text-muted">Based only on the information you've entered so far.</p>

@if(!empty($payload['findings']))
    <ul class="list-group mb-2">
        @foreach($payload['findings'] as $finding)
            <li class="list-group-item">
                <strong>{{ $finding['title'] }}</strong>
                <p class="mb-1">{{ $finding['reason'] }}</p>

                <form method="POST" action="{{ route('customer.onboarding.action.complete') }}" class="d-flex align-items-center gap-1">
                    @csrf
                    <input type="hidden" name="fingerprint" value="{{ $finding['fingerprint'] }}">
                    <input type="hidden" name="action_key" value="{{ $finding['action_key'] }}">

                    @if(in_array($finding['action_key'], ['add_location', 'complete_location', 'add_service', 'confirm_primary_service'], true))
                        <button type="submit" class="btn btn-sm btn-outline-primary">Go fix this</button>
                    @else
                        <input type="text" name="value" class="form-control form-control-sm" placeholder="Enter a value" maxlength="2048">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    @endif
                </form>
            </li>
        @endforeach
    </ul>
@else
    <p>No outstanding items found in your stored profile data.</p>
@endif

<form method="POST" action="{{ route('customer.onboarding.complete') }}" class="mt-2">
    @csrf
    <button type="submit" class="btn btn-success">Finish setup</button>
</form>
