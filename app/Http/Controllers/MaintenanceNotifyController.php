<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    class MaintenanceNotifyController extends Controller
    {
        /**
         * The session-backed notify form. The prerendered maintenance page cannot hold a valid CSRF token (it is
         * rendered by the CLI, outside the visitor's session), so it links here; this response is rendered live in the
         * visitor's own session and carries a token that the POST below will accept.
         */
        public function show()
        {
            if ( ! app()->maintenanceMode()->active()) {
                abort(404);
            }

            return response()->view('errors.503', [], 503, ['Retry-After' => '60']);
        }

        public function store(Request $request)
        {

            if ( ! app()->maintenanceMode()->active()) {
                abort(403);
            }

            $request->validate([
                'email' => 'required|email|unique:maintenance_notifications,email',
            ]);

            DB::table('maintenance_notifications')->insert([
                'email'      => $request->email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);


            // Fallback for session issues during maintenance
            return redirect()->to('/?notified=1');
        }

    }
