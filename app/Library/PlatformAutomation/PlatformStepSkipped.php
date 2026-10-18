<?php

namespace App\Library\PlatformAutomation;

use RuntimeException;

/** A step that has nothing to act on for this run (no such recipient, already done). Recorded as skipped, not failed. */
final class PlatformStepSkipped extends RuntimeException
{
}
