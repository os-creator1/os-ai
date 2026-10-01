<?php

namespace App\DTO\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailSource;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;

/**
 * The canonical, application-level request to email a Contact. It carries
 * application identity only — never a provider, an account id or a
 * credential. The sender resolves the account, sender identity and provider.
 *
 * `operationKey` is the idempotency identity (unique per Business). A
 * manual form uses a per-render token; a future Automation step will use a
 * deterministic key derived from its step run so a retry never double-sends.
 */
final class BusinessEmailSendRequest
{
    public function __construct(
        public readonly Business $business,
        public readonly Contacts $contact,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly string $operationKey,
        public readonly BusinessEmailSource $source = BusinessEmailSource::Manual,
        public readonly ?BusinessLocation $location = null,
        public readonly ?int $sentByUserId = null,
        public readonly ?int $automationStepRunId = null,
    ) {
    }
}
