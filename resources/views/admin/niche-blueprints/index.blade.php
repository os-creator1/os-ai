@extends('layouts/contentLayoutMaster')

@section('title', 'Niche Blueprints')

@section('content')
    <section id="admin-niche-blueprints-index">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
            </div>
            <div class="col-12">
                <x-card title="Niche Blueprints">
                    <x-slot:actions>
                        <x-button :href="route('admin.niche-blueprints.create')" variant="primary" icon="plus">
                            Create Blueprint
                        </x-button>
                    </x-slot:actions>

                    <x-table :headers="['Key', 'Display name', 'Vertical / Industry', 'Active', 'Draft', 'Published', '']">
                        @forelse ($blueprints as $blueprint)
                            @php
                                $draftVersion = $blueprint->versions->firstWhere('state', \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Draft);
                                $publishedVersion = $blueprint->versions->firstWhere('state', \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Published);
                            @endphp
                            <tr>
                                <td><code>{{ $blueprint->key }}</code></td>
                                <td>{{ $blueprint->display_name }}</td>
                                <td>{{ $blueprint->vertical_key ?? $blueprint->broad_industry ?? '—' }}</td>
                                <td>
                                    @if ($blueprint->is_active)
                                        <x-badge variant="success">Active</x-badge>
                                    @else
                                        <x-badge variant="neutral">Inactive</x-badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($draftVersion)
                                        <x-badge variant="accent">v{{ $draftVersion->version_number }} draft</x-badge>
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($publishedVersion)
                                        <x-badge variant="success">v{{ $publishedVersion->version_number }}</x-badge>
                                    @else
                                        <span class="text-muted">None</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.niche-blueprints.show', $blueprint) }}">Inspect</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"><x-empty-state icon="inbox" title="No Niche Blueprints exist yet." /></td>
                            </tr>
                        @endforelse
                    </x-table>

                    <x-pagination :paginator="$blueprints" />
                </x-card>
            </div>
        </div>
    </section>
@endsection
