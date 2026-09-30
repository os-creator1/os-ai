{{--
    Website Builder redesign — the Business's own WebsiteForm rows
    (business_id-scoped, per the redesign's "reusable elsewhere"
    requirement).
--}}
@if ($forms->isEmpty())
    <x-empty-state icon="clipboard-list" title="No forms yet" description="A quote-request form is created automatically once your Contact page is generated." />
@else
    <div class="row">
        @foreach ($forms as $form)
            <div class="col-md-6 mb-3">
                <x-card :title="$form->name">
                    <p class="text-caption mb-2">{{ count($form->fields) }} field(s).</p>
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View submissions</x-button>
                </x-card>
            </div>
        @endforeach
    </div>
@endif
