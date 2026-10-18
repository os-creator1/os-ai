<?php

namespace App\Http\Middleware;

use App\Library\NicheBlueprint\Safety\BlueprintMode;
use Closure;
use Illuminate\Http\Request;

/**
 * Puts the request into Blueprint Safety Mode for its whole life (controller
 * and view rendering), and always takes it back out.
 */
class EnterBlueprintMode
{
    public function __construct(private readonly BlueprintMode $mode) {}

    public function handle(Request $request, Closure $next)
    {
        $this->mode->enter();

        try {
            return $next($request);
        } finally {
            $this->mode->leave();
        }
    }
}
