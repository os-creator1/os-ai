<?php

namespace App\Console\Commands;

use App\Library\Voice\TestCallPlacer;
use App\Library\Voice\TestCallRefusedException;
use App\Models\Business;
use App\Models\BusinessVoiceCall;
use Illuminate\Console\Command;

/**
 * Operator-only (shell access) Telnyx Call Control test call. Refuses unless enabled, live and the destination is on an
 * exact allowlist; see TestCallPlacer. Prints ids and status only — never the destination or caller number.
 */
class VoiceTestCall extends Command
{
    protected $signature = 'voice:test-call {business : Business id} {to : Destination in E.164 (must be on the allowlist)}';

    protected $description = 'Place one admin test call from a Business\'s active local Telnyx number via Call Control (allowlisted destinations only).';

    public function handle(TestCallPlacer $placer): int
    {
        $business = ctype_digit((string) $this->argument('business')) ? Business::find((int) $this->argument('business')) : null;
        if ($business === null) {
            $this->error('Refused (business_not_found): no such Business.');

            return self::FAILURE;
        }

        try {
            $call = $placer->place($business, (string) $this->argument('to'));
        } catch (TestCallRefusedException $e) {
            $this->error('Refused (' . $e->reason . '): ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($call->status === BusinessVoiceCall::STATUS_FAILED) {
            $this->error(sprintf('Call %d failed (%s). Check Telnyx before retrying.', $call->id, $call->failure_code));

            return self::FAILURE;
        }

        $this->info(sprintf('Call %d placed (status: %s). Status updates arrive via the voice webhook.', $call->id, $call->status));

        return self::SUCCESS;
    }
}
