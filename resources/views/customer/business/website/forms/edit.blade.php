@extends('layouts/contentLayoutMaster')

@section('title', 'Edit form')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Edit form — {{ $form->name }}</h4>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]) }}">Back to Forms</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    <x-card>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.forms.update', [$workspaceUid, $businessUid, $form->uid]) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="name" class="form-label text-label">Form name</label>
                <input type="text" name="name" id="name" class="form-control" maxlength="120" value="{{ old('name', $form->name) }}" required>
                @error('name')<div class="text-danger text-caption">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="submit_label" class="form-label text-label">Button label</label>
                <input type="text" name="submit_label" id="submit_label" class="form-control" maxlength="40" value="{{ old('submit_label', $form->submit_label) }}" required>
                @error('submit_label')<div class="text-danger text-caption">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="location_uid" class="form-label text-label">Location</label>
                <select name="location_uid" id="location_uid" class="form-select" required>
                    @foreach ($locations as $location)
                        <option value="{{ $location->uid }}" @selected(old('location_uid', optional($form->location)->uid) === $location->uid)>{{ $location->name }}</option>
                    @endforeach
                </select>
                <div class="form-text text-caption">Every inquiry through this form, and the contact and opportunity it creates, belongs to this location.</div>
                @error('location_uid')<div class="text-danger text-caption">{{ $message }}</div>@enderror
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input" @checked(old('is_active', $form->is_active))>
                <label for="is_active" class="form-check-label">Accepting submissions</label>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="create_opportunity" id="create_opportunity" value="1" class="form-check-input" @checked(old('create_opportunity', $form->create_opportunity))>
                <label for="create_opportunity" class="form-check-label">Open an opportunity in my pipeline for each inquiry</label>
            </div>

            @if ($pipelines->count() > 1)
                <div class="mb-3">
                    <label for="crm_pipeline_uid" class="form-label text-label">Pipeline</label>
                    <select name="crm_pipeline_uid" id="crm_pipeline_uid" class="form-select">
                        <option value="">First pipeline</option>
                        @foreach ($pipelines as $pipeline)
                            <option value="{{ $pipeline->uid }}" @selected(old('crm_pipeline_uid', optional($pipelines->firstWhere('id', $form->crm_pipeline_id))->uid) === $pipeline->uid)>{{ $pipeline->name }}</option>
                        @endforeach
                    </select>
                    @error('crm_pipeline_uid')<div class="text-danger text-caption">{{ $message }}</div>@enderror
                </div>
            @endif

            <x-button type="submit" variant="primary">Save form</x-button>
        </form>
    </x-card>
@endsection
