<?php

namespace Tests\Support\Documents;

use App\Jobs\Base;

/**
 * TEST-ONLY. An ordinary Base job that is NOT ShouldBeEncrypted — the control
 * for JobServiceProvider's payload extraction: every pre-existing job must
 * keep serializing, and reading back, exactly as it did before Sub-slice C
 * taught the monitor to decrypt.
 *
 * A named class rather than an anonymous one because Laravel refuses to
 * serialize an anonymous class onto a queue at all.
 */
class PlainMonitoredJob extends Base
{
    public function __construct(public readonly int $value = 1)
    {
    }

    public function handle(): void
    {
        // Nothing: this job exists to be serialized, not to do work.
    }
}
