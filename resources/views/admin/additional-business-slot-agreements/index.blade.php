@extends('layouts/contentLayoutMaster')

@section('title', 'Additional Business Slot Agreements')

@section('content')
    <section id="admin-additional-business-slot-agreements-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card title="Additional Business Slot Agreements">
                    @if ($agreements->isEmpty())
                        <x-empty-state icon="inbox" title="No additional-slot agreements yet." />
                    @else
                        <x-table :headers="['ID', 'Workspace', 'State', 'Current', 'Target', 'Lapsed', 'Created']">
                            @foreach ($agreements as $agreement)
                                <tr>
                                    <td class="text-numeric">
                                        <a href="{{ route('admin.additional-business-slot-agreements.show', $agreement->id) }}">{{ $agreement->id }}</a>
                                    </td>
                                    <td class="text-numeric">{{ $agreement->workspace_id }}</td>
                                    <td><x-badge variant="info">{{ $agreement->state->value }}</x-badge></td>
                                    <td class="text-numeric">{{ $agreement->current_allocation_count }}</td>
                                    <td class="text-numeric">{{ $agreement->target_allocation_count }}</td>
                                    <td>{{ $agreement->payment_lapsed ? 'Yes' : 'No' }}</td>
                                    <td class="text-caption">{{ $agreement->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </x-table>

                        {{ $agreements->links() }}
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
