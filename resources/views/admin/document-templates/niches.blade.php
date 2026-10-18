@extends('layouts/contentLayoutMaster')

@section('title', 'Assign to niches')

@section('content')
    <section id="admin-document-templates-niches" data-role="platform-template-niches">
        <div class="row">
            <div class="col-12">
                @if (session('flash_success'))
                    <div class="alert alert-success" data-role="flash-success">{{ session('flash_success') }}</div>
                @endif
                @if (session('flash_error'))
                    <div class="alert alert-danger" data-role="flash-error">{{ session('flash_error') }}</div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
            </div>

            <div class="col-12">
                <x-card :title="'Assign '.$template->name.' to niches'">
                    <x-slot:actions>
                        <x-button :href="route('admin.document-templates.edit', $template)" variant="secondary">Back to editor</x-button>
                    </x-slot:actions>

                    @if ($template->status->value !== 'active')
                        <div class="alert alert-warning" data-role="template-not-live">
                            This template is <strong>{{ $template->status->value === 'archived' ? 'disabled' : 'a draft' }}</strong>, so no business will see it
                            even when it is assigned. Publish it from the editor or the template list.
                        </div>
                    @endif

                    <p class="text-muted">
                        Assigning is <strong>two steps</strong>. <strong>1. Save</strong> records your choice on each niche blueprint draft version;
                        nothing changes for businesses yet. <strong>2. Publish blueprint version</strong> (per niche, below) makes it live:
                        businesses in that niche then see this template under &ldquo;Recommended for your business&rdquo;.
                        Disabling the template removes it from recommendations immediately, whatever the blueprint says.
                    </p>

                    <form method="POST" action="{{ route('admin.document-templates.niches.update', $template) }}" data-role="niche-assignment-form">
                        @csrf
                        @method('PUT')

                        <x-table :headers="['Assigned', 'Niche blueprint', 'Industry / vertical', 'Live', 'Draft']">
                            @forelse ($rows as $row)
                                @php $blueprint = $row['blueprint']; @endphp
                                <tr data-role="niche-row" data-blueprint-uid="{{ $blueprint->uid }}">
                                    <td>
                                        <input type="checkbox" class="form-check-input" name="blueprints[]" value="{{ $blueprint->uid }}"
                                               id="niche-{{ $blueprint->uid }}" @checked($row['desired']) aria-label="Assign to {{ $blueprint->display_name }}">
                                    </td>
                                    <td>
                                        <label for="niche-{{ $blueprint->uid }}">{{ $blueprint->display_name }}</label>
                                        <code class="ms-1">{{ $blueprint->key }}</code>
                                        @unless ($blueprint->is_active)<x-badge variant="neutral">Inactive</x-badge>@endunless
                                    </td>
                                    <td>{{ $blueprint->vertical_key ?? $blueprint->broad_industry ?? '-' }}</td>
                                    <td>
                                        @if ($row['published'] === null)
                                            <span class="text-muted">No published version</span>
                                        @elseif ($row['live'])
                                            <x-badge variant="success">Recommended in v{{ $row['published']->version_number }}</x-badge>
                                        @else
                                            <span class="text-muted">Not in v{{ $row['published']->version_number }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['draft'] === null)
                                            <span class="text-muted">No draft</span>
                                        @elseif ($row['pending'])
                                            <x-badge variant="warning">v{{ $row['draft']->version_number }}: {{ $row['desired'] ? 'will add' : 'will remove' }}</x-badge>
                                        @else
                                            <x-badge variant="accent">v{{ $row['draft']->version_number }} draft</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-empty-state icon="inbox" title="No niche blueprints exist yet." /></td></tr>
                            @endforelse
                        </x-table>

                        <x-button type="submit" variant="primary" data-role="niche-save">1. Save to blueprint drafts</x-button>
                    </form>
                </x-card>
            </div>

            @php $draftRows = $rows->filter(fn ($row) => $row['draft'] !== null); @endphp
            @if ($draftRows->isNotEmpty())
                <div class="col-12">
                    <x-card title="2. Publish blueprint versions" data-role="niche-publish-card">
                        <p class="text-muted">
                            A niche blueprint has one draft and one published version. Publishing releases the <strong>whole draft</strong>,
                            including any other change on it, and supersedes the current published version. Review a draft on
                            <a href="{{ route('admin.niche-blueprints.index') }}">Niche Blueprints</a> first if unsure.
                        </p>
                        <x-table :headers="['Niche blueprint', 'Draft', 'Change for this template', '']">
                            @foreach ($draftRows as $row)
                                @php $blueprint = $row['blueprint']; @endphp
                                <tr data-role="niche-publish-row" data-blueprint-uid="{{ $blueprint->uid }}">
                                    <td>{{ $blueprint->display_name }}</td>
                                    <td>v{{ $row['draft']->version_number }} &middot; {{ $row['draft_components'] }} component(s)</td>
                                    <td>
                                        @if ($row['pending'])
                                            {{ $row['desired'] ? 'Adds this template' : 'Removes this template' }}
                                        @else
                                            <span class="text-muted">None (draft is unrelated to this template)</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('admin.document-templates.niches.publish', $template) }}"
                                              onsubmit="return confirm({{ json_encode('Publish '.$blueprint->display_name.' blueprint version '.$row['draft']->version_number.'? This makes the whole draft live for businesses in this niche.', JSON_HEX_APOS | JSON_HEX_QUOT) }});">
                                            @csrf
                                            <input type="hidden" name="blueprint" value="{{ $blueprint->uid }}">
                                            <input type="hidden" name="confirm" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" data-role="niche-publish">Publish blueprint version</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    </x-card>
                </div>
            @endif
        </div>
    </section>
@endsection
