<?php

namespace App\Library\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Library\BusinessEmail\Contracts\BusinessEmailProvider;

/**
 * The one place that maps a provider type to its adapter — and therefore the
 * only place that knows both providers exist. Resolved through the container
 * so tests (and, later, a platform-paid provider) can rebind an adapter
 * without any caller changing.
 */
final class BusinessEmailProviderRegistry
{
    public function for(BusinessEmailProviderType $type): BusinessEmailProvider
    {
        return match ($type) {
            BusinessEmailProviderType::Google => app(GoogleBusinessEmailProvider::class),
            BusinessEmailProviderType::Microsoft => app(MicrosoftBusinessEmailProvider::class),
        };
    }
}
