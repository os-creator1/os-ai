@extends('layouts/contentLayoutMaster')

@section('title', 'Template Library')

@section('content')
    <section id="admin-template-library-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Template Library">
                    <p class="text-muted">
                        Read-only catalog of every Niche Blueprint identity and its currently
                        published version. Authoring happens on the
                        <a href="{{ route('admin.niche-blueprints.index') }}">Niche Blueprints</a> surface —
                        this is the same underlying domain content, viewed for inspection.
                    </p>

                    <x-table :headers="['Key', 'Display name', 'Vertical / Industry', 'Published version', 'Components', '']">
                        @forelse ($blueprints as $blueprint)
                            @php
                                $published = $blueprint->versions->firstWhere('state', \App\Enums\NicheBlueprint\NicheBlueprintVersionState::Published);
                            @endphp
                            <tr>
                                <td><code>{{ $blueprint->key }}</code></td>
                                <td>{{ $blueprint->display_name }}</td>
                                <td>{{ $blueprint->vertical_key ?? $blueprint->broad_industry ?? '—' }}</td>
                                <td>
                                    @if ($published)
                                        <x-badge variant="success">v{{ $published->version_number }}</x-badge>
                                    @else
                                        <span class="text-muted">None published</span>
                                    @endif
                                </td>
                                <td>{{ $published->components_count ?? 0 }}</td>
                                <td>
                                    <a href="{{ route('admin.template-library.show', $blueprint) }}">Inspect</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-empty-state icon="inbox" title="No Blueprints in the catalog yet." /></td>
                            </tr>
                        @endforelse
                    </x-table>

                    <x-pagination :paginator="$blueprints" />
                </x-card>
            </div>
        </div>
    </section>
@endsection
