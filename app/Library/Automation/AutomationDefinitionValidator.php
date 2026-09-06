<?php

namespace App\Library\Automation;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Senderid;
use Illuminate\Validation\ValidationException;

/**
 * B4 Business Automations — turns raw form input into a bounded,
 * Business-verified trigger_config / action_config (contract §12.3).
 * Configuration is never mass-assigned from the request: every referenced
 * resource (contact group, date field, sender, channel, custom field) is
 * re-resolved INSIDE the already-resolved Business, and only an
 * allowlisted, normalized array is ever persisted.
 */
class AutomationDefinitionValidator
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function triggerConfig(Business $business, AutomationTriggerType $type, array $input): array
    {
        return match ($type) {
            AutomationTriggerType::ContactDateReached => $this->dateReachedConfig($business, $input),
            AutomationTriggerType::ContactCreated => $this->contactCreatedConfig($business, $input),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function actionConfig(Business $business, AutomationActionType $type, array $input): array
    {
        return match ($type) {
            AutomationActionType::SendMessage => $this->sendMessageConfig($business, $input),
            AutomationActionType::UpdateContactField => $this->updateContactFieldConfig($business, $input),
        };
    }

    private function dateReachedConfig(Business $business, array $input): array
    {
        $group = $this->businessGroup($business, $input['contact_group_id'] ?? null);

        if ($group === null) {
            throw ValidationException::withMessages(['contact_group_id' => 'Select a contact group that belongs to this Business.']);
        }

        $field = ContactGroupFields::query()
            ->where('contact_group_id', $group->id)
            ->whereIn('type', [ContactGroupFields::TYPE_DATE, ContactGroupFields::TYPE_DATETIME])
            ->find((int) ($input['date_field_id'] ?? 0));

        if ($field === null) {
            throw ValidationException::withMessages(['date_field_id' => 'Select a date field that belongs to the chosen contact group.']);
        }

        $offset = (string) ($input['offset'] ?? '');

        if (! in_array($offset, AutomationTriggerEvaluator::OFFSET_ALLOWLIST, true)) {
            throw ValidationException::withMessages(['offset' => 'Choose a supported offset.']);
        }

        $sendAt = (string) ($input['send_at'] ?? '');

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendAt)) {
            throw ValidationException::withMessages(['send_at' => 'Enter a time of day as HH:MM.']);
        }

        return [
            'contact_group_id' => (int) $group->id,
            'date_field_id' => (int) $field->id,
            'offset' => $offset,
            'send_at' => $sendAt,
        ];
    }

    private function contactCreatedConfig(Business $business, array $input): array
    {
        $groupId = $input['contact_group_id'] ?? null;

        if ($groupId === null || $groupId === '') {
            return ['contact_group_id' => null];
        }

        $group = $this->businessGroup($business, $groupId);

        if ($group === null) {
            throw ValidationException::withMessages(['contact_group_id' => 'Select a contact group that belongs to this Business.']);
        }

        return ['contact_group_id' => (int) $group->id];
    }

    private function sendMessageConfig(Business $business, array $input): array
    {
        $smsType = (string) ($input['sms_type'] ?? 'plain');

        if (! in_array($smsType, ['plain', 'mms'], true)) {
            throw ValidationException::withMessages(['sms_type' => 'Only SMS and MMS are supported.']);
        }

        $message = trim((string) ($input['message'] ?? ''));

        if ($message === '' || mb_strlen($message) > 1600) {
            throw ValidationException::withMessages(['message' => 'Enter a message of up to 1600 characters.']);
        }

        $senderId = trim((string) ($input['sender_id'] ?? ''));

        if ($senderId === '' || ! $this->senderBelongsToBusiness($business, $senderId)) {
            throw ValidationException::withMessages(['sender_id' => 'Choose a sender that belongs to this Business.']);
        }

        $sendingServer = (int) ($input['sending_server'] ?? 0);

        $assignment = CustomerBasedSendingServer::query()
            ->where('business_id', $business->id)
            ->where('sending_server', $sendingServer)
            ->where('status', 1)
            ->with('sendingServer')
            ->first();

        if ($sendingServer === 0 || $assignment === null || $assignment->sendingServer === null || ! $assignment->sendingServer->status) {
            throw ValidationException::withMessages(['sending_server' => 'Choose an active messaging channel assigned to this Business.']);
        }

        $config = [
            'sms_type' => $smsType,
            'message' => $message,
            'sender_id' => $senderId,
            'sending_server' => $sendingServer,
        ];

        if ($smsType === 'mms') {
            $mediaUrl = trim((string) ($input['media_url'] ?? ''));

            if ($mediaUrl === '' || ! preg_match('#^https?://#i', $mediaUrl) || filter_var($mediaUrl, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages(['media_url' => 'Enter a valid http(s) media URL for MMS.']);
            }

            $config['media_url'] = mb_substr($mediaUrl, 0, 2048);
        }

        return $config;
    }

    private function updateContactFieldConfig(Business $business, array $input): array
    {
        $field = ContactGroupFields::query()
            ->with('contactGroup')
            ->find((int) ($input['field_id'] ?? 0));

        if (
            $field === null
            || $field->contactGroup === null
            || (int) $field->contactGroup->business_id !== (int) $business->id
            || $field->is_phone
        ) {
            throw ValidationException::withMessages(['field_id' => 'Choose a non-phone custom field that belongs to this Business.']);
        }

        if (! array_key_exists('value', $input) || mb_strlen((string) $input['value']) > 255) {
            throw ValidationException::withMessages(['value' => 'Enter a value of up to 255 characters.']);
        }

        return [
            'field_id' => (int) $field->id,
            'value' => (string) $input['value'],
        ];
    }

    private function businessGroup(Business $business, mixed $groupId): ?ContactGroups
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }

        return ContactGroups::query()
            ->where('business_id', $business->id)
            ->where('status', true)
            ->find((int) $groupId);
    }

    /**
     * Mirrors the B1 core's own Business-scoped originator rule: the
     * value must be an active Business SenderID or an assigned Business
     * PhoneNumber. Re-verified again by checkQuickSendValidation() at send
     * time — this is the definition-time gate, not the only one.
     */
    private function senderBelongsToBusiness(Business $business, string $senderId): bool
    {
        if (Senderid::query()->where('business_id', $business->id)->where('sender_id', $senderId)->where('status', Senderid::STATUS_ACTIVE)->exists()) {
            return true;
        }

        return PhoneNumbers::query()->where('business_id', $business->id)->where('number', $senderId)->where('status', 'assigned')->exists();
    }
}
