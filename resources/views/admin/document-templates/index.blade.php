@extends('layouts/contentLayoutMaster')

@section('title', 'Proposal Templates')

@section('content')
    <section id="admin-document-templates-index" data-role="platform-templates">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success" data-role="flash-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger" data-role="flash-error">{{ session('flash_error') }}</div>
                @endif
            </div>
            <div class="col-12">
                <x-card title="Proposal and contract templates">
                    <x-slot:actions>
                        <x-button :href="route('admin.document-templates.create')" variant="primary" icon="plus">
                            New template
                        </x-button>
                    </x-slot:actions>

                    <p class="text-muted">
                        Platform-owned layouts. A template is recommended to businesses in a niche only when it is
                        <strong>published</strong> and assigned to that niche in a <strong>published blueprint version</strong>.
                        Nothing is copied into a business: when a business uses a template it gets its own draft.
                    </p>

                    <x-table :headers="['Name', 'Type', 'Status', 'Assigned niches (live)', 'Updated', '']">
                        @forelse ($templates as $template)
                            @php
                                $liveNiches = $niches[$template->uid] ?? [];
                                $statusBadge = match ($template->status->value) {
                                    'active' => ['success', 'Published'],
                                    'archived' => ['neutral', 'Disabled'],
                                    default => ['accent', 'Draft'],
                                };
                            @endphp
                            <tr data-role="platform-template-row" data-template-uid="{{ $template->uid }}">
                                <td>
                                    <a href="{{ route('admin.document-templates.edit', $template) }}">{{ $template->name }}</a>
                                    @if ($template->description)
                                        <div class="text-muted small">{{ \Illuminate\Support\Str::limit($template->description, 110) }}</div>
                                    @endif
                                </td>
                                <td>{{ ucfirst($template->template_type->value) }}</td>
                                <td><x-badge :variant="$statusBadge[0]">{{ $statusBadge[1] }}</x-badge></td>
                                <td>
                                    @forelse ($liveNiches as $niche)
                                        <x-badge variant="accent">{{ $niche }}</x-badge>
                                    @empty
                                        <span class="text-muted">None</span>
                                    @endforelse
                                </td>
                                <td>{{ $template->updated_at?->diffForHumans() }}</td>
                                <td class="text-nowrap">
                                    <a href="{{ route('admin.document-templates.edit', $template) }}">Edit</a> &middot;
                                    <a href="{{ route('admin.document-templates.preview', $template) }}" target="_blank" rel="noopener">Preview</a> &middot;
                                    <a href="{{ route('admin.document-templates.niches', $template) }}">Niches</a>
                                    @if ($template->status->value === 'active')
                                        <form method="POST" action="{{ route('admin.document-templates.disable', $template) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-link btn-sm p-0 ms-1" data-role="template-disable">Disable</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.document-templates.publish', $template) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-link btn-sm p-0 ms-1" data-role="template-publish">{{ $template->status->value === 'archived' ? 'Enable' : 'Publish' }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-empty-state icon="inbox" title="No platform templates yet." /></td>
                            </tr>
                        @endforelse
                    </x-table>

                    <x-pagination :paginator="$templates" />
                </x-card>
            </div>
        </div>
    </section>
@endsection
