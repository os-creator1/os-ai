<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Models\AgencyProspectingChannel;
use Illuminate\Support\Str;

/**
 * Runtime pass — the sole test double for AgencyProspectingMessageSender.
 * Deterministic, in-memory, never makes a real provider call. Bound only
 * inside the automated test suite via container override in each test's
 * own setUp() — never AppServiceProvider's default binding.
 */
class FakeAgencyProspectingMessageSender implements AgencyProspectingMessageSender
{
    public bool $nextSendSucceeds = true;

    public ?string $nextFailureReason = 'fake_failure';

    /** @var array<int, array{channel_id: int, from: string, to: string, body: string}> */
    public array $sentMessages = [];

    public function send(AgencyProspectingChannel $channel, string $from, string $to, string $body): AgencyProspectingSendResult
    {
        $this->sentMessages[] = [
            'channel_id' => $channel->id,
            'from' => $from,
            'to' => $to,
            'body' => $body,
        ];

        if (! $this->nextSendSucceeds) {
            return AgencyProspectingSendResult::failure($this->nextFailureReason ?? 'fake_failure');
        }

        return AgencyProspectingSendResult::success('FAKE-' . Str::uuid());
    }
}
