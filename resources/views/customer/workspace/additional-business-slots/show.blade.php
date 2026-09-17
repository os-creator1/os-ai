@extends('layouts/contentLayoutMaster')

@php
    $resolvedCustomerContext = request()->attributes->get('customerContext');
    $accountNoun = $resolvedCustomerContext instanceof \App\Library\Navigation\CustomerContext ? $resolvedCustomerContext->accountNoun() : 'account';
@endphp

@section('title', 'Additional Business Slots')

@section('content')
    <section id="additional-business-slots">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.show', $workspace->uid) }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" />
                    Back to {{ ucfirst($accountNoun) }}
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
                <x-card title="Additional Business Slots">
                    @if ($agreement === null)
                        {{-- Implementation Contract 11 §4/§14 — the new-purchase checkout route is retired; no form may reference it. --}}
                        <p class="text-caption mb-3">No additional-slot agreement exists for this {{ $accountNoun }}. New additional-slot purchases are not currently available.</p>
                    @else
                        <dl class="row mb-3">
                            <dt class="col-sm-4 text-label">State</dt>
                            <dd class="col-sm-8"><x-badge variant="info">{{ $agreement->state->value }}</x-badge></dd>

                            <dt class="col-sm-4 text-label">Current allocation</dt>
                            <dd class="col-sm-8 text-numeric">{{ $agreement->current_allocation_count }}</dd>

                            <dt class="col-sm-4 text-label">Target allocation</dt>
                            <dd class="col-sm-8 text-numeric">{{ $agreement->target_allocation_count }}</dd>

                            <dt class="col-sm-4 text-label">Payment method</dt>
                            <dd class="col-sm-8">{{ $agreement->payment_method_display_snapshot }}</dd>

                            <dt class="col-sm-4 text-label">Next renewal</dt>
                            <dd class="col-sm-8 text-caption">{{ $agreement->next_renewal_at?->format('Y-m-d H:i') ?? '—' }}</dd>

                            @if ($agreement->payment_lapsed)
                                <dt class="col-sm-4 text-label">Payment lapsed</dt>
                                <dd class="col-sm-8"><x-badge variant="danger">Payment lapsed since {{ $agreement->payment_lapsed_at?->format('Y-m-d H:i') }}</x-badge></dd>
                            @endif

                            @if ($agreement->cancel_at_period_end)
                                <dt class="col-sm-4 text-label">Cancellation</dt>
                                <dd class="col-sm-8"><x-badge variant="warning">Effective {{ $agreement->cancellation_effective_at?->format('Y-m-d H:i') }}</x-badge></dd>
                            @endif
                        </dl>

                        @if ($agreement->state->value === 'checkout_pending')
                            <a href="{{ route('customer.workspaces.additional-business-slots.confirm', ['workspaceUid' => $workspace->uid, 'agreement' => $agreement->id]) }}" class="btn btn-outline-primary btn-sm">Confirm Payment</a>
                        @endif

                        {{-- Implementation Contract 11 §1/§4/§14 (corrected) — mid-period increase buys new paid slot capacity and is retired along with initial checkout; no form may reference it. Cancellation is a non-expansion existing-holder action and remains available. --}}
                        @if ($agreement->state->value === 'completed' && ! $agreement->cancel_at_period_end)
                            <form method="POST" action="{{ route('customer.workspaces.additional-business-slots.cancel', ['workspaceUid' => $workspace->uid, 'agreement' => $agreement->id]) }}" class="mt-3">
                                @csrf
                                <x-button type="submit" variant="outline-danger" size="sm">Cancel At Period End</x-button>
                            </form>
                        @endif
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
