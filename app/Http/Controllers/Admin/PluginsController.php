<?php

    namespace App\Http\Controllers\Admin;

    use App\Helpers\Helper;
    use App\Http\Requests\Plugins\InstallPluginRequest;
    use App\Library\StringHelper;
    use App\Library\Tool;
    use App\Models\Plugins;
    use App\Repositories\Contracts\PluginsRepository;
    use Exception;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\File;
    use Symfony\Component\Console\Output\BufferedOutput;

    class PluginsController extends AdminBaseController
    {
        protected PluginsRepository $plugins;


        /**
         * Create a new controller instance.
         *
         * @param PluginsRepository $plugins
         */
        public function __construct(PluginsRepository $plugins)
        {
            $this->plugins = $plugins;
        }


        /**
         * @return Application|Factory|View
         */
        public function plugins(): View|Factory|Application
        {

            $this->authorize('view plugins');

            $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
            ];

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['name' => __('locale.menu.Plugins')],
            ];

            $plugins = Plugins::all();

            return view('admin.Plugins.index', [
                'pageConfigs' => $pageConfigs,
                'breadcrumbs' => $breadcrumbs,
                'plugins'     => $plugins,
            ]);
        }


        /**
         * Shows the plugin installation page.
         *
         * This page is responsible for showing the plugin installation page.
         *
         * @return View
         */
        public function install()
        {

            $this->authorize('install plugins');

            $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
            ];

            $breadcrumbs = [
                ['link' => url(config('app.admin_path') . "/dashboard"), 'name' => __('locale.menu.Dashboard')],
                ['link' => url(config('app.admin_path') . "/plugins"), 'name' => __('locale.menu.Plugins')],
                ['name' => __('locale.menu.Add Plugin')],
            ];

            return view('admin.Plugins.install', [
                'pageConfigs' => $pageConfigs,
                'breadcrumbs' => $breadcrumbs,
            ]);

        }

        /**
         * Handles the plugin installation process.
         *
         * This function is responsible for handling the plugin installation process. It first
         * checks if the user has the necessary permissions. Then, it uploads the plugin to the
         * server, installs it, and updates the purchase code.
         *
         * @param InstallPluginRequest $request
         * @param BufferedOutput       $outputLog
         *
         * @return JsonResponse
         * @throws Exception
         */
        public function upload(InstallPluginRequest $request, BufferedOutput $outputLog): JsonResponse
        {

            $this->authorize('install plugins');

            // Upload
            $pluginName = $this->plugins->upload($request);

            // Install Plugin
            Plugins::installFromDir($pluginName);

            Artisan::call('optimize:clear');
            Artisan::call('migrate', ['--force' => true], $outputLog);

            // Update purchase code
            Plugins::where('name', $pluginName)->update(['purchase_code' => $request->purchase_code]);

            return response()->json([
                'status'       => 'success',
                'message'      => __('locale.plugins.installed'),
                'redirect_url' => route('admin.plugins'),
            ]);

        }


        /**
         * Perform the specified action on a plugin.
         *
         * This function handles various actions such as enabling, disabling, and uninstalling a plugin.
         * It returns a JSON response with the status and message of the action performed.
         *
         * @param Plugins $plugin The plugin instance to perform the action on.
         * @param string  $action The action to be performed ('enable', 'disable', 'uninstall').
         * @throws Exception
         */
        public function action(Plugins $plugin, string $action, Request $request)
        {

            $this->authorize('update plugins');

            switch ($action) {
                case 'disable':
                    $plugin->disable();

                    return redirect()->route('admin.plugins')->with([
                        'status'  => 'success',
                        'message' => __('locale.plugins.plugin_disabled'),
                    ]);
                case 'enable':
                    $plugin->activate();

                    return redirect()->route('admin.plugins')->with([
                        'status'  => 'success',
                        'message' => __('locale.plugins.plugin_enabled'),
                    ]);

                case 'uninstall':

                    if ($request->has('uninstall_option') && $request->input('uninstall_option') == 'remove') {
                        $plugin->permanentlyDelete();
                    } else {
                        $plugin->deleteAndCleanup();
                    }


                    return redirect()->route('admin.plugins')->with([
                        'status'  => 'success',
                        'message' => __('locale.plugins.plugin_uninstalled'),
                    ]);

                default:

                    return redirect()->route('admin.plugins')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);
            }
        }


        /**
         * @throws Exception
         */
        public function asset($dirname, $basename)
        {

            $dirname = StringHelper::base64UrlDecode($dirname);
            $absPath = base_path(Helper::join_paths($dirname, $basename));

            if (File::exists($absPath)) {
                $mimetype = Tool::getFileType($absPath);

                return response()->file($absPath, [
                    'Content-Type'   => $mimetype,
                    'Content-Length' => filesize($absPath),
                ]);
            } else {
                abort(404);
            }
        }

    }
