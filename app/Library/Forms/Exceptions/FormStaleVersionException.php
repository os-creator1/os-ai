<?php

namespace App\Library\Forms\Exceptions;

/**
 * The editor saved on top of a version that is no longer the form's current one:
 * another tab or teammate published a newer version in the meantime. A subtype of
 * FormRuleException so every existing `catch (FormRuleException)` still treats it
 * as a refusal that wrote nothing; the builder catches this one first and answers
 * "stale tab" (409) instead of a validation error.
 */
final class FormStaleVersionException extends FormRuleException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('This form was changed somewhere else (another tab or teammate). Reload to continue from the latest version.');
    }
}
