<?php

namespace App\Library\AgencySite;

use Closure;

/**
 * Everything the renderer needs that does NOT come from the Agency's saved copy: who the brand is
 * (from the single white-label identity), which plans are on sale (from the plan catalog, read live),
 * and where the call-to-action buttons lead. Built server-side only; nothing here is accepted from
 * a request.
 *
 * @phpstan-type PlanView array{uid: string, name: string, description: ?string, tier: string, price: ?string, currency: ?string, cycle: string, sellable: bool}
 */
final class SiteContext
{
    /**
     * @param  list<array<string, mixed>>  $plans  plan views already filtered to what may be shown publicly
     * @param  Closure(string): string  $pageUrl  page key => absolute URL
     * @param  Closure(string): string  $signupUrl  plan uid => absolute URL
     */
    public function __construct(
        public readonly string $brandName,
        public readonly ?string $legalName,
        public readonly string $primaryColor,
        public readonly ?string $secondaryColor,
        public readonly ?string $logo,
        public readonly ?string $logoDark,
        public readonly ?string $logoSquare,
        public readonly ?string $favicon,
        public readonly ?string $supportEmail,
        public readonly ?string $supportUrl,
        public readonly array $social,
        public readonly array $plans,
        public readonly ?string $bookingUrl,
        public readonly string $goal,
        public readonly string $pricingMode,
        public readonly Closure $pageUrl,
        public readonly Closure $signupUrl,
        public readonly string $loginUrl,
        public readonly string $cssUrl,
        public readonly bool $preview = false,
    ) {
    }

    public function offersSubscriptions(): bool
    {
        return in_array($this->goal, ['both', 'subscriptions'], true) && $this->pricingMode !== 'book_call';
    }

    public function offersBooking(): bool
    {
        return $this->bookingUrl !== null && in_array($this->goal, ['both', 'book_call'], true);
    }
}
