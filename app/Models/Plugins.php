<?php

    namespace App\Models;

    use App;
    use App\Helpers\Helper;
    use App\Library\Tool;
    use App\Library\Traits\HasUid;
    use Artisan;
    use Composer\Autoload\ClassLoader;
    use Exception;
    use File;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;
    use Throwable;
    use Twig\Environment;
    use Twig\Loader\FilesystemLoader;
    use App\Library\Facades\Hook;
    use Validator;
    use ZipArchive;
    use function App\Helpers\pcopy;

    /**
     * @method static where(string $string, $pluginName)
     */
    class Plugins extends Model
    {
        use HasUid;

        public const STATUS_ACTIVE   = 'active';
        public const STATUS_INACTIVE = 'inactive';


        protected       $fillable     = ['name', 'title', 'description', 'version', 'purchase_code'];
        protected array $requiredKeys = [
            'name', 'version', 'app_version',
        ];


        /**
         * Disable verification server.
         *
         * @return void
         * @throws Exception
         */
        public function disable(): void
        {
            $this->status = self::STATUS_INACTIVE;
            $this->save();
            self::updatePluginMasterFile($this->name, [
                'status' => $this->status,
                'error'  => null, // clean up error
            ]);

            AppConfig::setEnv('USUPPORT_ENABLED', "false");
        }

        /**
         * @param $name
         * @param $params
         *
         * @return void
         * @throws Exception
         */
        public static function updatePluginMasterFile($name, $params): void
        {
            // File storing plugin index (meta data)
            $index = self::getPluginMasterFile();

            // Get json data from file
            if ( ! file_exists($index)) {
                $data = [];
            } else {
                $data = json_decode(file_get_contents($index), true);
            }

            if (is_null($params)) {
                // null means: delete plugin from index
                unset($data[$name]);
            } else {
                $plugininfo  = array_key_exists($name, $data) ? $data[$name] : [];
                $plugininfo  = array_merge($plugininfo, $params);
                $plugininfo  = array_filter($plugininfo); // filter out null keys
                $data[$name] = $plugininfo;

                // Currently, only 'status' is required in this master file
                if ( ! array_key_exists('status', $data[$name])) {
                    throw new Exception('Plugin\'s "status" attribute is required in plugin master file');
                }
            }

            // Write back to file
            file_put_contents($index, json_encode($data));
        }

        public static function getPluginMasterFile(): string
        {
            return config('plugins.index');
        }

        /**
         * Upload a plugin to tmp.
         *
         * @throws Exception
         */
        public static function upload($request)
        {
            $pluginsPath = base_path('packages');

            // move file to temp place
            $tmp_path  = storage_path('tmp/uploaded_plugin_' . time());
            $file_name = $request->file('file')->getClientOriginalName();
            $request->file('file')->move($tmp_path, $file_name);
            $tmp_zip = Helper::join_paths($tmp_path, $file_name);

            // read zip file check if zip archive invalid
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) {
                throw new App\Exceptions\GeneralException('Invalid zip archive');
            }

            // unzip template archive and remove zip file
            $zip->extractTo($tmp_path);
            $zip->close();
            unlink($tmp_zip);

            // read plugin file
            if ( ! is_file($configFile = $tmp_path . '/composer.json')) {
                throw new App\Exceptions\GeneralException('Invalid plugin package. No meta data found');
            }

            try {
                $config = json_decode(file_get_contents($configFile), true);
            } catch (Exception) {
                throw new App\Exceptions\GeneralException('Invalid plugin package. Cannot parse meta data');
            }

            $names = explode('/', $config['name']);
            // check valid plugin name
            if (count($names) != 2) {
                throw new App\Exceptions\GeneralException('Invalid plugin package. No meta data found');
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

            return $config['name'];
        }


        /**
         * Get plugin path.
         *
         * @return string
         * @throws Exception
         */
        public function getPluginPath(): string
        {
            return base_path(Helper::join_paths('packages', $this->name));
        }


        public static function isInstalled($name)
        {
            return self::where('name', '=', $name)->count();
        }

        public function isActive(): bool
        {
            return $this->status == self::STATUS_ACTIVE;
        }


        public static function getByName($name)
        {
            return self::where('name', '=', $name)->first();
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
                    throw new Exception("Invalid plugin package. Unknown '{$key}'");
                }
            }


            // Check app version
            $currentVersion  = config('app.version');
            $requiredVersion = $config['app_version'];
            $checked         = version_compare($currentVersion, $requiredVersion, '>=');
            if ( ! $checked) {
                throw new Exception(trans('locale.plugins.version_mismatch', [
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

        public function getData()
        {
            if ( ! $this['data']) {
                return json_decode('{}', true);
            }

            return json_decode($this['data'], true);
        }

        public function updateData($data): void
        {
            $data         = (object) array_merge((array) $this->getData(), $data);
            $this['data'] = json_encode($data);

            $this->save();
        }

        /**
         * @throws Exception
         */
        public function activate(): void
        {
            $hookName = 'activate_plugin_' . $this->name;
            Hook::execute($hookName);

            $config = $this->getComposerJson();
            self::validateMetaData($config);

            $this->status = self::STATUS_ACTIVE;
            $this->save();

            AppConfig::setEnv('USUPPORT_ENABLED', "true");

            self::updatePluginMasterFile($this->name, [
                'status' => $this->status,
                'error'  => null, // clean up error
            ]);
        }

        /**
         * @throws Exception
         */
        public function reset(): void
        {
            $this->status = self::STATUS_INACTIVE;
            $this->data   = null;
            $this->save();
            self::updatePluginMasterFile($this->name, [
                'status' => $this->status,
                'error'  => null,
            ]);
        }

        /**
         * @throws Exception
         */
        public function getStoragePath($subpath = null): string
        {
            return self::getStoragePathByName($this->name, $subpath);
        }

        /**
         * @throws Exception
         */
        public function getComposerJsonFilePath(): string
        {
            return self::getComposerJsonFilePathByName($this->name);
        }

        /**
         * @throws Exception
         */
        public function getComposerJson()
        {
            return self::getComposerJsonByName($this->name);
        }


        /**
         * @throws Exception
         */
        public static function autoload(): void
        {
            $activePlugins = self::all();
            foreach ($activePlugins as $plugin) {
                try {
                    $plugin->load();
                } catch (Exception $ex) {
                    throw new Exception($ex->getMessage());
                }
            }

        }

        /**
         * @throws Exception
         */
        public function load($relations = true): void
        {
            self::loadPluginByName($this->name, $relations);
        }

        /**
         * @throws Exception
         */
        public static function installFromDir($name)
        {
            // Instantiate the plugin object, not save yet
            $plugin = self::firstOrNew(['name' => $name]);

            // plugin.name attribute is required
            // before we can get other information
            // Validation may throw an exception
            $composer = $plugin->getComposerJson();

            if ($plugin->name != $composer['name']) {
                throw new Exception("Plugin name in composer.json is expected to be '{$plugin->name}', found '" . $composer['name'] . "'");
            }

            $plugin->title       = $composer['title'];
            $plugin->description = array_key_exists('description', $composer) ? $composer['description'] : null;
            $plugin->version     = array_key_exists('version', $composer) ? $composer['version'] : '0.0.0';
            $plugin->status      = self::STATUS_INACTIVE;
            $plugin->save();
            self::updatePluginMasterFile($plugin->name, ['status' => $plugin->status]);

            // Important: publish language files after installing a plugin
            // Remember to load the plugin first, make sure its service provider runs first
            $plugin->load();


            // Public plugins' assets with 'plugin' tag
            Artisan::call('vendor:publish', ['--force' => true, '--tag' => 'plugin']);

            // Return it
            return $plugin;
        }


        public static function enabled($name)
        {
            return self::where('name', '=', $name)
                ->where('status', '=', self::STATUS_ACTIVE)
                ->exists();
        }

        /**
         * @throws Exception
         */
        public function deleteAndCleanup(): void
        {
            $name = $this->name;

            Hook::execute('delete_plugin_' . $name);

            $this->deletePluginDirectory();
            $this->delete();
            self::updatePluginMasterFile($name, null);
        }


        /**
         * @throws Exception
         */
        public function permanentlyDelete(): void
        {
            $this->deleteAndCleanup();

            if ($this->name === 'codeglen/usupport') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');

                $tables = [
                    'support_settings',
                    'support_categories',
                    'support_articles',
                    'support_agents',
                    'ticket_tags',
                    'support_tickets',
                    'ticket_replies',
                    'ticket_attachments',
                    'ticket_ticket_tag',
                ];

                foreach ($tables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->truncate();
                    }
                }

                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
        }


        /**
         * @throws Exception
         */
        public function deletePluginDirectory(): void
        {
            $file = $this->getPluginPath();

            if ( ! file_exists($file)) {
                return;
            }

            if (is_link($file) || is_file($file)) {
                File::delete($file);
            } else {
                File::deleteDirectory($file);
            }
        }

        /**
         * @throws Exception
         */
        public static function init($plugin): void
        {
            $validator = Validator::make(['plugin' => $plugin], [
                'plugin' => [
                    'required',
                    'regex:/^[0-9a-z_]+\/[0-9a-z_]+$/',
                    function ($attribute, $value, $fail) {
                        //
                    },
                ],
            ]);

            if ($validator->fails()) {
                throw new Exception("Plugin name must be in the \"author/name\" format.\nOnly alphanumeric lowercase characters and underscores are accepted for author and name.\nFor example: awesomeplugin/my_plugin123");
            }

            [$author, $name] = explode('/', $plugin);

            $rules = [
                'min:3',
                'max:32',
                'regex:/^[a-z0-9]/',
                'regex:/[a-z0-9]$/',
                function ($attribute, $value, $fail) {
                    if (str_contains($value, '__')) {
                        $fail("Invalid :attribute ':input', must not contain double underscores '__'");
                    }
                },
            ];

            $messages = [
                'author.min'   => 'Author name must be at least 3 characters in length',
                'name.min'     => 'Plugin name must be at least 3 characters in length',
                'author.max'   => 'Author name is too long',
                'name.max'     => 'Plugin name is too long',
                'author.regex' => 'Author name ":input" is invalid. Name must start and end with a lowercase character.',
                'name.regex'   => 'Plugin name ":input" is invalid. Name must start and end with a lowercase character.',
            ];

            $validator = Validator::make(
                ['author' => $author, 'name' => $name],
                ['author' => $rules, 'name' => $rules],
                $messages
            );

            if ($validator->fails()) {
                // throw the first error
                throw new Exception($validator->errors()->first());
            }

            // OK, make the plugin
            // Copy from template
            $templatePath = database_path('plugins/awesome/hello');
            $pluginPath   = base_path("packages/{$plugin}");

            // Clone the template plugin
            pcopy($templatePath, $pluginPath);

            // Replace the templates with actual plugin names
            $loader = new FilesystemLoader($pluginPath);
            $twig   = new Environment($loader);

            $params = [
                'plugin'       => $plugin,
                'name'         => $name,
                'author'       => $author,
                'name_class'   => self::makeClassNameFromString($name),
                'author_class' => self::makeClassNameFromString($author),
            ];

            // Files to replace
            $files = [
                'composer.json',
                'routes.php',
                'build.sh',
                'src/ServiceProvider.php',
                'src/Controllers/DashboardController.php',
                'resources/views/index.blade.php',
            ];

            foreach ($files as $file) {
                $fcontent = $twig->render($file, $params);
                file_put_contents(Helper::join_paths($pluginPath, $file), $fcontent);
            }

            // Register the plugin with ultimate sms (add a record to plugins DB table)
            self::installFromDir($plugin);
        }

        public static function makeClassNameFromString($name): string
        {
            // Change "my_1st_plugin" to "My1StPlugin"
            return implode('', explode(' ', ucwords(preg_replace('/(?<=[0-9])(?=[a-z])|_/', ' ', $name))));
        }


        // This is the alternative to the static::autoload() function
        // Instead querying the DB, this function reads the list of plugins from master file
        // It is to avoid making DB queries which is not recommended in service provider
        /**
         * @throws Exception
         */
        public static function autoloadWithoutDbQuery(): void
        {
            // File storing plugin index (meta data)
            $index = self::getPluginMasterFile();

            // Get json data from file
            if ( ! file_exists($index)) {
                return;
            }

            $plugins = json_decode(file_get_contents($index), true);

            foreach ($plugins as $name => $attributes) {
                $status = $attributes['status'];

                // Currently, we need to load all plugins
                // In order to use its routes
                if ($status == self::STATUS_ACTIVE || self::STATUS_INACTIVE) {
                    try {
                        self::loadPluginByName($name);
                    } catch (Throwable $t) {
                        // Important: this block cannot catch exception in plugin's service provider itself
                        // It is because the service is registered at this time with App::register(...)
                        // but shall be executed later on, not immediately
                        self::updatePluginMasterFile($name, [
                            'error' => $t->getMessage(),
                        ]);
                    }
                }
            }
        }

        /**
         * @throws Exception
         */
        public static function getStoragePathByName($name, $subpath = null): string
        {
            if (is_null($name)) {
                throw new Exception("Plugin name is null");
            }

            if ( ! preg_match('/^[a-z0-9\-]+\/[a-z0-9\-]+$/', $name)) {
                throw new Exception("Plugin name must match 'author/name' pattern. Only lowercase letters, numbers and/or dash (-) are allowed.");
            }

            // 👇 NEW: plugin base directory inside packages/
            $base = base_path("packages/{$name}");

            return $subpath ? Helper::join_paths($base, $subpath) : $base;
        }


        /**
         * @throws Exception
         */
        public static function getComposerJsonFilePathByName($name): string
        {
            return self::getStoragePathByName($name, 'composer.json');
        }

        /**
         * @throws Exception
         */
        public static function getComposerJsonByName($name)
        {
            $pluginDir = self::getStoragePathByName($name);

            if ( ! file_exists($pluginDir)) {
                throw new Exception("Plugin folder '{$pluginDir}' missing");
            }

            $composerFile = self::getComposerJsonFilePathByName($name);

            if ( ! file_exists($composerFile)) {
                throw new Exception("Cannot find {$composerFile} in plugin folder. Invalid plugin?");
            }

            try {
                $composerJson = json_decode(file_get_contents($composerFile), true);
            } catch (Exception) {
                throw new Exception("Cannot parse {$composerFile}"); // for easy debugging
            }

            return $composerJson;
        }

        /**
         * @throws Exception
         */
        public static function loadPluginByName($name, $boot = true): void
        {
            $composerJson = self::getComposerJsonByName($name);

            if ( ! array_key_exists('autoload', $composerJson)) {
                throw new Exception("Cannot boot plugin '{$name}'. No 'autoload' found in composer.json");
            }

            if ( ! array_key_exists('psr-4', $composerJson['autoload'])) {
                throw new Exception("Cannot boot plugin '{$name}'. No 'autoload.psr4' found in composer.json");
            }

            $loader = new ClassLoader();

            $psr4 = $composerJson['autoload']['psr-4'];
            foreach ($psr4 as $prefix => $path) {
                $realpath = self::getStoragePathByName($name, $path);
                $loader->addPsr4($prefix, $realpath);
            }

            $loader->register();

            if ( ! $boot) {
                return;
            }

            // Load and boot ServiceProvider
            $serviceProviders = [];
            if (
                array_key_exists('extra', $composerJson) &&
                array_key_exists('laravel', $composerJson['extra']) &&
                array_key_exists('providers', $composerJson['extra']['laravel'])) {
                $serviceProviders = $composerJson['extra']['laravel']['providers'];
            }

            // Register service
            foreach ($serviceProviders as $service) {
                App::register($service);
            }
        }


        /**
         * @throws Exception
         */
        public static function resetPluginMasterFile(): void
        {
            $index = self::getPluginMasterFile();

            if (file_exists($index)) {
                File::delete($index);
            }

            foreach (self::all() as $plugin) {
                self::updatePluginMasterFile($plugin->name, [
                    'status' => $plugin->status,
                    'error'  => null, // drop error
                ]);
            }
        }


        public function getPluginInfo($key)
        {
            return self::getPluginInfoByName($this->name, $key);
        }

        public static function getPluginInfoByName($name, $key)
        {
            // File storing plugin index (meta data)
            $index = self::getPluginMasterFile();

            // Get json data from file
            if ( ! file_exists($index)) {
                $data = [];
            } else {
                $data = json_decode(file_get_contents($index), true);
            }

            $plugininfo = array_key_exists($name, $data) ? $data[$name] : [];

            if ($key) {
                return array_key_exists($key, $plugininfo) ? $plugininfo[$key] : null;
            } else {
                return $plugininfo;
            }
        }


        /**
         * @throws Exception
         */
        public function getIconUrl()
        {
            $possibleFileNames = ['icon.svg', 'icon.jpg', 'icon.png', 'icon.gif'];

            foreach ($possibleFileNames as $file) {
                $absPath = $this->getStoragePath($file);
                if (file_exists($absPath)) {
                    return App\Helpers\generatePublicPath($absPath);
                }
            }

            return '';
        }

    }
