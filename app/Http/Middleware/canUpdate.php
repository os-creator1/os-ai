<?php

    namespace App\Http\Middleware;

    use App\Helpers\MigrationsHelper;
    use Closure;
    use Illuminate\Http\RedirectResponse;


    class canUpdate
    {
        use MigrationsHelper;

        /**
         * Handle an incoming request.
         *
         * @param         $request
         * @param Closure $next
         *
         * @return RedirectResponse|mixed
         */
        public function handle($request, Closure $next): mixed
        {
            $updateEnabled = filter_var(config('installer.updaterEnabled'), FILTER_VALIDATE_BOOLEAN);
            switch ($updateEnabled) {
                case true:
                    $canInstall = new canInstall;

                    // if the application has not been installed,
                    // redirect to the installer
                    if ( ! $canInstall->alreadyInstalled()) {
                        return redirect()->route('Installer::welcome');
                    }

                    if ($this->alreadyUpdated()) {
                        return redirect()->route('login')->with([
                            'status'  => 'info',
                            'message' => 'You are already up to date.',
                        ]);
                    }
                    break;

                case false:
                default:
                    return redirect()->route('login')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);
            }

            return $next($request);
        }

        /**
         * If application is already updated.
         *
         * @return bool
         */
        public function alreadyUpdated(): bool
        {
            $migrations   = $this->getMigrations();
            $dbMigrations = $this->getExecutedMigrations();

            // If the count of migrations and dbMigrations is equal,
            // then the update as already been updated.
            if (count($migrations) == count($dbMigrations)) {
                return true;
            }

            // Continue, the app needs an update
            return false;
        }

    }

