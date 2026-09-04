<?php

    namespace App\Providers;

    use App\Helpers\Helper;
    use Illuminate\Support\Facades\View;
    use Illuminate\Support\ServiceProvider;

    class MenuServiceProvider extends ServiceProvider
    {
        /**
         * Register services.
         *
         * @return void
         */
        public function register()
        {
            //
        }

        /**
         * Bootstrap services.
         *
         * @return void
         */
        public function boot()
        {
            // 1. Load base menu
            // Assuming this returns an associative array like ['admin' => [...], 'customer' => [...]]
            $menuData = Helper::menuData();

            // 3. Convert to object for Blade compatibility
            $verticalMenuData = json_decode(json_encode($menuData));

            // 4. Share to all views
            View::share('menuData', [$verticalMenuData, $verticalMenuData]);
        }

    }
