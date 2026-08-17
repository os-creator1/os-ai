<?php

namespace App\Library\Theme\Exceptions;

use Exception;

/**
 * Covers every preset-lifecycle rule violation (§6.14): editing or
 * deleting the Factory preset, editing or deleting the active preset,
 * activating a preset still in Draft, deleting an uploaded font still
 * referenced by another preset.
 */
class InvalidThemePresetOperationException extends Exception
{
    //
}
