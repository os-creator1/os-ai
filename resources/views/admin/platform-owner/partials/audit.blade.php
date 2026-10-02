{{--
    Platform Owner / Admin V1 — recent rows of the Workspace's append-only
    entitlement/lifecycle audit trail (workspace_entitlement_transitions):
    when, what, who, why, and the before/after of the changed field. Reasons
    are free text the actor typed; no secret or provider payload is ever
    stored there. Expects $rows (bounded collection) and $actors (id => label).
--}}
@php
    $actors = $actors ?? [];
    $title = $title ?? 'Recent actions';
@endphp

<x-card :title="$title">
    @if (collect($rows)->isEmpty())
        <p class="mb-0 text-muted" data-testid="po-audit-none">No recorded actions yet.</p>
    @else
        <x-table :headers="array_merge(['When'], ($showWorkspace ?? false) ? ['Workspace'] : [], ['Action', 'Actor', 'Change', 'Reason'])">
            @foreach ($rows as $row)
                <tr data-testid="po-audit-row">
                    <td>{{ $row->created_at?->toDateTimeString() }}</td>
                    @if ($showWorkspace ?? false)
                        <td>
                            @if ($row->workspace)
                                <a href="{{ route('admin.workspaces.show', $row->workspace) }}">{{ $row->workspace->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                    @endif
                    <td><code>{{ $row->transition_type->value }}</code></td>
                    <td>{{ $row->actor_user_id ? ($actors[$row->actor_user_id] ?? 'User #' . $row->actor_user_id) : 'System' }}</td>
                    <td>
                        @if ($row->transition_type === \App\Enums\Entitlement\WorkspaceEntitlementTransitionType::BusinessStatusChanged)
                            {{ $row->payload['business_uid'] ?? '' }}: {{ $row->payload['from'] ?? '—' }} → {{ $row->payload['to'] ?? '—' }}
                        @elseif ($row->from_status || $row->to_status)
                            {{ $row->from_status?->value ?? '—' }} → {{ $row->to_status?->value ?? '—' }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $row->reason ?? '—' }}</td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>
