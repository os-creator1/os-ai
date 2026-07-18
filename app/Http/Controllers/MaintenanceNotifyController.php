<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    class MaintenanceNotifyController extends Controller
    {
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
