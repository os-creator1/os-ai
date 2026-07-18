<?php

    namespace App\Repositories\Contracts;

    /* *
     * Interface PluginsRepository
     */

    use App\Models\Plugins;
    use Exception;
    use Illuminate\Http\Request;

    interface PluginsRepository extends BaseRepository
    {


        /**
         * Upload a plugin to tmp.
         *
         * @param Request $request
         *
         * @throws Exception
         */
        public function upload(Request $request);


        public function enable(Plugins $plugin);

        public function disable(Plugins $plugin);

        public function uninstall(Plugins $plugin);

        public function settings(Plugins $plugin);

    }
