@extends('layouts/contentLayoutMaster')

{{-- Call-task inbox: open tasks (soonest due first) and the last completed ones. Escaped plain text only. --}}

@section('title', 'Tasks')

@section('content')
    @php
        $personName = fn ($task) => ($names[$task->contact_id] ?? '') !== '' ? $names[$task->contact_id] : 'Contact';
        $due = fn ($task) => $task->due_at === null ? null : \Illuminate\Support\Carbon::parse($task->due_at, 'UTC')->setTimezone($timezone);
    @endphp

    <section id="crm-tasks" data-role="crm-tasks">
        <div class="mb-1">
            <x-button variant="ghost" icon="arrow-left" :href="route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid])">Opportunities</x-button>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        <x-card title="Open tasks" :padded="false">
            <ol class="list-unstyled mb-0" data-role="crm-task-list">
                @forelse ($open as $task)
                    @php($dueAt = $due($task))
                    <li class="d-flex align-items-center justify-content-between border-bottom p-1" data-role="crm-task" data-task="{{ $task->uid }}">
                        <div>
                            <div><strong>{{ $task->title }}</strong></div>
                            <div class="small text-muted">
                                @if (isset($contactUids[$task->contact_id]))
                                    <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contactUids[$task->contact_id]]) }}">{{ $personName($task) }}</a>
                                @else
                                    {{ $personName($task) }}
                                @endif
                                @if ($dueAt)
                                    · due {{ $dueAt->format('M j, g:i A') }}
                                    @if ($dueAt->isPast())<x-badge variant="accent">Overdue</x-badge>@endif
                                @else
                                    · no due time
                                @endif
                            </div>
                        </div>
                        @if ($canManage)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.crm.tasks.complete', [$workspaceUid, $businessUid, $task->uid]) }}" data-role="crm-task-complete">
                                @csrf
                                <x-button type="submit" variant="outline" size="sm">Mark done</x-button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="p-1 text-muted" data-role="crm-tasks-empty">No open tasks.</li>
                @endforelse
            </ol>
        </x-card>

        @if ($done->isNotEmpty())
            <x-card title="Recently completed" :padded="false">
                <ol class="list-unstyled mb-0" data-role="crm-task-done-list">
                    @foreach ($done as $task)
                        <li class="p-1 border-bottom text-muted">{{ $task->title }} · {{ $personName($task) }}</li>
                    @endforeach
                </ol>
            </x-card>
        @endif
    </section>
@endsection
