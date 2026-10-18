<?php

    namespace App\Http\Middleware;

    use Closure;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Http\Request;

    class CheckPasswordChanged
    {
        /**
         * Handle an incoming request.
         */
        public function handle(Request $request, Closure $next)
        {
            if (Auth::check()) {
                $user = Auth::user();

                // A user disabled by an admin / agency / parent customer after sign-in must lose the
                // live session immediately, not only be blocked at the next login.
                if ($user->status === false || $user->status === 0 || $user->status === '0') {
                    Auth::logout();

                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')
                        ->withErrors(['message' => __('locale.auth.disabled')]);
                }

                // Ensure the model has 'password_changed_at' in the $casts array as 'datetime'
                if ($user->password_changed_at) {
                    $sessionTimestamp = session('password_changed_at');

                    // Logic: If session timestamp is missing OR older than the DB timestamp, logout.
                    // Using ->timestamp ensures we are comparing two integers.
                    if ( ! $sessionTimestamp || $sessionTimestamp < $user->password_changed_at->timestamp) {

                        Auth::logout();

                        $request->session()->invalidate();
                        $request->session()->regenerateToken();

                        return redirect()->route('login')
                            ->withErrors(['message' => __('Your password was recently changed. Please login again.')]);
                    }
                }
            }

            return $next($request);
        }

    }
