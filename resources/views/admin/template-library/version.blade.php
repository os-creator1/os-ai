@extends('layouts/contentLayoutMaster')

@section('title', $blueprint->display_name . ' — v' . $version->version_number)

@section('content')
    <section id="admin-template-library-version">
        <div class="row">
            <div class="col-12">
                <x-card title="{{ $blueprint->display_name }} — v{{ $version->version_number }}">
                    <dl class="row">
                        <dt class="col-sm-3">State</dt>
                        <dd class="col-sm-9">
                            @php
                                $stateVariant = match ($version->state->value) {
                                    'draft' => 'accent',
                                    'published' => 'success',
                                    default => 'neutral',
                                };
                            @endphp
                            <x-badge :variant="$stateVariant">{{ $version->state->value }}</x-badge>
                        </dd>

                        <dt class="col-sm-3">Notes</dt>
                        <dd class="col-sm-9">{{ $version->notes ?? '—' }}</dd>

                        <dt class="col-sm-3">Published at</dt>
                        <dd class="col-sm-9">{{ $version->published_at?->toDateTimeString() ?? '—' }}</dd>

                        <dt class="col-sm-3">Published by user id</dt>
                        <dd class="col-sm-9">{{ $version->published_by_user_id ?? '—' }}</dd>
                    </dl>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Component inventory">
                    @if (empty($registeredComponentTypes))
                        <div class="alert alert-warning py-2">
                            No component adapters are currently registered on this platform. Every
                            component below is provenance/catalog content only — none can be
                            installed into a Business until an adapter is registered for its
                            <code>component_type</code>.
                        </div>
                    @endif

                    @if ($version->components->isEmpty())
                        <x-empty-state icon="inbox" title="This version has no components." />
                    @else
                        <x-table :headers="['Key', 'Type', 'Adapter registered', 'Required feature', 'Availability', 'Position', 'Payload']">
                            @foreach ($version->components as $component)
                                <tr>
                                    <td><code>{{ $component->component_key }}</code></td>
                                    <td>{{ $component->component_type }}</td>
                                    <td>
                                        @if (in_array($component->component_type, $registeredComponentTypes, true))
                                            <x-badge variant="success">Registered</x-badge>
                                        @else
                                            <x-badge variant="danger">Unregistered</x-badge>
                                        @endif
                                    </td>
                                    <td>{{ $component->required_feature_key }}</td>
                                    <td>
                                        @if ($availabilityByFeatureKey[$component->required_feature_key] ?? false)
                                            <x-badge variant="success">Available</x-badge>
                                        @else
                                            <x-badge variant="neutral">Planned</x-badge>
                                        @endif
                                    </td>
                                    <td>{{ $component->position }}</td>
                                    <td><code class="text-wrap">{{ json_encode($component->payload, JSON_PRETTY_PRINT) }}</code></td>
                                </tr>
                            @endforeach
                        </x-table>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
