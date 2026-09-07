@extends('layouts/contentLayoutMaster')

@section('title', $automation ? 'Edit automation' : 'Create automation')

@php
    $editing = $automation !== null;
    $selectedTrigger = old('trigger_type', $automation?->trigger_type?->value ?? \App\Enums\Automation\AutomationTriggerType::ContactCreated->value);
    $selectedAction = old('action_type', $automation?->action_type?->value ?? \App\Enums\Automation\AutomationActionType::SendMessage->value);
    $triggerConfig = $automation?->trigger_config ?? [];
    $actionConfig = $automation?->action_config ?? [];
    $formAction = $editing
        ? route('customer.workspaces.businesses.automations.update', [$workspaceUid, $businessUid, $automation->uid])
        : route('customer.workspaces.businesses.automations.store', [$workspaceUid, $businessUid]);
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $editing ? 'Edit automation' : 'Create automation' }}</h4>
            <x-button variant="outline" size="sm" icon="arrow-left"
                      :href="route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid])">
                Back
            </x-button>
        </div>
    </div>

    @if($errors->any())
        <x-card :padded="true" class="mb-2">
            <ul class="mb-0 text-danger">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <form method="post" action="{{ $formAction }}" data-role="automation-form"
          data-update-field-action="{{ \App\Enums\Automation\AutomationActionType::UpdateContactField->value }}">
        @csrf

        <x-card title="1. Name" :padded="true" class="mb-2">
            <div class="mb-1">
                <label class="form-label" for="name">Automation name</label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $automation?->name) }}" required maxlength="255">
            </div>
        </x-card>

        <x-card title="2. Trigger" :padded="true" class="mb-2">
            <div class="mb-1">
                <label class="form-label" for="trigger_type">When</label>
                <select id="trigger_type" name="trigger_type" class="form-select" data-role="trigger-type">
                    @foreach($triggerTypes as $type)
                        <option value="{{ $type->value }}" @selected($selectedTrigger === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-1">
                <label class="form-label" for="contact_group_id">Contact group <span class="text-caption" data-role="group-hint">(optional for "Contact created")</span></label>
                <select id="contact_group_id" name="contact_group_id" class="form-select @error('contact_group_id') is-invalid @enderror" data-role="group-select">
                    <option value="" data-role="any-group">Any group in this Business</option>
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) old('contact_group_id', $triggerConfig['contact_group_id'] ?? '') === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
                <p class="text-caption mb-0" data-role="group-required-note" hidden>
                    "Update contact field" writes one group's field, so this automation must be limited to that contact group.
                </p>
            </div>

            <div data-role="trigger-config" data-trigger="{{ \App\Enums\Automation\AutomationTriggerType::ContactDateReached->value }}">
                <div class="mb-1">
                    <label class="form-label" for="date_field_id">Date field</label>
                    <select id="date_field_id" name="date_field_id" class="form-select">
                        <option value="">Select a date field…</option>
                        @foreach($dateFields as $field)
                            <option value="{{ $field->id }}" data-group="{{ $field->contact_group_id }}" @selected((string) old('date_field_id', $triggerConfig['date_field_id'] ?? '') === (string) $field->id)>{{ $field->label }}</option>
                        @endforeach
                    </select>
                    @if($dateFields->isEmpty())
                        <p class="text-caption mb-0">No date fields exist yet — add a date custom field to a contact group first.</p>
                    @endif
                </div>
                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label" for="offset">Send</label>
                        <select id="offset" name="offset" class="form-select">
                            @foreach($offsets as $offset)
                                <option value="{{ $offset }}" @selected(old('offset', $triggerConfig['offset'] ?? '0 day') === $offset)>{{ $offset === '0 day' ? 'On the day' : $offset . ' before' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label" for="send_at">At (Business local time)</label>
                        <input type="time" id="send_at" name="send_at" class="form-control" value="{{ old('send_at', $triggerConfig['send_at'] ?? '09:00') }}">
                    </div>
                </div>
            </div>
        </x-card>

        <x-card title="3. Action" :padded="true" class="mb-2">
            <div class="mb-1">
                <label class="form-label" for="action_type">Then</label>
                <select id="action_type" name="action_type" class="form-select" data-role="action-type">
                    @foreach($actionTypes as $type)
                        <option value="{{ $type->value }}" @selected($selectedAction === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div data-role="action-config" data-action="{{ \App\Enums\Automation\AutomationActionType::SendMessage->value }}">
                <div class="row">
                    <div class="col-md-4 mb-1">
                        <label class="form-label" for="sms_type">Channel type</label>
                        <select id="sms_type" name="sms_type" class="form-select">
                            <option value="plain" @selected(old('sms_type', $actionConfig['sms_type'] ?? 'plain') === 'plain')>SMS</option>
                            <option value="mms" @selected(old('sms_type', $actionConfig['sms_type'] ?? 'plain') === 'mms')>MMS</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-1">
                        <label class="form-label" for="sender_id">Sender</label>
                        <select id="sender_id" name="sender_id" class="form-select">
                            <option value="">Select a sender…</option>
                            @foreach($senders as $sender)
                                <option value="{{ $sender }}" @selected(old('sender_id', $actionConfig['sender_id'] ?? '') === (string) $sender)>{{ $sender }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 mb-1">
                        <label class="form-label" for="sending_server">Messaging channel</label>
                        <select id="sending_server" name="sending_server" class="form-select">
                            <option value="">Select a channel…</option>
                            @foreach($channels as $channel)
                                <option value="{{ $channel->sending_server }}" @selected((string) old('sending_server', $actionConfig['sending_server'] ?? '') === (string) $channel->sending_server)>{{ $channel->sendingServer->name }}</option>
                            @endforeach
                        </select>
                        @if($channels->isEmpty())
                            <p class="text-caption mb-0">No active messaging channel is assigned to this Business yet.</p>
                        @endif
                    </div>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="message">Message</label>
                    <textarea id="message" name="message" class="form-control" rows="3" maxlength="1600">{{ old('message', $actionConfig['message'] ?? '') }}</textarea>
                    <p class="text-caption mb-0">Contact fields can be inserted as tags, e.g. @{{FIRST_NAME}} — written as {FIRST_NAME}.</p>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="media_url">MMS media URL <span class="text-caption">(MMS only)</span></label>
                    <input type="url" id="media_url" name="media_url" class="form-control" value="{{ old('media_url', $actionConfig['media_url'] ?? '') }}" maxlength="2048">
                </div>
            </div>

            <div data-role="action-config" data-action="{{ \App\Enums\Automation\AutomationActionType::UpdateContactField->value }}">
                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label" for="field_id">Custom field <span class="text-caption">(of the selected contact group)</span></label>
                        <select id="field_id" name="field_id" class="form-select @error('field_id') is-invalid @enderror" data-role="field-select">
                            <option value="">Select a field…</option>
                            @foreach($customFields as $field)
                                <option value="{{ $field->id }}" data-group="{{ $field->contact_group_id }}" @selected((string) old('field_id', $actionConfig['field_id'] ?? '') === (string) $field->id)>{{ $field->contactGroup?->name }} › {{ $field->label }}</option>
                            @endforeach
                        </select>
                        <p class="text-caption mb-0" data-role="field-hint">Only fields of the selected contact group are listed.</p>
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label" for="value">Set value to</label>
                        <input type="text" id="value" name="value" class="form-control" value="{{ old('value', $actionConfig['value'] ?? '') }}" maxlength="255">
                    </div>
                </div>
            </div>
        </x-card>

        <x-card title="4. Status" :padded="true" class="mb-2">
            <div class="form-check">
                <input type="hidden" name="enabled" value="0">
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1"
                       @checked(old('enabled', $automation ? $automation->status === \App\Models\Automation::STATUS_ACTIVE : true))>
                <label class="form-check-label" for="enabled">Enabled</label>
            </div>
        </x-card>

        <x-button type="submit" variant="primary">{{ $editing ? 'Save changes' : 'Create automation' }}</x-button>
    </form>

    <script>
        (function () {
            var form = document.querySelector('[data-role="automation-form"]');
            if (!form) { return; }

            var updateFieldAction = form.getAttribute('data-update-field-action');
            var groupSelect = form.querySelector('[data-role="group-select"]');
            var anyGroupOption = groupSelect.querySelector('[data-role="any-group"]');
            var groupHint = form.querySelector('[data-role="group-hint"]');
            var groupRequiredNote = form.querySelector('[data-role="group-required-note"]');

            // Show only the options that belong to the selected group; a
            // field belongs to exactly one group, so nothing may look
            // usable across groups.
            function filterByGroup(select, groupId, requireGroup) {
                var visible = 0;
                select.querySelectorAll('option[data-group]').forEach(function (opt) {
                    var match = groupId !== '' && opt.getAttribute('data-group') === groupId;
                    var show = requireGroup ? match : (groupId === '' || match);
                    opt.hidden = !show;
                    opt.disabled = !show;
                    if (show) { visible++; }
                    if (!show && opt.selected) { select.value = ''; }
                });
                return visible;
            }

            function sync() {
                var trigger = form.querySelector('[data-role="trigger-type"]').value;
                var action = form.querySelector('[data-role="action-type"]').value;
                var needsGroup = action === updateFieldAction;
                var groupId = groupSelect.value;

                form.querySelectorAll('[data-role="trigger-config"]').forEach(function (el) {
                    el.hidden = el.getAttribute('data-trigger') !== trigger;
                });
                form.querySelectorAll('[data-role="action-config"]').forEach(function (el) {
                    el.hidden = el.getAttribute('data-action') !== action;
                });

                // "Any group" is not a valid audience for "Update contact field".
                anyGroupOption.hidden = needsGroup;
                anyGroupOption.disabled = needsGroup;
                groupSelect.required = needsGroup;
                groupHint.textContent = needsGroup ? '(required for "Update contact field")' : '(optional for "Contact created")';
                groupRequiredNote.hidden = !needsGroup;

                filterByGroup(form.querySelector('[data-role="field-select"]'), groupId, true);
                filterByGroup(form.querySelector('#date_field_id'), groupId, false);
            }

            form.querySelector('[data-role="trigger-type"]').addEventListener('change', sync);
            form.querySelector('[data-role="action-type"]').addEventListener('change', sync);
            groupSelect.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
