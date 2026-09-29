@extends('layouts/contentLayoutMaster')

@section('title', $blueprint->display_name)

@section('content')
    @php
        $registeredTypesList = implode(', ', $registeredComponentTypes);
    @endphp
    <section id="admin-niche-blueprint-show">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Identity ------------------------------------------------- --}}
            <div class="col-12">
                <x-card title="Blueprint identity">
                    <dl class="row">
                        <dt class="col-sm-3">Key</dt>
                        <dd class="col-sm-9"><code>{{ $blueprint->key }}</code></dd>

                        <dt class="col-sm-3">Display name</dt>
                        <dd class="col-sm-9">{{ $blueprint->display_name }}</dd>

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

                    <div class="d-flex gap-2 mb-3">
                        @if ($blueprint->is_active)
                            <form method="POST" action="{{ route('admin.niche-blueprints.deactivate', $blueprint) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-warning btn-sm">Deactivate</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.niche-blueprints.activate', $blueprint) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-success btn-sm">Activate</button>
                            </form>
                        @endif
                    </div>

                    <hr>

                    <h6>Edit identity</h6>
                    <form method="POST" action="{{ route('admin.niche-blueprints.update', $blueprint) }}" class="row g-2">
                        @csrf
                        @method('PATCH')
                        <div class="col-md-4">
                            <input type="text" name="display_name" class="form-control" value="{{ old('display_name', $blueprint->display_name) }}" required>
                        </div>
                        <div class="col-md-3">
                            <select name="vertical_key" class="form-select">
                                <option value="">(none)</option>
                                @foreach ($verticals as $vertical)
                                    <option value="{{ $vertical->key }}" @selected(old('vertical_key', $blueprint->vertical_key) === $vertical->key)>{{ $vertical->display_name }}{{ $vertical->is_active ? '' : ' (inactive — current value, kept unless changed)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="broad_industry" class="form-select">
                                <option value="">(none)</option>
                                @foreach ($industries as $industry)
                                    <option value="{{ $industry->value }}" @selected(old('broad_industry', $blueprint->broad_industry) === $industry->value)>{{ $industry->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-primary w-100">Save</button>
                        </div>
                    </form>
                </x-card>
            </div>

            {{-- Draft version ---------------------------------------------- --}}
            <div class="col-12">
                <x-card title="Draft version">
                    @if ($draft === null)
                        <x-empty-state icon="file-plus" title="No draft version." description="Open a draft to author components before publishing." />
                        <form method="POST" action="{{ route('admin.niche-blueprints.versions.store', $blueprint) }}" class="row g-2">
                            @csrf
                            <div class="col-md-9">
                                <input type="text" name="notes" class="form-control" placeholder="Release note (optional)" value="{{ old('notes') }}">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">Open draft</button>
                            </div>
                        </form>
                    @else
                        <dl class="row">
                            <dt class="col-sm-3">Version</dt>
                            <dd class="col-sm-9">v{{ $draft->version_number }}</dd>
                            <dt class="col-sm-3">State</dt>
                            <dd class="col-sm-9"><x-badge variant="accent">{{ $draft->state->value }}</x-badge></dd>
                        </dl>

                        <form method="POST" action="{{ route('admin.niche-blueprints.versions.update', [$blueprint, $draft]) }}" class="row g-2 mb-3">
                            @csrf
                            @method('PATCH')
                            <div class="col-md-9">
                                <input type="text" name="notes" class="form-control" placeholder="Release note" value="{{ old('notes', $draft->notes) }}">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-outline-primary w-100">Save notes</button>
                            </div>
                        </form>

                        <h6>Components</h6>
                        @if ($draft->components->isEmpty())
                            <p class="text-muted">This draft has no components yet. An empty draft may still be published (§6.2).</p>
                        @else
                            <x-table :headers="['Key', 'Type', 'Required feature', 'Position', '']">
                                @foreach ($draft->components as $component)
                                    <tr>
                                        <td><code>{{ $component->component_key }}</code></td>
                                        <td>{{ $component->component_type }}</td>
                                        <td>{{ $component->required_feature_key }}</td>
                                        <td>{{ $component->position }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('admin.niche-blueprints.components.destroy', [$blueprint, $draft, $component]) }}" onsubmit="return confirm('Remove this component from the draft?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="5">
                                            <form method="POST" action="{{ route('admin.niche-blueprints.components.update', [$blueprint, $draft, $component]) }}" class="row g-2">
                                                @csrf
                                                @method('PATCH')
                                                <div class="col-md-2">
                                                    <input type="text" name="component_key" class="form-control form-control-sm" value="{{ $component->component_key }}">
                                                </div>
                                                <div class="col-md-2">
                                                    <input type="text" name="component_type" class="form-control form-control-sm" value="{{ $component->component_type }}">
                                                </div>
                                                <div class="col-md-2">
                                                    @php
                                                        $currentFeatureInVocabulary = collect($businessScopedFeatures)->contains(fn ($feature) => $feature->value === $component->required_feature_key);
                                                    @endphp
                                                    <select name="required_feature_key" class="form-select form-select-sm">
                                                        @unless ($currentFeatureInVocabulary)
                                                            {{-- Contract 20 §6.2/§15 — draft authoring deliberately permits an
                                                                 unknown or Workspace-scoped required_feature_key; only publish
                                                                 refuses it. The persisted current value must stay selectable and
                                                                 selected here, or editing any other field on this component
                                                                 (payload, position) would silently overwrite it with whichever
                                                                 Business-scoped option happens to render first. --}}
                                                            <option value="{{ $component->required_feature_key }}" selected>
                                                                {{ $component->required_feature_key }} (current / invalid until corrected)
                                                            </option>
                                                        @endunless
                                                        @foreach ($businessScopedFeatures as $feature)
                                                            <option value="{{ $feature->value }}" @selected($component->required_feature_key === $feature->value)>
                                                                {{ $feature->value }} ({{ \App\Library\Entitlement\PlatformFeatureRegistry::isAvailable($feature->value) ? 'Available' : 'Planned' }})
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <textarea name="payload_json" class="form-control form-control-sm" rows="1">{{ json_encode($component->payload) }}</textarea>
                                                </div>
                                                <div class="col-md-1">
                                                    <input type="number" name="position" class="form-control form-control-sm" value="{{ $component->position }}" min="0">
                                                </div>
                                                <div class="col-md-2">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Update</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </x-table>
                        @endif

                        <h6>Add component</h6>
                        @if (empty($registeredComponentTypes))
                            <div class="alert alert-warning py-2">
                                No component adapters are currently registered. A component naming any
                                <code>component_type</code> here will fail to publish (§6.2 gate 1) until an
                                adapter is registered for it — the UI honestly reflects that vocabulary is
                                currently empty.
                            </div>
                        @else
                            <p class="text-muted">Registered adapter types: {{ $registeredTypesList }}</p>
                        @endif
                        <form method="POST" action="{{ route('admin.niche-blueprints.components.store', [$blueprint, $draft]) }}" class="row g-2">
                            @csrf
                            <div class="col-md-2">
                                <input type="text" name="component_key" class="form-control" placeholder="component_key" value="{{ old('component_key') }}" required>
                            </div>
                            <div class="col-md-2">
                                <input type="text" name="component_type" class="form-control" placeholder="component_type" value="{{ old('component_type') }}" required>
                            </div>
                            <div class="col-md-2">
                                <select name="required_feature_key" class="form-select" required>
                                    <option value="">Required feature…</option>
                                    @foreach ($businessScopedFeatures as $feature)
                                        <option value="{{ $feature->value }}" @selected(old('required_feature_key') === $feature->value)>
                                            {{ $feature->value }} ({{ \App\Library\Entitlement\PlatformFeatureRegistry::isAvailable($feature->value) ? 'Available' : 'Planned' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <textarea name="payload_json" class="form-control" rows="1" placeholder='{"key": "value"}'>{{ old('payload_json', '{}') }}</textarea>
                            </div>
                            <div class="col-md-1">
                                <input type="number" name="position" class="form-control" placeholder="pos" min="0" value="{{ old('position') }}">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Add</button>
                            </div>
                        </form>

                        <hr>

                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('admin.niche-blueprints.versions.publish', [$blueprint, $draft]) }}" onsubmit="return confirm('Publish this draft? Its authoring content becomes immutable.');">
                                @csrf
                                <button type="submit" class="btn btn-success">Publish</button>
                            </form>
                            <form method="POST" action="{{ route('admin.niche-blueprints.versions.destroy', [$blueprint, $draft]) }}" onsubmit="return confirm('Discard this draft? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Discard draft</button>
                            </form>
                        </div>
                    @endif
                </x-card>
            </div>

            {{-- Published version -------------------------------------------- --}}
            <div class="col-12">
                <x-card title="Published version">
                    @if ($published === null)
                        <x-empty-state icon="package" title="No published version yet." />
                    @else
                        <dl class="row">
                            <dt class="col-sm-3">Version</dt>
                            <dd class="col-sm-9">v{{ $published->version_number }}</dd>
                            <dt class="col-sm-3">Published at</dt>
                            <dd class="col-sm-9">{{ $published->published_at?->toDateTimeString() ?? '—' }}</dd>
                            <dt class="col-sm-3">Published by user id</dt>
                            <dd class="col-sm-9">{{ $published->published_by_user_id ?? '—' }}</dd>
                            <dt class="col-sm-3">Notes</dt>
                            <dd class="col-sm-9">{{ $published->notes ?? '—' }}</dd>
                        </dl>

                        <h6>Components</h6>
                        @if ($published->components->isEmpty())
                            <p class="text-muted">This version has no components.</p>
                        @else
                            <x-table :headers="['Key', 'Type', 'Required feature', 'Position']">
                                @foreach ($published->components as $component)
                                    <tr>
                                        <td><code>{{ $component->component_key }}</code></td>
                                        <td>{{ $component->component_type }}</td>
                                        <td>{{ $component->required_feature_key }}</td>
                                        <td>{{ $component->position }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        @endif

                        <form method="POST" action="{{ route('admin.niche-blueprints.versions.supersede', [$blueprint, $published]) }}" onsubmit="return confirm('Supersede the published version without publishing a replacement?');">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning btn-sm">Supersede</button>
                        </form>
                    @endif
                </x-card>
            </div>

            {{-- Version history -------------------------------------------- --}}
            <div class="col-12">
                <x-card title="Version history">
                    <x-table :headers="['Version', 'State', 'Notes', 'Published at']">
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
                                <td>{{ $version->notes ?? '—' }}</td>
                                <td>{{ $version->published_at?->toDateTimeString() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4"><x-empty-state icon="inbox" title="No versions yet." /></td>
                            </tr>
                        @endforelse
                    </x-table>
                </x-card>
            </div>
        </div>
    </section>
@endsection
