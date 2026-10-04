<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    ];

    /**
     * Contract 17B: the spaces around a merge field live in the text run
     * ("Proposal for " + chip). Trimming them would glue the chip to the word.
     * Only the document / template editors' block-save requests keep run text
     * untouched (BlockSchema validates and bounds it itself); every other
     * request, and every other field of those requests, is trimmed as before.
     * The route is not resolved yet at global-middleware time, so this is matched
     * on method and path.
     */
    private bool $preserveRunText = false;

    public function handle($request, Closure $next)
    {
        $this->preserveRunText = $request instanceof Request
            && $request->isMethod('PUT')
            && $request->is('*/documents/*/editor/blocks', '*/document-templates/*/blocks');

        return parent::handle($request, $next);
    }

    protected function transform($key, $value)
    {
        if ($this->preserveRunText && is_string($value) && Str::is('blocks.*.data.runs.*.t', (string) $key)) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
