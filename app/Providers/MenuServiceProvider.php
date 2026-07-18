<?php

    namespace App\Providers;

    use App\Helpers\Helper;
    use Illuminate\Support\Facades\View;
    use Illuminate\Support\ServiceProvider;
    use function App\Helpers\is_plugin_active;

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

            // 2. Merge plugin menus if active

            // Example 1: Codeglen/usupport
            if (is_plugin_active('codeglen/usupport')) {
                // Get the plugin's menu structure. e.g., ['admin' => [item1, item2]]
                $supportMenu = \Codeglen\Usupport\Helper\Helper::supportMenu();

                // Merge the 'admin' menu items: Base items + Support items
                $menuData['admin'] = array_merge(
                    $menuData['admin'] ?? [], // Use existing admin menu or empty array if none
                    $supportMenu['admin'] ?? [] // Append plugin's admin menu or empty array
                );

                // Repeat for any other relevant menu types (e.g., 'customer')
                $menuData['customer'] = array_merge(
                    $menuData['customer'] ?? [],
                    $supportMenu['customer'] ?? []
                );
            }

            // Example 2: Codeglen/ulanding
            if (is_plugin_active('codeglen/ulanding')) {
                $landingMenu = \Codeglen\Ulanding\Helper\Helper::uLandingMenu();

                // Merge the 'admin' menu items: Current items + Landing items
                $menuData['admin'] = array_merge(
                    $menuData['admin'] ?? [],
                    $landingMenu['admin'] ?? []
                );
            }

            // 3. Convert to object for Blade compatibility
            $verticalMenuData = json_decode(json_encode($menuData));

            // 4. Share to all views
            View::share('menuData', [$verticalMenuData, $verticalMenuData]);
        }

    }
