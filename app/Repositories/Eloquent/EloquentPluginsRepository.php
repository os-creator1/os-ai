<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Library\Tool;
    use App\Models\Plugins;
    use App\Repositories\Contracts\PluginsRepository;
    use Exception;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use ZipArchive;

    class EloquentPluginsRepository extends EloquentBaseRepository implements PluginsRepository
    {
        /**
         * EloquentPluginsRepository constructor.
         *
         * @param Plugins $plugin
         */
        public function __construct(Plugins $plugin)
        {
            parent::__construct($plugin);
        }

        /**
         * Upload a plugin to tmp.
         *
         * @param Request $request
         *
         * @return JsonResponse
         *
         * @throws Exception
         */

        public function upload(Request $request)
        {

            $purchase_code = $request->input('purchase_code');

            $post_data = [
                'purchase_code' => $purchase_code,
            ];


            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://ultimatesms.codeglen.com/verify/');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $data = curl_exec($ch);
            curl_close($ch);

            $get_data = json_decode($data, true);

            if (is_array($get_data) && array_key_exists('status', $get_data)) {
                if ($get_data['status'] == 'success' || config('app.stage') == 'local') {

                    $pluginsPath = base_path('packages');

                    // move file to temp place
                    $tmp_path  = base_path('tmp/uploaded_plugin_' . time());
                    $file_name = $request->file('file')->getClientOriginalName();
                    $request->file('file')->move($tmp_path, $file_name);
                    $tmp_zip = Helper::join_paths($tmp_path, $file_name);

                    // read zip file check if zip archive invalid
                    $zip = new ZipArchive();
                    if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Invalid zip archive',
                        ]);
                    }

                    // unzip template archive and remove zip file
                    $zip->extractTo($tmp_path);
                    $zip->close();
                    unlink($tmp_zip);

                    // read plugin file
                    if ( ! is_file($configFile = $tmp_path . '/composer.json')) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Invalid plugin package. No meta data found',
                        ]);
                    }

                    try {
                        $config = json_decode(file_get_contents($configFile), true);
                    } catch (Exception) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Invalid plugin package. Cannot parse meta data',
                        ]);
                    }

                    $names = explode('/', $config['name']);
                    // check valid plugin name
                    if (count($names) != 2) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'Invalid plugin package. No meta data found',
                        ]);
                    }
                    $pluginVendor = $names[0];
                    $pluginName   = $names[1];

                    // check if plugin exist
                    $pluginsVendorPath = $pluginsPath . '/' . $pluginVendor;
                    $pluginsSourcePath = $pluginsVendorPath . '/' . $pluginName;

                    // check if overwrite plugin
                    if ($request->overwrite) {
                        $config['overwrite'] = true;
                    }

                    // throw exception if any required key is missing
                    self::validateMetaData($config);

                    // create folders
                    $paths = [
                        $pluginsPath,
                        $pluginsVendorPath,
                        $pluginsSourcePath,
                    ];

                    foreach ($paths as $path) {
                        if ( ! file_exists($path)) {
                            mkdir($path, 0777, true);
                        }
                    }

                    // move from tmp to storage/app/plugins
                    Tool::xdelete($pluginsSourcePath);
                    if ( ! file_exists($pluginsSourcePath)) {
                        mkdir($pluginsSourcePath, 0777, true);
                    }
                    Tool::xcopy($tmp_path, $pluginsSourcePath);
                    Tool::xdelete($tmp_path);


                    // Step 1: Update the main composer.json to include the new package
                    $mainComposerPath                                           = base_path('composer.json');
                    $mainComposer                                               = json_decode(file_get_contents($mainComposerPath), true);
                    $mainComposer['repositories'][]                             = [
                        'type'    => 'path',
                        'url'     => 'packages/' . $pluginVendor . '/' . $pluginName,
                        'options' => [
                            'symlink' => true,
                        ],
                    ];
                    $mainComposer['require'][$pluginVendor . '/' . $pluginName] = '*';

                    file_put_contents($mainComposerPath, json_encode($mainComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                    // Step 2: Run Composer commands to install the new package
                    // This will register the new package's service provider and migrations
                    // Note: This requires the `composer` executable to be available on the server.
                    $composerCommand = 'composer require ' . $pluginVendor . '/' . $pluginName;
                    exec($composerCommand);

                    return $config['name'];
                }

                return response()->json([
                    'message' => $get_data['msg'],
                    'status'  => 'error',
                ]);
            }

            return response()->json([
                'message' => 'Invalid request',
                'status'  => 'error',
            ]);
        }

        /**
         * @throws Exception
         */
        public function enable(Plugins $plugin)
        {

            $plugin->activate();

            return $plugin;

        }

        public function disable(Plugins $plugin)
        {

        }

        public function uninstall(Plugins $plugin)
        {

        }

        public function settings(Plugins $plugin)
        {

        }

        /**
         * @throws Exception
         */
        public static function validateMetaData($config = []): void
        {
            // Check required keys
            $requiredKeys = ['name', 'title', 'version', 'app_version']; // ['name', 'title', 'version', 'app_version'];
            foreach ($requiredKeys as $key) {
                if ( ! array_key_exists($key, $config)) {
                    throw new GeneralException("Invalid plugin package. Unknown '{$key}'");
                }
            }


            // Check app version
            $currentVersion  = config('app.version');
            $requiredVersion = $config['app_version'];
            $checked         = version_compare($currentVersion, $requiredVersion, '>=');
            if ( ! $checked) {
                throw new GeneralException(trans('messages.plugin.error.version', [
                    'current'  => $currentVersion,
                    'required' => $requiredVersion,
                ]));
            }

            // // Check license type
            // if (empty(Setting::get('license_type'))) {
            //     throw new \Exception("The uploaded plugin requires a valid license of ultimate sms to proceed");
            // }

            // // Check license type
            // if (Setting::get('license_type') != 'extended') {
            //     throw new \Exception("The uploaded plugin requires an 'Extended' license of ultimate sms to proceed, your current license is 'Regular'");
            // }
        }

    }
