<?php

    namespace App\Http\Controllers;

    use App\Helpers\DatabaseManager;
    use App\Helpers\EnvironmentManager;
    use App\Helpers\MigrationsHelper;
    use App\Helpers\PermissionsChecker;
    use App\Helpers\RequirementsChecker;
    use App\Library\Tool;
    use App\Models\AppConfig;
    use Exception;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\Support\Facades\Artisan;
    use Symfony\Component\Console\Output\BufferedOutput;


    class UpdateController extends Controller
    {
        use MigrationsHelper;

        /**
         * @var RequirementsChecker
         */
        protected RequirementsChecker $requirements;
        protected EnvironmentManager  $EnvironmentManager;
        private DatabaseManager       $databaseManager;


        /**
         * @param RequirementsChecker $checker
         * @param EnvironmentManager  $environmentManager
         * @param DatabaseManager     $databaseManager
         */
        public function __construct(RequirementsChecker $checker, EnvironmentManager $environmentManager, DatabaseManager $databaseManager)
        {
            $this->requirements       = $checker;
            $this->EnvironmentManager = $environmentManager;
            $this->databaseManager    = $databaseManager;
        }

        public function welcome()
        {
            if (config('app.stage') == 'demo') {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry!! This feature is not available in demo mode',
                ]);
            }

            $phpSupportInfo = $this->requirements->checkPHPversion(
                config('installer.core.minPhpVersion')
            );
            $requirements   = $this->requirements->check(
                config('installer.requirements')
            );

            $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
            ];

            $getPermissions = new PermissionsChecker();

            $permissions = $getPermissions->check(
                config('installer.permissions')
            );

            return view('Installer.update.welcome', compact('requirements', 'phpSupportInfo', 'pageConfigs', 'permissions'));
        }


        /**
         * @param Request        $request
         * @param BufferedOutput $outputLog
         *
         * @return RedirectResponse
         */
        public function verifyProduct(Request $request, BufferedOutput $outputLog): RedirectResponse
        {

            $v = Validator::make($request->all(), [
                'purchase_code' => 'required|string|min:15',
            ]);

            if ($v->fails()) {
                return redirect()->route('Updater::welcome')->withErrors($v->errors());
            }

            $purchase_code = $request->input('purchase_code');
            $domain_name   = config('app.url');
            $input         = trim($domain_name, '/');
            $urlParts      = parse_url($input);
            $domain_name   = preg_replace('/^www\./', '', $urlParts['host']);

            $post_data = [
                'purchase_code' => $purchase_code,
                'domain'        => $domain_name,
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
                if ($get_data['status'] == 'success') {

                    try {

                        Artisan::call('optimize:clear');
                        Artisan::call('migrate', ['--force' => true], $outputLog);
                        Tool::versionSeeder(config('app.version'));

                        AppConfig::setEnv('APP_VERSION', '3.16.0');

                        return redirect()->route('login')->with([
                            'status'  => 'success',
                            'message' => 'You have successfully updated your application.',
                        ]);
                    } catch (Exception $e) {

                        return redirect()->route('Updater::welcome')->with([
                            'status'  => 'error',
                            'message' => $e->getMessage(),
                        ]);

                    }
                }

                return redirect()->route('Updater::welcome')->with([
                    'message' => $get_data['msg'],
                    'status'  => 'error',
                ]);
            }

            return redirect()->route('Updater::welcome')->with([
                'message' => 'Invalid request',
                'status'  => 'error',
            ]);
        }

    }
