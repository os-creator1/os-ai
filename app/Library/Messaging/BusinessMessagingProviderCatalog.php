<?php

namespace App\Library\Messaging;

use App\Models\SendingServer;

/**
 * B2's own customer-facing provider allowlist and field metadata — Twilio
 * and Telnyx, the same two providers MessagingChannelsController has always
 * hard-allowed, now with a declared `mms` capability flag and an (empty by
 * default) `mms_fields` slot so a future provider that needs MMS-only input
 * has somewhere to declare it.
 *
 * This is NOT the Slice 3 managed-messaging registry (App\Enums\Messaging\MessagingProvider,
 * App\Library\Messaging\ManagedMessageDispatcher, etc.) — that is a
 * platform-run, Telnyx-only system with its own provider concept.
 * This class is B2's BYO-credential connect flow only; the two never share
 * a registry.
 *
 * Instantiable with a custom provider map so tests can exercise the
 * conditional-MMS-field rules against a synthetic non-MMS provider without
 * touching the real, production two-provider allowlist.
 */
final class BusinessMessagingProviderCatalog
{
    /**
     * @var array<string, array{label: string, credential_fields: array<string, array{label: string, required: bool}>, mms: bool, mms_fields: array<string, array{label: string, required: bool}>}>
     */
    private array $providers;

    /**
     * @param  array<string, array{label: string, credential_fields: array<string, array{label: string, required: bool}>, mms: bool, mms_fields: array<string, array{label: string, required: bool}>}>|null  $providers
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? self::defaults();
    }

    /**
     * @return array<string, array{label: string, credential_fields: array<string, array{label: string, required: bool}>, mms: bool, mms_fields: array<string, array{label: string, required: bool}>}>
     */
    public static function defaults(): array
    {
        return [
            SendingServer::TYPE_TWILIO => [
                'label' => 'Twilio',
                'credential_fields' => [
                    'account_sid' => ['label' => 'Twilio account identifier', 'required' => true],
                    'auth_token' => ['label' => 'Twilio secret', 'required' => true],
                ],
                'mms' => true,
                'mms_fields' => [],
            ],
            SendingServer::TYPE_TELNYX => [
                'label' => 'Telnyx',
                'credential_fields' => [
                    'api_key' => ['label' => 'Telnyx access key', 'required' => true],
                    'c1' => ['label' => 'Messaging profile ID', 'required' => true],
                    'c2' => ['label' => 'Messaging connection ID', 'required' => false],
                ],
                'mms' => true,
                'mms_fields' => [],
            ],
        ];
    }

    public function isAllowed(string $provider): bool
    {
        return array_key_exists($provider, $this->providers);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->providers);
    }

    public function label(string $provider): ?string
    {
        return $this->providers[$provider]['label'] ?? null;
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public function credentialFields(string $provider): array
    {
        return $this->providers[$provider]['credential_fields'] ?? [];
    }

    public function supportsMms(string $provider): bool
    {
        return (bool) ($this->providers[$provider]['mms'] ?? false);
    }

    /**
     * @return array<string, array{label: string, required: bool}>
     */
    public function mmsFields(string $provider): array
    {
        return $this->providers[$provider]['mms_fields'] ?? [];
    }

    /**
     * The fields eligible to be required/stored for this submission: the
     * provider's credential fields always, plus its MMS-only fields when
     * MMS is enabled AND the provider actually supports MMS. A provider
     * without MMS support never contributes MMS-only fields here, even if
     * $mmsEnabled is true — there is nothing to enable.
     *
     * @return array<string, array{label: string, required: bool}>
     */
    public function eligibleFields(string $provider, bool $mmsEnabled): array
    {
        $fields = $this->credentialFields($provider);

        if ($mmsEnabled && $this->supportsMms($provider)) {
            $fields = array_merge($fields, $this->mmsFields($provider));
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @return array{0: array<string, string>, 1: array<string, string>} [errors, valid field values]
     */
    public function validate(string $provider, array $submitted, bool $isUpdate, bool $mmsEnabled): array
    {
        $errors = [];
        $values = [];

        foreach ($this->eligibleFields($provider, $mmsEnabled) as $key => $meta) {
            $value = trim((string) ($submitted[$key] ?? ''));

            if ($value === '') {
                // Create: a required field left blank is an error. Update:
                // a blank field means "keep the current value" — never an
                // error, never applied.
                if ($meta['required'] && ! $isUpdate) {
                    $errors[$key] = $meta['label'] . ' is required.';
                }

                continue;
            }

            $values[$key] = $value;
        }

        return [$errors, $values];
    }
}
