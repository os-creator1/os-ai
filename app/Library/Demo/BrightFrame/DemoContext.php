<?php

namespace App\Library\Demo\BrightFrame;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * What every stage of the BrightFrame provisioner shares. Only the demo
 * tenant's own rows are ever reachable from here: stages receive this object
 * and never look a Business up by anything else.
 */
final class DemoContext
{
    /** @var array<string, int|string> */
    public array $report = [];

    /**
     * Rows an earlier stage made that a later one points at (contacts by
     * phone, services by name, ...), so no stage re-queries by guesswork.
     *
     * @var array<string, mixed>
     */
    public array $refs = [];

    public function __construct(
        public readonly User $user,
        public readonly Customer $customer,
        public readonly Workspace $workspace,
        public readonly Business $business,
        public readonly BusinessLocation $location,
        public readonly int $platformAdminId,
        public readonly CarbonImmutable $now,
    ) {
    }

    public function note(string $key, int|string $value): void
    {
        $this->report[$key] = $value;
    }

    /** A moment relative to the provisioning day, so the demo always looks current. */
    public function daysFromNow(int $days, int $hour = 10, int $minute = 0): CarbonImmutable
    {
        return $this->now->addDays($days)->setTime($hour, $minute);
    }
}
