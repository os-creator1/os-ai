@extends('layouts/contentLayoutMaster')

@section('title', 'Create Blueprint')

@section('content')
    <section id="admin-niche-blueprints-create">
        <div class="row">
            <div class="col-12">
                @if (session('flash_error'))
                    <div class="alert alert-danger">{{ session('flash_error') }}</div>
                @endif
            </div>
            <div class="col-12 col-lg-8">
                <x-card title="Create Blueprint">
                    <form method="POST" action="{{ route('admin.niche-blueprints.store') }}">
                        @csrf

                        <x-input
                            name="key"
                            label="Key"
                            value="{{ old('key') }}"
                            help="Stable identity, e.g. photo_booth. Lowercase, hyphen/underscore separated. Never editable after creation."
                            :error="$errors->first('key')"
                        />

                        <x-input
                            name="display_name"
                            label="Display name"
                            value="{{ old('display_name') }}"
                            :error="$errors->first('display_name')"
                        />

                        <x-select
                            name="vertical_key"
                            label="Vertical (optional)"
                            :options="['' => '(none)'] + $verticals->pluck('display_name', 'key')->all()"
                            :selected="old('vertical_key')"
                            help="At most one Blueprint may bind to a given vertical — the signup resolution key (§7.1)."
                        />

                        <x-select
                            name="broad_industry"
                            label="Broad industry fallback (optional)"
                            :options="['' => '(none)'] + collect($industries)->mapWithKeys(fn ($industry) => [$industry->value => $industry->value])->all()"
                            :selected="old('broad_industry')"
                            help="Used when a Business has no vertical_key but an industry value matches."
                        />

                        <x-button type="submit" variant="primary">Create Blueprint</x-button>
                        <x-button :href="route('admin.niche-blueprints.index')" variant="secondary">Cancel</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection
