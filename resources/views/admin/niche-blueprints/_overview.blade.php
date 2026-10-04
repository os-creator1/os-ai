{{-- Niche Blueprint V2 overview: status, configuration counts, Workspace entry, version history. --}}
@php
    $publishedVersion = $blueprint->versions->first(fn ($v) => $v->state === \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Published);
    $draftVersion = $blueprint->versions->first(fn ($v) => $v->state === \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Draft);
@endphp
<div class="col-12" id="blueprint-overview">
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h4 class="mb-0">{{ $blueprint->display_name }}
                    @if ($blueprint->is_active)
                        <span class="badge bg-success">Active</span>
                    @else
                        <span class="badge bg-secondary">Inactive</span>
                    @endif
                </h4>
                <div class="text-muted small">Last updated {{ optional($blueprint->updated_at)->toDayDateTimeString() }}</div>
            </div>
            <form method="POST" action="{{ route('admin.niche-blueprints.workspace.enter', $blueprint) }}">
                @csrf
                <button type="submit" class="btn btn-primary" id="enter-blueprint-workspace">Enter Blueprint Workspace</button>
            </form>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-sm-3"><strong>Published version</strong><br>{{ $publishedVersion ? 'v'.$publishedVersion->version_number : 'None' }}</div>
                <div class="col-sm-3"><strong>Latest draft</strong><br>{{ $draftVersion ? 'v'.$draftVersion->version_number : 'None' }}</div>
                <div class="col-sm-3"><strong>Businesses using it</strong><br>{{ $businessesUsing }}</div>
                <div class="col-sm-3"><strong>Showing configuration of</strong><br>{{ $configStatus['source'] }}{{ $configStatus['version'] ? ' v'.$configStatus['version']->version_number : '' }}</div>
            </div>

            <h6>Configuration status</h6>
            <div class="row" id="blueprint-config-status">
                @foreach ($configStatus['surfaces'] as $key => $surface)
                    <div class="col-md-4 mb-2" data-surface-status="{{ $key }}">
                        <div class="border rounded p-2 h-100">
                            <strong>{{ $surface['label'] }}</strong>
                            <span class="badge {{ $surface['count'] > 0 ? 'bg-success' : 'bg-secondary' }}">{{ $surface['count'] > 0 ? $surface['count'] : 'not configured' }}</span>
                            @foreach (array_slice($surface['lines'], 0, 3) as $line)
                                <div class="small text-muted">{{ $line }}</div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <h6 class="mt-3">Version history</h6>
            <table class="table table-sm" id="blueprint-version-history">
                <thead><tr><th>Version</th><th>State</th><th>Components</th><th>Published</th></tr></thead>
                <tbody>
                    @foreach ($blueprint->versions as $v)
                        <tr>
                            <td>v{{ $v->version_number }}</td>
                            <td>{{ $v->state->value }}</td>
                            <td>{{ $v->components->count() }}</td>
                            <td>{{ $v->published_at ? $v->published_at->toDayDateTimeString() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <h6 class="mt-3">Businesses provisioned from this Blueprint</h6>
            @if (count($businessRows) === 0)
                <p class="text-muted mb-0">No Business has been provisioned yet.</p>
            @else
                <table class="table table-sm" id="blueprint-business-rows">
                    <thead><tr><th>Business</th><th>Provisioned from</th><th>Updates available</th></tr></thead>
                    <tbody>
                        @foreach ($businessRows as $row)
                            <tr>
                                <td>{{ $row['business']->name }}</td>
                                <td>v{{ $row['version'] }}</td>
                                <td>{{ $row['updates'] > 0 ? $row['updates'].' (not applied automatically)' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
