@extends('layouts/contentLayoutMaster')

{{--
    Settings → Plan & subscription: this account's AI Business OS plan
    (WorkspacePlanPresenter). Every line is a persisted fact — plan, status,
    the features that exist today, canonical capacity and a price only when
    one is configured. The inherited SMS "Pricing Plans" / subscription pages
    are a different domain and are not linked from here.
--}}

@section('title', 'Plan & subscription')

@section('content')
    <section id="workspace-plan">
        <div class="row">
            <div class="col-12 col-lg-8">
                <x-card title="Your plan">
                    @if ($plan === null)
                        <p class="mb-0" data-role="plan-unassigned">No plan is set up for {{ $accountName }} yet. Please contact support.</p>
                    @else
                        <div class="d-flex align-items-center flex-wrap gap-1 mb-50">
                            <h3 class="mb-0" data-role="plan-name">{{ $plan['name'] }}</h3>
                            <x-badge :variant="$plan['status_variant']">{{ $plan['status'] }}</x-badge>
                            @if ($plan['complimentary'])
                                <x-badge variant="neutral">Complimentary</x-badge>
                            @endif
                        </div>
                        <p class="text-caption mb-0">{{ $accountName }}</p>
                    @endif
                </x-card>

                @if ($plan !== null)
                    @if (! empty($included))
                        <x-card title="What's included">
                            <ul class="list-unstyled mb-0" data-role="plan-included">
                                @foreach ($included as $feature)
                                    <li class="mb-1">
                                        <div class="fw-bolder">{{ $feature['name'] }}</div>
                                        <div class="text-caption">{{ $feature['description'] }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        </x-card>
                    @endif

                    @if (! empty($capacity))
                        <x-card title="Capacity">
                            <dl class="row mb-0" data-role="plan-capacity">
                                @foreach ($capacity as $row)
                                    <dt class="col-sm-5">{{ $row['label'] }}</dt>
                                    <dd class="col-sm-7">{{ $row['value'] }}</dd>
                                @endforeach
                            </dl>
                            @if ($capacity_note !== null)
                                <p class="text-caption mt-1 mb-0" data-role="plan-capacity-note">{{ $capacity_note }}</p>
                            @endif
                        </x-card>
                    @endif

                    @if (! empty($billing))
                        <x-card title="Billing">
                            <dl class="row mb-0" data-role="plan-billing">
                                @foreach ($billing as $row)
                                    <dt class="col-sm-5">{{ $row['label'] }}</dt>
                                    <dd class="col-sm-7">{{ $row['value'] }}</dd>
                                @endforeach
                            </dl>
                        </x-card>
                    @endif
                @endif
            </div>
        </div>
    </section>
@endsection
