<?php

namespace App\Console\Commands;

use App\Library\Voice\Inbound\InboundCallRouter;
use App\Library\Voice\Inbound\InboundCallingGate;
use Illuminate\Console\Command;

/** Closes inbound calls that outlived their ring/duration bound and records them (missed/ended). No-op while the flag is off. */
class ExpireInboundCalls extends Command
{
    protected $signature = 'voice:expire-inbound-calls';

    protected $description = 'Close inbound calls that rang or ran past their bound (missed-call backstop)';

    public function handle(InboundCallRouter $router, InboundCallingGate $gate): int
    {
        if (! $gate->platformEnabled()) {
            return self::SUCCESS;
        }
        $this->info('Acted on ' . $router->expireStale() . ' call(s).');

        return self::SUCCESS;
    }
}
