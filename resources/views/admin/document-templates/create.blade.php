@extends('layouts/contentLayoutMaster')

@section('title', 'New platform template')

@section('content')
    <section id="admin-document-templates-create">
        <div class="row">
            <div class="col-12 col-lg-8">
                <x-card title="New platform template">
                    <p class="text-muted">
                        Creates a blank <strong>draft</strong>. You build it in the visual editor; it stays invisible to every business
                        until you publish it and assign it to a niche. Platform templates hold layout, explanatory text and merge fields only:
                        no business product, price, contact, payment amount, date or image.
                    </p>
                    <form method="POST" action="{{ route('admin.document-templates.store') }}" data-role="platform-template-create-form">
                        @csrf

                        <x-input name="name" label="Name" value="{{ old('name') }}" :error="$errors->first('name')" />

                        <x-select
                            name="template_type"
                            label="Type"
                            :options="['proposal' => 'Proposal', 'contract' => 'Contract']"
                            :selected="old('template_type', 'proposal')"
                            :error="$errors->first('template_type')"
                        />

                        <x-input name="description" label="Description (optional)" value="{{ old('description') }}" :error="$errors->first('description')" />

                        <x-button type="submit" variant="primary">Create and open editor</x-button>
                        <x-button :href="route('admin.document-templates.index')" variant="secondary">Cancel</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection
