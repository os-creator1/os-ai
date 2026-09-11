@extends('layouts/contentLayoutMaster')

@section('title', 'Business Details')

@section('content')
    @php
        $labelFor = fn (string $key) => $fieldCopy[$key]['label'] ?? $key;
    @endphp

    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Business Details</h4>
            <p class="text-caption mb-0">These answers help us write your website and keep your information accurate. Nothing here is shown publicly until you confirm it.</p>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($completeness->questionPack !== null)
        <x-card title="Suggested questions for your business" class="mb-3">
            <p class="text-caption mb-2">Based on your business, here's what we'd most like to know:</p>
            <ol class="mb-0">
                @foreach ($completeness->questionPack->questions as $question)
                    <li class="mb-1">{{ $question['prompt'] ?? $labelFor($question['field_key'] ?? '') }}</li>
                @endforeach
            </ol>
        </x-card>
    @else
        <x-alert variant="neutral" class="mb-3">
            No guided question set has been configured for your business type yet. You can still fill in every field below.
        </x-alert>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Needs your attention" class="mb-3">
                @if (empty($completeness->missingFieldKeys) && empty($completeness->staleFieldKeys))
                    <p class="text-caption mb-0">Nothing needs your attention right now.</p>
                @else
                    @if (! empty($completeness->missingFieldKeys))
                        <p class="text-label fw-medium mb-2">Not answered yet</p>
                        <ul class="list-unstyled mb-3">
                            @foreach ($completeness->missingFieldKeys as $key)
                                <li class="mb-2">
                                    <x-badge variant="warning">{{ $labelFor($key) }}</x-badge>
                                    @if (isset($fieldCopy[$key]['help']))
                                        <span class="text-caption d-block mt-1">{{ $fieldCopy[$key]['help'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($completeness->staleFieldKeys))
                        <p class="text-label fw-medium mb-2">Please confirm these are still correct</p>
                        <ul class="list-unstyled mb-0">
                            @foreach ($completeness->staleFieldKeys as $key)
                                <li class="mb-2">
                                    <x-badge variant="accent">{{ $labelFor($key) }}</x-badge>
                                    @if (isset($fieldCopy[$key]['help']))
                                        <span class="text-caption d-block mt-1">{{ $fieldCopy[$key]['help'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-card>

            @if (! empty($completeness->presentFieldKeys))
                <x-card title="Already complete" class="mb-3">
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($completeness->presentFieldKeys as $key)
                            <x-badge variant="success">{{ $labelFor($key) }}</x-badge>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-button variant="primary" :href="route('customer.workspaces.businesses.knowledge-profile.edit', [$workspaceUid, $businessUid])">
                Edit your business details
            </x-button>
        </div>

        <div class="col-lg-4">
            <x-card title="Locations &amp; hours">
                @forelse ($locations as $location)
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <span class="text-label">{{ $location->name }}</span>
                            @if ($location->is_primary)
                                <x-badge variant="accent" class="ms-1">Primary</x-badge>
                            @endif
                            @if ($location->hours === null)
                                <span class="text-caption d-block">Hours not set</span>
                            @elseif ($location->hours_verification_status !== 'customer_confirmed')
                                <span class="text-caption d-block">Please confirm hours</span>
                            @else
                                <span class="text-caption d-block">Hours set</span>
                            @endif
                        </div>
                        <x-button variant="ghost" size="sm" :href="route('customer.workspaces.businesses.knowledge-profile.edit', [$workspaceUid, $businessUid]) . '#hours-' . $location->uid">
                            Edit
                        </x-button>
                    </div>
                @empty
                    <p class="text-caption mb-0">No locations yet.</p>
                @endforelse
            </x-card>
        </div>
    </div>
@endsection
