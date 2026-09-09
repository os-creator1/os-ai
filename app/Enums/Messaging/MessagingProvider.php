<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.3 — the provider-neutral transport identity.
 *
 * Exactly one case at launch: managed messaging runs on Telnyx under the
 * §28.3 Candidate B architecture. Slice 3 introduces no second managed
 * provider, and no Managed-Account-shaped identifier of any kind.
 */
enum MessagingProvider: string
{
    case Telnyx = 'telnyx';
}
