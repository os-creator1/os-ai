<?php

    namespace App\Providers;

    use App\Helpers\Helper;
    use App\Library\Navigation\CustomerShellComposer;
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

            // Customer Experience Slice 1B (contract §8.4): the CUSTOMER
            // sidebar, navbar and breadcrumb are composed per request from
            // the resolved account context and the authorization-driven
            // CustomerMenuBuilder. The static array above keeps serving the
            // admin branch unchanged.
            //
            // Customer Experience Slice 1A (contract §6a #16): panels.
            // horizontalMenu joins the same composer so the horizontal
            // layout's customer branch also renders from CustomerMenuBuilder
            // instead of the legacy Helper::menuData()['customer'] array.
            View::composer(
                [
                    'panels.sidebar',
                    'panels.navbar',
                    'panels.breadcrumb',
                    'panels.horizontalMenu',
                    'components.customer-context-switcher',
                    'components.view-as-banner',
                ],
                CustomerShellComposer::class,
            );
        }

    }
