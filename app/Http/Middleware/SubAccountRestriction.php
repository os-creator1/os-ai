<?php

// app/Http/Middleware/SubAccountRestriction.php
    namespace App\Http\Middleware;

    use Closure;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;

    class SubAccountRestriction
    {
        public function handle(Request $request, Closure $next): Response
        {
            if (auth()->check() && auth()->user()->parent_id !== null) {
                return redirect()
                    ->route('user.home')
                    ->with([
                        'status'  => 'error',
                        'message' => 'Access restricted for sub-accounts.',
                    ]);
            }

            return $next($request);
        }

    }
