@extends('layouts/contentLayoutMaster')

@section('title', 'Blueprint Workspace — '.$blueprint->display_name)

@section('content')
    <section id="blueprint-workspace" data-blueprint-mode="active">
        {{-- Blueprint Mode banner: obviously not a real Business. --}}
        <div class="alert alert-warning border-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="status" id="blueprint-mode-banner" style="background:#fff3cd;color:#3d2e00;">
            <div>
                <div class="fw-bold text-uppercase small">Blueprint Mode — editing a niche, not a Business</div>
                <div class="fs-5">Editing Niche Blueprint <strong>{{ $blueprint->display_name }}</strong> · Draft v{{ $draft->version_number }}</div>
                <div class="small" style="color:#5c4600;">
                    Safety mode is on: nothing here sends messages, takes payments, connects providers or publishes a website.
                    No contacts, deals, bookings or other customer data are part of a Blueprint.
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('admin.niche-blueprints.workspace.save', $blueprint) }}">
                    @csrf
                    <input type="hidden" name="surface" value="{{ $surface }}">
                    <input type="hidden" name="notes" value="{{ $draft->notes }}">
                    <button type="submit" class="btn btn-outline-primary" id="blueprint-save-draft">Save Draft</button>
                </form>
                <form method="POST" action="{{ route('admin.niche-blueprints.workspace.publish', $blueprint) }}"
                      onsubmit="return confirm('Publish this version? New Businesses in this niche will receive it. Existing Businesses are never overwritten.');">
                    @csrf
                    <button type="submit" class="btn btn-primary" id="blueprint-publish">Publish v{{ $draft->version_number }}</button>
                </form>
                <form method="POST" action="{{ route('admin.niche-blueprints.workspace.exit', $blueprint) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary" id="blueprint-exit">Exit Blueprint</button>
                </form>
            </div>
        </div>

        @if (session('flash_success'))
            <div class="alert alert-success">{{ session('flash_success') }}</div>
        @endif
        @if (session('flash_error'))
            <div class="alert alert-danger">{{ session('flash_error') }}</div>
        @endif

        <div class="row">
            {{-- Business-like navigation over the configuration surfaces --}}
            <div class="col-md-3 mb-3">
                <div class="list-group" id="blueprint-surface-nav">
                    @foreach ($surfaces as $key => $meta)
                        <a href="{{ route('admin.niche-blueprints.workspace.show', [$blueprint, $key]) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $surface === $key ? 'active' : '' }}"
                           data-surface="{{ $key }}">
                            {{ $meta['label'] }}
                            <span class="badge bg-secondary rounded-pill">{{ $counts[$key] ?? 0 }}</span>
                        </a>
                    @endforeach
                </div>
                <p class="small text-muted mt-2">
                    Published: {{ $published ? 'v'.$published->version_number : 'none yet' }}.
                    Changes are saved to the draft as you go.
                </p>
            </div>

            <div class="col-md-9">
                <h4 class="mb-3">{{ $surfaces[$surface]['label'] }}</h4>

                @forelse ($rows as $row)
                    @php
                        $component = $row['component'];
                        $definition = $row['definition'];
                        $values = $definition->inputFromPayload(is_array($component->payload) ? $component->payload : []);
                    @endphp
                    <div class="card mb-3" data-component-key="{{ $component->component_key }}">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-info">{{ $definition->typeLabel() }}</span>
                                <span class="badge {{ $definition->updatePolicy()->value === 'live' ? 'bg-warning text-dark' : 'bg-light text-dark' }}">
                                    {{ $definition->updatePolicy()->value === 'live' ? 'Live — reaches existing Businesses' : 'Copied — version based' }}
                                </span>
                                <span class="ms-2">{{ $row['summary'] }}</span>
                            </div>
                            <code class="small text-muted">{{ $component->component_key }}</code>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.niche-blueprints.workspace.components.update', [$blueprint, $surface, $component->id]) }}">
                                @csrf
                                @method('PATCH')
                                @include('admin.niche-blueprints.workspace._fields', ['fields' => $definition->formFields(), 'values' => $values])
                                <button type="submit" class="btn btn-sm btn-primary">Save component</button>
                            </form>
                            <form method="POST" class="d-inline"
                                  action="{{ route('admin.niche-blueprints.workspace.components.destroy', [$blueprint, $surface, $component->id]) }}"
                                  onsubmit="return confirm('Remove this component from the draft?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger mt-2">Remove</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-muted">Nothing configured on this surface yet.</p>
                @endforelse

                @php $component = null; @endphp
                @foreach ($definitions as $type => $definition)
                    <details class="card mb-3" @if(session('flash_error') && old('component_type') === $type) open @endif>
                        <summary class="card-header">Add {{ strtolower($definition->typeLabel()) }}</summary>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.niche-blueprints.workspace.components.store', [$blueprint, $surface]) }}">
                                @csrf
                                <input type="hidden" name="component_type" value="{{ $type }}">
                                @include('admin.niche-blueprints.workspace._fields', ['fields' => $definition->formFields(), 'values' => []])
                                <button type="submit" class="btn btn-sm btn-primary">Add to draft</button>
                            </form>
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>
@endsection
