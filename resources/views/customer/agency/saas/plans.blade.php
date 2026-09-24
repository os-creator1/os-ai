@extends('layouts/contentLayoutMaster')

{{--
    Lane C §C3.2/§C5.2 — the AGENCY's own resale catalog.

    This is the agency's product, priced by the agency, billed on the agency's
    own Stripe account. It is deliberately NOT the platform's Core/Growth/Agency
    price list: an agency names and prices its own offer, and maps it onto a
    capability tier the product knows how to entitle.

    Repricing a plan never reprices an existing subscriber — they keep the terms
    they agreed to — and a price change unpublishes the plan until a matching
    Stripe price is verified, because a Stripe price's amount cannot be edited.
--}}

@section('title', 'SaaS Plans')

@section('content')
    <section id="agency-saas-plans">
        <h2 class="mb-2">SaaS Plans</h2>

        @unless ($chargeReady)
            <div role="alert" data-role="agency-saas-no-account">
                <p>{{ __('Connect a Stripe account before publishing plans. That is where your clients\' payments land.') }}</p>
                <a href="{{ route('customer.workspaces.agency.saas.stripe', [$agencyWorkspace->uid]) }}">{{ __('Set up your revenue account') }}</a>
            </div>
        @endunless

        <x-card title="Your plans" class="mb-2">
            @if ($plans->isEmpty())
                <p data-role="agency-saas-empty">{{ __('No plans yet.') }}</p>
            @else
                <table data-role="agency-saas-plan-table">
                    <thead>
                        <tr>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Includes') }}</th>
                            <th>{{ __('Price') }}</th>
                            <th>{{ __('Trial') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Subscribers') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            <tr data-role="agency-saas-plan-row">
                                <td>{{ $plan->name }}</td>
                                <td>{{ ucfirst($plan->tier->value) }}</td>
                                <td>{{ $plan->price }} {{ $plan->currency_code }} / {{ $plan->billing_cycle }}</td>
                                <td>{{ $plan->configuredTrialDays() === null ? __('None') : $plan->configuredTrialDays() . ' ' . __('days') }}</td>
                                <td data-role="agency-saas-plan-status">
                                    @if ($plan->is_published)
                                        {{ __('Published') }}
                                    @elseif (blank($plan->provider_price_id))
                                        {{ __('Needs a Stripe price') }}
                                    @else
                                        {{ __('Not published') }}
                                    @endif
                                </td>
                                <td>{{ $subscriberCounts[$plan->id] ?? 0 }}</td>
                                <td>
                                    @if ($isOwner)
                                        @if (blank($plan->provider_price_id))
                                            <form method="POST" action="{{ route('customer.workspaces.agency.saas.plans.price', [$agencyWorkspace->uid, $plan->uid]) }}"
                                                  data-role="agency-saas-bind-price">
                                                @csrf
                                                <input type="text" name="provider_price_id" placeholder="{{ __('Existing Stripe price id (optional)') }}">
                                                <button type="submit">{{ __('Create or connect price') }}</button>
                                            </form>
                                        @elseif (! $plan->is_published)
                                            <form method="POST" action="{{ route('customer.workspaces.agency.saas.plans.publish', [$agencyWorkspace->uid, $plan->uid]) }}"
                                                  data-role="agency-saas-publish">
                                                @csrf
                                                <button type="submit">{{ __('Publish') }}</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('customer.workspaces.agency.saas.plans.unpublish', [$agencyWorkspace->uid, $plan->uid]) }}"
                                                  data-role="agency-saas-unpublish">
                                                @csrf
                                                <button type="submit">{{ __('Unpublish') }}</button>
                                            </form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>

        @if ($isOwner)
            <x-card title="Create a plan">
                <form method="POST" action="{{ route('customer.workspaces.agency.saas.plans.store', [$agencyWorkspace->uid]) }}"
                      data-role="agency-saas-create-plan">
                    @csrf
                    <label>{{ __('Name your clients will see') }}
                        <input type="text" name="name" maxlength="191" required>
                    </label>
                    <label>{{ __('Description') }}
                        <textarea name="description" maxlength="2000"></textarea>
                    </label>
                    <label>{{ __('Includes') }}
                        <select name="tier" required>
                            @foreach ($resellableTiers as $tier)
                                <option value="{{ $tier->value }}">{{ ucfirst($tier->value) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('Price') }}
                        <input type="text" name="price" inputmode="decimal" required>
                    </label>
                    <label>{{ __('Currency') }}
                        <select name="currency_id" required>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('Billing cycle') }}
                        <select name="billing_cycle" required>
                            <option value="monthly">{{ __('Monthly') }}</option>
                            <option value="yearly">{{ __('Yearly') }}</option>
                        </select>
                    </label>
                    <label>
                        <input type="checkbox" name="trial_enabled" value="1"> {{ __('Offer a free trial') }}
                    </label>
                    <label>{{ __('Trial days') }}
                        <input type="number" name="trial_days" min="1" max="730">
                    </label>
                    <button type="submit">{{ __('Create plan') }}</button>
                </form>
            </x-card>
        @endif
    </section>
@endsection
