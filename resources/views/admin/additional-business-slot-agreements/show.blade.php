@extends('layouts/contentLayoutMaster')

@section('title', 'Additional Business Slot Agreement #' . $agreement->id)

@section('content')
    <section id="admin-additional-business-slot-agreement-show">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('admin.additional-business-slot-agreements.index') }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" />
                    Back to Agreements
                </a>
            </div>

            <div class="col-12">
                @if (session('flash_success'))
                    <x-alert variant="success" icon="check-circle" class="mb-2">{{ session('flash_success') }}</x-alert>
                @endif

                @if (session('flash_error'))
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">{{ session('flash_error') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card :title="'Agreement #' . $agreement->id">
                    <dl class="row mb-3">
                        <dt class="col-sm-3 text-label">Workspace</dt>
                        <dd class="col-sm-9 text-numeric">{{ $agreement->workspace_id }}</dd>

                        <dt class="col-sm-3 text-label">State</dt>
                        <dd class="col-sm-9"><x-badge variant="info">{{ $agreement->state->value }}</x-badge></dd>

                        <dt class="col-sm-3 text-label">Current / Target allocation</dt>
                        <dd class="col-sm-9 text-numeric">{{ $agreement->current_allocation_count }} / {{ $agreement->target_allocation_count }}</dd>

                        <dt class="col-sm-3 text-label">Requesting customer</dt>
                        <dd class="col-sm-9 text-numeric">{{ $agreement->requesting_customer_user_id }}</dd>

                        <dt class="col-sm-3 text-label">Payment method</dt>
                        <dd class="col-sm-9">{{ $agreement->payment_method_display_snapshot }}</dd>

                        <dt class="col-sm-3 text-label">Payment lapsed</dt>
                        <dd class="col-sm-9">{{ $agreement->payment_lapsed ? 'Yes, since ' . $agreement->payment_lapsed_at?->format('Y-m-d H:i') : 'No' }}</dd>

                        <dt class="col-sm-3 text-label">Cancellation</dt>
                        <dd class="col-sm-9">{{ $agreement->cancel_at_period_end ? 'Requested, effective ' . $agreement->cancellation_effective_at?->format('Y-m-d H:i') : 'None' }}</dd>
                    </dl>

                    @if (in_array($agreement->state->value, ['payment_succeeded', 'allocation_pending', 'allocation_failed'], true))
                        <form method="POST" action="{{ route('admin.additional-business-slot-agreements.allocate', $agreement->id) }}" class="mb-3">
                            @csrf
                            <div class="input-group input-group-sm">
                                <input type="text" name="reason" class="form-control transition-fast" placeholder="Mandatory reason" required maxlength="5000">
                                <x-button type="submit" variant="primary" size="sm">Allocate Slots</x-button>
                            </div>
                        </form>
                    @endif

                    @if (! $agreement->cancel_at_period_end && $agreement->state->value !== 'canceled')
                        <form method="POST" action="{{ route('admin.additional-business-slot-agreements.cancel', $agreement->id) }}">
                            @csrf
                            <div class="input-group input-group-sm">
                                <input type="text" name="reason" class="form-control transition-fast" placeholder="Mandatory reason" required maxlength="5000">
                                <x-button type="submit" variant="outline-danger" size="sm">Cancel At Period End</x-button>
                            </div>
                        </form>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
