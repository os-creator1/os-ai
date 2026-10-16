<?php

namespace App\Library\Assistant;

use RuntimeException;

/** The executor's claim on a proposal was superseded (new token/fence, or the proposal is no longer `executing`). Nothing was written. */
final class AssistantLeaseLost extends RuntimeException
{
}
