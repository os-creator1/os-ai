@extends('layouts/contentLayoutMaster')

@section('title', $blueprint->display_name)

@section('content')
    <section id="admin-template-library-show">
        <div class="row">
            <div class="col-12">
                <x-card title="{{ $blueprint->display_name }}">
                    <dl class="row">
                        <dt class="col-sm-3">Key</dt>
                        <dd class="col-sm-9"><code>{{ $blueprint->key }}</code></dd>

                        <dt class="col-sm-3">Vertical</dt>
                        <dd class="col-sm-9">{{ $blueprint->vertical_key ?? '—' }}</dd>

                        <dt class="col-sm-3">Broad industry</dt>
                        <dd class="col-sm-9">{{ $blueprint->broad_industry ?? '—' }}</dd>

                        <dt class="col-sm-3">Active</dt>
                        <dd class="col-sm-9">
                            @if ($blueprint->is_active)
                                <x-badge variant="success">Active</x-badge>
                            @else
                                <x-badge variant="neutral">Inactive</x-badge>
                            @endif
                        </dd>
                    </dl>

                    <p>
                        <a href="{{ route('admin.niche-blueprints.show', $blueprint) }}">Open in Niche Blueprints (authoring)</a>
                    </p>
                </x-card>
            </div>

            <div class="col-12">
                <x-card title="Version provenance">
                    <x-table :headers="['Version', 'State', 'Components', 'Published at', '']">
                        @forelse ($blueprint->versions as $version)
                            <tr>
                                <td>v{{ $version->version_number }}</td>
                                <td>
                                    @php
                                        $stateVariant = match ($version->state->value) {
                                            'draft' => 'accent',
                                            'published' => 'success',
                                            default => 'neutral',
                                        };
                                    @endphp
                                    <x-badge :variant="$stateVariant">{{ $version->state->value }}</x-badge>
                                </td>
                                <td>{{ $version->components_count }}</td>
                                <td>{{ $version->published_at?->toDateTimeString() ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('admin.template-library.versions.show', [$blueprint, $version]) }}">Inspect</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5"><x-empty-state icon="inbox" title="No versions yet." /></td>
                            </tr>
                        @endforelse
                    </x-table>
                </x-card>
            </div>
        </div>
    </section>
@endsection
