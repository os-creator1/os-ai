@extends('layouts/contentLayoutMaster')

{{--
    Lane C §C8 — the AGENCY's own SaaS revenue, labelled as the agency's.

    This is money the agency bills its clients on the agency's own Stripe
    account. It is not platform revenue, it is not a business's customer
    revenue, and it is not usage funding — Addendum §12 keeps the four apart,
    and so does this page's wording.

    Totals are grouped BY CURRENCY and never summed across them: one number
    spanning currencies would be a fiction.
--}}

@section('title', 'Agency SaaS revenue')

@section('content')
    <section id="agency-saas-revenue">
        <h2 class="mb-2">Your SaaS revenue</h2>

        <p class="text-caption" data-role="agency-revenue-disclaimer">
            {{ __('This is recurring revenue you bill your own clients, on your own Stripe account. It is not platform revenue and we take no cut of it.') }}
        </p>

        <x-card title="Recurring revenue" class="mb-2">
            @if (empty($recurringByCurrency))
                <p data-role="agency-revenue-empty">{{ __('No active client subscriptions yet.') }}</p>
            @else
                <ul data-role="agency-revenue-totals">
                    @foreach ($recurringByCurrency as $code => $total)
                        <li>{{ $total }} {{ $code }} {{ __('per billing period') }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($attentionCount > 0)
                <p role="alert" data-role="agency-revenue-attention">
                    {{ __('Client subscriptions needing attention: :count', ['count' => $attentionCount]) }}
                </p>
            @endif
        </x-card>

        <x-card title="Client subscriptions">
            @if ($rows->isEmpty())
                <p data-role="agency-revenue-no-clients">{{ __('Offer a plan to a client to get started.') }}</p>
            @else
                <table data-role="agency-revenue-table">
                    <thead>
                        <tr>
                            <th>{{ __('Client') }}</th>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('They pay') }}</th>
                            <th>{{ __('Renews') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-role="agency-revenue-row" @if ($row['needs_attention']) data-attention="1" @endif>
                                <td>{{ $row['client_name'] }}</td>
                                <td>{{ $row['plan_name'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['price'] }} {{ $row['currency_code'] }} / {{ $row['billing_cycle'] }}</td>
                                <td>{{ $row['current_period_end']?->toFormattedDateString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>
    </section>
@endsection
