<?php

namespace App\Library\AgencyProspecting\Contracts;

use App\Library\AgencyProspecting\AgencyProspectingSendResult;
use App\Models\AgencyProspectingChannel;

interface AgencyProspectingMessageSender
{
    public function send(AgencyProspectingChannel $channel, string $from, string $to, string $body): AgencyProspectingSendResult;
}
