@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities — add a deal. It is always with one contact of this
    Business (picked from the Business's own contacts) and starts in the
    pipeline's New inquiry stage unless another stage is chosen.
--}}

@section('title', 'Add opportunity')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
    @endphp

    <section id="crm-opportunity-create">
        <div class="mb-1">
            <a href="{{ route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid]) }}">&larr; {{ $pipeline->name }}</a>
        </div>

        <div class="row">
            <div class="col-12 col-lg-7">
                <x-card title="Add opportunity">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.crm.opportunities.store', [$workspaceUid, $businessUid]) }}" data-role="crm-create-form">
                        @csrf

                        <x-input name="title" label="Opportunity name" maxlength="150" required :value="old('title')" :error="$errors->first('title')" placeholder="e.g. Kitchen renovation quote" />

                        <div class="ds-field mb-3">
                            <label for="crm-contact" class="form-label text-label">Contact</label>
                            <select id="crm-contact" name="contact" class="form-select" required data-role="crm-contact-select"
                                    data-search-url="{{ route('customer.workspaces.businesses.crm.contacts.search', [$workspaceUid, $businessUid]) }}">
                                @if ($contact !== null)
                                    <option value="{{ $contact['uid'] }}" selected>{{ $contact['name'] !== null ? $contact['name'] . ' · ' . $contact['phone'] : $contact['phone'] }}</option>
                                @endif
                            </select>
                            <div class="form-text text-caption">Search this Business's contacts by name or phone.</div>
                            @error('contact')
                                <div class="invalid-feedback d-block text-caption">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label for="crm-create-pipeline" class="form-label text-label">Pipeline</label>
                                <select id="crm-create-pipeline" name="pipeline" class="form-select mb-3" data-role="crm-create-pipeline">
                                    @foreach ($pipelines as $option)
                                        <option value="{{ $option->uid }}" @selected($option->is($pipeline))
                                                data-url="{{ route('customer.workspaces.businesses.crm.opportunities.create', [$workspaceUid, $businessUid, 'pipeline' => $option->uid]) }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="crm-create-stage" class="form-label text-label">Stage</label>
                                <select id="crm-create-stage" name="stage" class="form-select mb-3">
                                    @foreach ($stages as $stage)
                                        <option value="{{ $stage->uid }}" @selected(old('stage', $startingStage->uid) === $stage->uid)>{{ $stage->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <x-input name="value" type="number" label="Value (optional)" min="0" step="0.01" :value="old('value')" :error="$errors->first('value')" />

                        <x-button type="submit" variant="primary">Add opportunity</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')
    <script>
        (function () {
            var contact = $('[data-role="crm-contact-select"]');

            contact.select2({
                width: '100%',
                placeholder: 'Search contacts',
                minimumInputLength: 0,
                ajax: {
                    url: contact.data('search-url'),
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { q: params.term || '' }; },
                    processResults: function (data) { return { results: data.results }; }
                }
            });

            // Another pipeline has other stages: reload the form for it, keeping
            // what has been typed so far.
            document.querySelector('[data-role="crm-create-pipeline"]').addEventListener('change', function (event) {
                var url = new URL(event.target.selectedOptions[0].getAttribute('data-url'), window.location.href);
                if (contact.val()) { url.searchParams.set('contact', contact.val()); }
                window.location.assign(url.toString());
            });
        })();
    </script>
@endsection
