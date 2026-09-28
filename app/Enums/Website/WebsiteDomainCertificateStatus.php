<?php

namespace App\Enums\Website;

/**
 * The managed-server provider's (Forge) own certificate lifecycle, as
 * reported by ForgeDomainProvisioner::certificateStatus() — deliberately
 * a separate, smaller vocabulary from WebsiteDomainStatus: this is what
 * the PROVIDER says right now, not the domain row's own persisted state,
 * which WebsiteDomainService maps this onto.
 */
enum WebsiteDomainCertificateStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
