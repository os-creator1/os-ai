{{-- Forms V1 — the shared create/edit definition fields. Presentation only: what
     a valid form IS (field bounds, types, the phone requirement for
     opportunities) belongs to FormManager / FormDefinitionNormalizer, and a
     refusal comes back through the `forms` error bag worded as the manager
     worded it. A row with an empty question is an unused spare row and is ignored.
     $form / $version are null on the create screen. --}}
<div class="form-group">
    <label for="name">Form name</label>
    <input type="text" id="name" name="name" class="form-control" value="{{ old('name', $form->name ?? '') }}" required>
</div>

<div class="form-group">
    <label for="intro">Introduction <span class="text-muted">(optional)</span></label>
    <textarea id="intro" name="intro" class="form-control" rows="3">{{ old('intro', $version->intro ?? '') }}</textarea>
</div>

<h5 class="mt-2">Pages</h5>
<p class="text-muted">
    An ordinary form is one page — leave every question on page 1. To make a questionnaire, put questions on more pages
    (up to {{ $limits['pages'] }}); visitors move through them in the order below. A page with no questions is ignored.
</p>

<div class="table-responsive">
    <table class="table table-sm" data-role="forms-pages">
        <thead>
            <tr>
                <th>Page</th>
                <th>Title <span class="text-muted">(optional)</span></th>
                <th>Order</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pageRows as $i => $pageRow)
                <tr data-page="{{ $pageRow['key'] }}">
                    <td>
                        <input type="hidden" name="pages[{{ $i }}][key]" value="{{ $pageRow['key'] }}">
                        {{ $pageRow['key'] }}
                    </td>
                    <td><input type="text" name="pages[{{ $i }}][title]" class="form-control form-control-sm" value="{{ $pageRow['title'] ?? '' }}" aria-label="Title of {{ $pageRow['key'] }}"></td>
                    <td><input type="number" min="1" max="99" name="pages[{{ $i }}][position]" class="form-control form-control-sm" value="{{ $pageRow['position'] ?? $i + 1 }}" aria-label="Order of {{ $pageRow['key'] }}"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<h5 class="mt-2">Questions</h5>
<p class="text-muted">Up to {{ $limits['fields'] }}. Leave a row blank to skip it. A form may have one phone number question — it is how a person is recognized.</p>
<p class="text-muted">
    "Save answer to" stores a question's answer in one of your business's contact fields (for example an event date), so it can be used in messages and automations as <code>@{{contact.field_name}}</code>.
    A blank answer never overwrites a value the contact already has.
    @if ($customFields->isEmpty())
        You have no custom fields yet — add them in Settings → Custom fields.
    @endif
</p>

<div class="table-responsive">
    <table class="table table-sm" data-role="forms-fields">
        <thead>
            <tr>
                <th>Question</th>
                <th>Page</th>
                <th>Answer type</th>
                <th>Required</th>
                <th>Options <span class="text-muted">(one per line, for "Pick one")</span></th>
                <th>Their name</th>
                <th>Save answer to <span class="text-muted">(contact field)</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                <tr data-row="{{ $i }}">
                    <td>
                        <input type="hidden" name="fields[{{ $i }}][key]" value="{{ $row['key'] ?? '' }}">
                        <input type="text" name="fields[{{ $i }}][label]" class="form-control form-control-sm" value="{{ $row['label'] ?? '' }}" aria-label="Question {{ $i + 1 }}">
                    </td>
                    <td>
                        @php($selectedPage = $row['page'] ?? $pageRows[0]['key'])
                        <select name="fields[{{ $i }}][page]" class="form-control form-control-sm" aria-label="Page {{ $i + 1 }}">
                            @foreach ($pageRows as $pageRow)
                                <option value="{{ $pageRow['key'] }}" @selected($selectedPage === $pageRow['key'])>{{ ! empty($pageRow['title']) ? $pageRow['title'] : $pageRow['key'] }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        @php($selectedType = $row['type'] ?? 'text')
                        <select name="fields[{{ $i }}][type]" class="form-control form-control-sm" aria-label="Answer type {{ $i + 1 }}">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input type="hidden" name="fields[{{ $i }}][required]" value="0">
                        <input type="checkbox" name="fields[{{ $i }}][required]" value="1" @checked(! empty($row['required']) && $row['required'] !== '0') aria-label="Required {{ $i + 1 }}">
                    </td>
                    <td>
                        <textarea name="fields[{{ $i }}][options]" class="form-control form-control-sm" rows="2" aria-label="Options {{ $i + 1 }}">{{ $row['options'] ?? '' }}</textarea>
                    </td>
                    <td>
                        <input type="hidden" name="fields[{{ $i }}][contact_name]" value="0">
                        <input type="checkbox" name="fields[{{ $i }}][contact_name]" value="1" @checked(! empty($row['contact_name']) && $row['contact_name'] !== '0') aria-label="Use as their name {{ $i + 1 }}">
                    </td>
                    <td>
                        @php($selectedField = (string) ($row['custom_field_uid'] ?? ''))
                        <select name="fields[{{ $i }}][custom_field_uid]" class="form-control form-control-sm" aria-label="Save answer to {{ $i + 1 }}" data-role="forms-save-to">
                            <option value="">Don't save to a contact field</option>
                            @foreach ($customFields as $customField)
                                <option value="{{ $customField->uid }}" @selected($selectedField === $customField->uid)>{{ $customField->label }} ({{ \App\Enums\CustomFields\CustomFieldType::from($customField->type)->label() }}){{ $customField->isArchived() ? ' — archived' : '' }}</option>
                            @endforeach
                        </select>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="form-row">
    <div class="form-group col-md-6">
        <label for="submit_label">Button label</label>
        <input type="text" id="submit_label" name="submit_label" class="form-control" value="{{ old('submit_label', $version->submit_label ?? '') }}" placeholder="Send">
    </div>
    <div class="form-group col-md-6">
        <label for="success_message">Thank-you message</label>
        <input type="text" id="success_message" name="success_message" class="form-control" value="{{ old('success_message', $version->success_message ?? '') }}" placeholder="Thanks — we got your message and will be in touch.">
    </div>
</div>

<div class="form-group">
    <input type="hidden" name="create_opportunity" value="0">
    <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="create_opportunity" name="create_opportunity" value="1" @checked((bool) old('create_opportunity', $version->create_opportunity ?? false))>
        <label class="custom-control-label" for="create_opportunity">Create an opportunity for each response</label>
    </div>
    <small class="form-text text-muted">Needs a phone number question, so the response can be tied to a person.</small>
</div>

<div class="form-group">
    <label for="opportunity_pipeline_id">Pipeline for new opportunities</label>
    @php($selectedPipeline = (string) old('opportunity_pipeline_id', $version->opportunity_pipeline_id ?? ''))
    <select id="opportunity_pipeline_id" name="opportunity_pipeline_id" class="form-control">
        <option value="">First active pipeline</option>
        @foreach ($pipelines as $pipeline)
            <option value="{{ $pipeline->id }}" @selected($selectedPipeline === (string) $pipeline->id)>{{ $pipeline->name }}</option>
        @endforeach
    </select>
</div>
