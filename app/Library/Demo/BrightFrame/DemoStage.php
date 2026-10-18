<?php

namespace App\Library\Demo\BrightFrame;

interface DemoStage
{
    /** Idempotently populate one module for the demo tenant in $context. */
    public function run(DemoContext $context): void;
}
