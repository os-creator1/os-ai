<?php

namespace Tests\Unit\Messaging;

use App\Library\Messaging\BusinessMessagingProviderCatalog;
use App\Models\SendingServer;
use Tests\TestCase;

/**
 * Customer Messaging Setup UX Cleanup — the conditional-MMS-field rules
 * MessagingChannelsController's connect/store flow relies on, proven
 * directly against synthetic providers so the "a provider without MMS
 * support never shows/requires MMS controls" case is actually exercised
 * (both real, currently-allowed providers — Twilio and Telnyx — support
 * MMS today, so an HTTP-level test can't reach that branch without
 * fabricating a third allowlisted provider).
 */
class BusinessMessagingProviderCatalogTest extends TestCase
{
    private function withMms(): BusinessMessagingProviderCatalog
    {
        return new BusinessMessagingProviderCatalog([
            'mms_provider' => [
                'label' => 'MMS Provider',
                'credential_fields' => [
                    'key' => ['label' => 'API key', 'required' => true],
                ],
                'mms' => true,
                'mms_fields' => [
                    'mms_webhook' => ['label' => 'MMS webhook URL', 'required' => true],
                ],
            ],
        ]);
    }

    private function withoutMms(): BusinessMessagingProviderCatalog
    {
        return new BusinessMessagingProviderCatalog([
            'sms_only_provider' => [
                'label' => 'SMS Only Provider',
                'credential_fields' => [
                    'key' => ['label' => 'API key', 'required' => true],
                ],
                'mms' => false,
                'mms_fields' => [
                    // Even declared, these must never be reachable through
                    // eligibleFields()/validate() when the provider itself
                    // does not support MMS.
                    'mms_webhook' => ['label' => 'MMS webhook URL', 'required' => true],
                ],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Provider capability.
    // -----------------------------------------------------------------

    public function test_a_provider_declaring_mms_support_reports_it(): void
    {
        $this->assertTrue($this->withMms()->supportsMms('mms_provider'));
    }

    public function test_a_provider_without_mms_support_reports_it(): void
    {
        $this->assertFalse($this->withoutMms()->supportsMms('sms_only_provider'));
    }

    public function test_the_real_production_allowlist_is_exactly_twilio_and_telnyx_and_both_currently_support_mms(): void
    {
        $catalog = new BusinessMessagingProviderCatalog();

        $this->assertSame([SendingServer::TYPE_TWILIO, SendingServer::TYPE_TELNYX], $catalog->types());
        $this->assertTrue($catalog->supportsMms(SendingServer::TYPE_TWILIO));
        $this->assertTrue($catalog->supportsMms(SendingServer::TYPE_TELNYX));
        $this->assertSame('Twilio', $catalog->label(SendingServer::TYPE_TWILIO));
        $this->assertSame('Telnyx', $catalog->label(SendingServer::TYPE_TELNYX));
    }

    public function test_an_unknown_provider_is_not_allowed_and_has_no_capability(): void
    {
        $catalog = new BusinessMessagingProviderCatalog();

        $this->assertFalse($catalog->isAllowed('plivo'));
        $this->assertFalse($catalog->supportsMms('plivo'));
        $this->assertNull($catalog->label('plivo'));
    }

    // -----------------------------------------------------------------
    // Conditional MMS fields.
    // -----------------------------------------------------------------

    public function test_mms_fields_are_eligible_when_mms_is_enabled_and_the_provider_supports_it(): void
    {
        $fields = $this->withMms()->eligibleFields('mms_provider', mmsEnabled: true);

        $this->assertArrayHasKey('key', $fields);
        $this->assertArrayHasKey('mms_webhook', $fields);
    }

    public function test_mms_fields_are_not_eligible_when_mms_is_not_enabled(): void
    {
        $fields = $this->withMms()->eligibleFields('mms_provider', mmsEnabled: false);

        $this->assertArrayHasKey('key', $fields);
        $this->assertArrayNotHasKey('mms_webhook', $fields);
    }

    public function test_mms_fields_are_never_eligible_for_a_provider_without_mms_support_even_if_requested(): void
    {
        // Defensive: even a caller that (incorrectly) passes mmsEnabled=true
        // for a non-MMS provider must not surface its declared MMS fields.
        $fields = $this->withoutMms()->eligibleFields('sms_only_provider', mmsEnabled: true);

        $this->assertArrayHasKey('key', $fields);
        $this->assertArrayNotHasKey('mms_webhook', $fields);
    }

    // -----------------------------------------------------------------
    // Validation.
    // -----------------------------------------------------------------

    public function test_create_requires_the_mms_only_field_when_mms_is_enabled(): void
    {
        [$errors, $values] = $this->withMms()->validate('mms_provider', ['key' => 'abc'], isUpdate: false, mmsEnabled: true);

        $this->assertArrayHasKey('mms_webhook', $errors);
        $this->assertSame('MMS webhook URL is required.', $errors['mms_webhook']);
        $this->assertSame(['key' => 'abc'], $values);
    }

    public function test_create_does_not_require_the_mms_only_field_when_mms_is_disabled(): void
    {
        [$errors, $values] = $this->withMms()->validate('mms_provider', ['key' => 'abc'], isUpdate: false, mmsEnabled: false);

        $this->assertSame([], $errors);
        $this->assertSame(['key' => 'abc'], $values);
    }

    public function test_create_still_requires_base_credential_fields_regardless_of_mms(): void
    {
        [$errors] = $this->withMms()->validate('mms_provider', ['key' => ''], isUpdate: false, mmsEnabled: false);

        $this->assertArrayHasKey('key', $errors);
    }

    public function test_update_never_requires_a_blank_field_mms_or_otherwise(): void
    {
        [$errors, $values] = $this->withMms()->validate('mms_provider', ['key' => '', 'mms_webhook' => ''], isUpdate: true, mmsEnabled: true);

        $this->assertSame([], $errors);
        $this->assertSame([], $values, 'A blank field on update means "keep the current value" — it is never applied.');
    }

    public function test_update_applies_only_the_non_blank_submitted_mms_field(): void
    {
        [$errors, $values] = $this->withMms()->validate('mms_provider', ['key' => '', 'mms_webhook' => 'https://example.test/mms'], isUpdate: true, mmsEnabled: true);

        $this->assertSame([], $errors);
        $this->assertSame(['mms_webhook' => 'https://example.test/mms'], $values);
    }

    public function test_a_submitted_mms_only_field_is_silently_ignored_when_mms_is_disabled(): void
    {
        // Not eligible, so it is neither required nor stored — the caller
        // decided MMS is off for this connection.
        [$errors, $values] = $this->withMms()->validate('mms_provider', ['key' => 'abc', 'mms_webhook' => 'https://example.test/mms'], isUpdate: false, mmsEnabled: false);

        $this->assertSame([], $errors);
        $this->assertSame(['key' => 'abc'], $values);
    }
}
