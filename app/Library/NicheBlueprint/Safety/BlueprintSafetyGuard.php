<?php

namespace App\Library\NicheBlueprint\Safety;

/**
 * The single question every external-facing choke point asks:
 * "am I being reached from the Blueprint Workspace?" If so it refuses.
 *
 * `assertAllowed` is a no-op outside Blueprint mode (one boolean check), so
 * wiring it into a seam changes nothing for real Businesses.
 */
final class BlueprintSafetyGuard
{
    /** The named external actions the guard knows (also what tests iterate). */
    public const ACTIONS = [
        'sms_send',
        'email_send',
        'notification_send',
        'payment',
        'provider_oauth',
        'ads_mutation',
        'website_publish',
        'customer_notification',
        'booking',
        'http_request',
    ];

    public function __construct(private readonly BlueprintMode $mode) {}

    public function active(): bool
    {
        return $this->mode->active();
    }

    /** @throws BlueprintModeRefusedException */
    public function assertAllowed(string $action): void
    {
        if ($this->mode->active()) {
            throw new BlueprintModeRefusedException($action);
        }
    }

    /** Static convenience for call sites that are not container-built. */
    public static function check(string $action): void
    {
        app(self::class)->assertAllowed($action);
    }
}
