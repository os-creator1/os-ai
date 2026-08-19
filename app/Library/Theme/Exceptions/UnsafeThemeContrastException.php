<?php

namespace App\Library\Theme\Exceptions;

use Exception;

class UnsafeThemeContrastException extends Exception
{
    /** @var array<string> */
    private array $contrastErrors;

    public function __construct(array $contrastErrors)
    {
        $this->contrastErrors = $contrastErrors;
        parent::__construct('This theme fails required accessibility contrast checks: ' . implode(' ', $contrastErrors));
    }

    public function getContrastErrors(): array
    {
        return $this->contrastErrors;
    }
}
