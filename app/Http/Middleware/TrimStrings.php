<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // Contract 17B: the spaces around a merge field live in the text run ("Proposal for " + chip).
        // Trimming them here would glue the chip to the word ("forAlex"); BlockSchema validates run text itself.
        'blocks.*.data.runs.*.t',
    ];
}
