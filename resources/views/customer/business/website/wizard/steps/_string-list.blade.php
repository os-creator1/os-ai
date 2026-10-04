{{--
    A one-per-row list of short strings (service areas). Rows are added,
    removed and re-ordered in place; the entered order is the owner's
    priority and is preserved. Nothing is split apart by a delimiter.
--}}
@php
    $noun = ($step['target_module'] ?? null) === 'business_location' ? 'location' : 'entry';
    $placeholder = $noun === 'location' ? 'City or area, e.g. Naperville, IL' : '';
    $entries = array_values($entries) !== [] ? array_values($entries) : [''];
@endphp
<div data-repeatable>
    <div data-repeatable-rows>
        @foreach ($entries as $entry)
            @include('customer.business.website.wizard.steps._string-row', ['value' => $entry])
        @endforeach
    </div>
    <template data-row-template>
        @include('customer.business.website.wizard.steps._string-row', ['value' => ''])
    </template>
    <button type="button" class="btn btn-link px-0" data-add-row>+ Add another {{ $noun }}</button>
</div>
