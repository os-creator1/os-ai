@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Automations')

@php
    $stateBadge = fn (string $state) => match ($state) {
        'enabled', 'succeeded' => 'badge-light-success',
        'failed', 'cancelled' => 'badge-light-danger',
        'awaiting_approval', 'waiting' => 'badge-light-warning',
        'disabled', 'draft', 'skipped' => 'badge-light-secondary',
        default => 'badge-light-primary',
    };
@endphp

@section('content')
    <section id="admin-platform-automations">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h3 class="mb-25">Platform Automations</h3>
                <p class="text-muted mb-0">Run platform operations — trial reminders, failed-payment notices, onboarding nudges — on account events. Separate from every Business's own automations.</p>
            </div>
            <div class="d-flex gap-1">
                <a href="{{ route('admin.platform-announcements.index') }}" class="btn btn-outline-secondary">Announcements</a>
                <a href="{{ route('admin.platform-automations.create') }}" class="btn btn-primary" data-role="pa-new">New automation</a>
            </div>
        </div>

        <ul class="nav nav-tabs" role="tablist" data-role="pa-tabs">
            @foreach (['automations' => 'Automations', 'recipes' => 'Recipes', 'runs' => 'Runs', 'failures' => 'Failures'] as $key => $label)
                <li class="nav-item">
                    <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.platform-automations.index', ['tab' => $key]) }}" data-tab="{{ $key }}">
                        {{ $label }}
                        @if ($key === 'failures' && ($counts['failed'] + $counts['approval']) > 0)
                            <span class="badge rounded-pill bg-danger">{{ $counts['failed'] + $counts['approval'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="card mt-0 border-top-0 rounded-top-0">
            <div class="card-body">
                @if ($tab === 'automations')
                    @if ($automations->isEmpty())
                        <div class="text-center py-3" data-role="pa-empty">
                            <p class="mb-1">No platform automations yet.</p>
                            <a href="{{ route('admin.platform-automations.index', ['tab' => 'recipes']) }}" class="btn btn-outline-primary">Start from a recipe</a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover" data-role="pa-list">
                                <thead class="table-primary"><tr><th>Name</th><th>When</th><th>Steps</th><th>Status</th><th>Runs</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($automations as $a)
                                    <tr data-automation="{{ $a->uid }}">
                                        <td><a href="{{ route('admin.platform-automations.edit', $a) }}" class="fw-bolder">{{ $a->name }}</a>
                                            @if ($a->description)<div class="small text-muted">{{ $a->description }}</div>@endif</td>
                                        <td>{{ $triggers[$a->trigger_type]['label'] ?? $a->trigger_type }}</td>
                                        <td>{{ count($a->definition['steps'] ?? []) }}</td>
                                        <td><span class="badge {{ $stateBadge($a->status->value) }}" data-role="pa-status">{{ ucfirst($a->status->value) }}</span></td>
                                        <td>{{ $a->runs_count }}</td>
                                        <td class="text-end text-nowrap">
                                            @if ($a->status->value === 'enabled')
                                                <form method="POST" action="{{ route('admin.platform-automations.disable', $a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" data-role="pa-disable">Disable</button></form>
                                            @else
                                                <form method="POST" action="{{ route('admin.platform-automations.enable', $a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success" data-role="pa-enable">Enable</button></form>
                                            @endif
                                            <form method="POST" action="{{ route('admin.platform-automations.duplicate', $a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary" data-role="pa-duplicate">Duplicate</button></form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @elseif ($tab === 'recipes')
                    <p class="text-muted">A recipe is a starting point, not hidden logic: using one copies it into an ordinary, disabled automation you can edit, duplicate or delete.</p>
                    <div class="row" data-role="pa-recipes">
                        @foreach ($recipes as $key => $recipe)
                            <div class="col-md-6 col-xl-4 mb-2">
                                <div class="card h-100 border" data-recipe="{{ $key }}">
                                    <div class="card-body d-flex flex-column">
                                        <h5>{{ $recipe['name'] }}</h5>
                                        <p class="text-muted flex-grow-1">{{ $recipe['description'] }}</p>
                                        <div class="small mb-1">When: <strong>{{ $triggers[$recipe['trigger_type']]['label'] ?? 'Not available yet' }}</strong></div>
                                        @if ($recipe['available'])
                                            <form method="POST" action="{{ route('admin.platform-automations.recipes.use', $key) }}">@csrf
                                                <button class="btn btn-outline-primary" data-role="pa-use-recipe">Use this recipe</button></form>
                                        @else
                                            <div class="alert alert-secondary mb-0 small" data-role="pa-recipe-unavailable">{{ $recipe['unavailable_reason'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    @if ($tab === 'failures')
                        <p class="text-muted">Runs that failed after their retries, and steps waiting for a Platform Owner to approve or reject them.</p>
                    @endif
                    @if ($runs->isEmpty())
                        <div class="text-center py-3" data-role="pa-runs-empty">{{ $tab === 'failures' ? 'Nothing needs attention.' : 'No runs yet.' }}</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover" data-role="pa-runs">
                                <thead class="table-primary"><tr><th>Started</th><th>Automation</th><th>Trigger</th><th>Target</th><th>State</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($runs as $run)
                                    <tr data-run="{{ $run->uid }}">
                                        <td class="text-nowrap">{{ $run->created_at->format('M j, g:i A') }}</td>
                                        <td>{{ $run->automation->name }}</td>
                                        <td>{{ $triggers[$run->trigger_type]['label'] ?? $run->trigger_type }}</td>
                                        <td>{{ ucfirst($run->target_type->value) }} #{{ $run->target_id }}</td>
                                        <td><span class="badge {{ $stateBadge($run->state->value) }}">{{ str_replace('_', ' ', ucfirst($run->state->value)) }}</span>
                                            @if ($run->safe_error)<div class="small text-danger">{{ $run->safe_error }}</div>@endif</td>
                                        <td class="text-end"><a href="{{ route('admin.platform-automations.runs.show', $run) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $runs->links() }}
                    @endif
                @endif
            </div>
        </div>
    </section>
@endsection
