<?php

namespace App\Enums\BusinessEmail;

/**
 * What caused a send. `automation` is reserved for the later Automations
 * integration slice (a message then also carries automation_step_run_id);
 * nothing in this slice produces it.
 */
enum BusinessEmailSource: string
{
    case Manual = 'manual';
    case Automation = 'automation';
}
