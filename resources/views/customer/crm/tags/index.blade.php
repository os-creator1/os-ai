@extends('layouts/contentLayoutMaster')

{{--
    Contact Tags foundation — the minimum standalone CRM surface: create,
    rename and archive this Business's tags, and attach/detach a tag on a
    Contact by phone number. Every mutation goes through TagManager
    (ContactTagsController); this view only renders its result.
--}}

@section('title', 'Tags')

@section('content')
    @php
        $tagArgs = fn ($tag) => [$workspaceUid, $businessUid, $tag->uid];
    @endphp

    <section id="crm-tags" data-role="crm-tags">
        <div class="row">
            <div class="col-12 col-xl-8">
                <x-card title="New tag">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.tags.store', [$workspaceUid, $businessUid]) }}" class="d-flex gap-1 align-items-end" data-role="crm-tag-create">
                        @csrf
                        <div class="flex-grow-1">
                            <label for="crm-tag-name" class="form-label text-label">Name</label>
                            <input id="crm-tag-name" name="name" class="form-control" maxlength="191" required value="{{ old('name') }}">
                            @error('name')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <x-button type="submit" variant="primary">Create tag</x-button>
                    </form>
                </x-card>

                <x-card title="Tags" :padded="false">
                    <ol class="list-unstyled mb-0" data-role="crm-tag-list">
                        @forelse ($tags as $tag)
                            <li class="d-flex align-items-center justify-content-between border-bottom p-1" data-role="crm-tag">
                                <div class="d-flex align-items-center gap-1">
                                    <span>{{ $tag->name }}</span>
                                    @if ($tag->isArchived())
                                        <x-badge variant="accent">Archived</x-badge>
                                    @endif
                                </div>

                                <div class="d-flex align-items-center gap-1">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.tags.rename', $tagArgs($tag)) }}" class="d-flex gap-1" data-role="crm-tag-rename">
                                        @csrf
                                        <input name="name" class="form-control form-control-sm" value="{{ $tag->name }}" maxlength="191" required>
                                        <x-button type="submit" variant="outline" size="sm">Rename</x-button>
                                    </form>

                                    @unless ($tag->isArchived())
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.tags.archive', $tagArgs($tag)) }}" data-role="crm-tag-archive">
                                            @csrf
                                            <x-button type="submit" variant="ghost" size="sm">Archive</x-button>
                                        </form>

                                        <form method="POST" action="{{ route('customer.workspaces.businesses.tags.attach', $tagArgs($tag)) }}" class="d-flex flex-column gap-1" data-role="crm-tag-attach">
                                            @csrf
                                            <div class="d-flex gap-1">
                                                <input name="phone" class="form-control form-control-sm" placeholder="Contact phone" required>
                                                <x-button type="submit" variant="outline" size="sm">Add to contact</x-button>
                                            </div>
                                            @error('phone')
                                                <div class="text-danger small">{{ $message }}</div>
                                            @enderror
                                        </form>
                                    @endunless
                                </div>
                            </li>
                        @empty
                            <li class="p-1 text-muted">No tags yet — create one above.</li>
                        @endforelse
                    </ol>
                </x-card>
            </div>
        </div>
    </section>
@endsection
