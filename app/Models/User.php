<?php

    namespace App\Models;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Jobs\ImportCustomers;
    use App\Library\StringHelper;
    use App\Library\Traits\TrackJobs;
    use Exception;
    use Illuminate\Contracts\Auth\MustVerifyEmail;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\Relations\HasOne;
    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Http\UploadedFile;
    use Illuminate\Notifications\Notifiable;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Hash;
    use Intervention\Image\Facades\Image;
    use Laravel\Sanctum\HasApiTokens;
    use League\Csv\Reader;
    use Str;
    use Throwable;

    /**
     * @method static where(string $string, bool $true)
     * @method getProvider($provider)
     * @method providers()
     * @method truncate()
     * @method create(array $array)
     * @method static find($end_by)
     * @method static select(string $string)
     * @method permissions()
     * @method first()
     * @method count()
     * @method getFields()
     * @method static updateOrCreate(array $array, array $array1)
     *
     * @property mixed       is_admin
     * @property mixed       first_name
     * @property mixed       last_name
     * @property mixed       id
     * @property mixed       roles
     * @property int|null    $two_factor_code
     * @property Carbon|null $two_factor_expires_at
     */
    class User extends Authenticatable implements MustVerifyEmail
    {
        use HasApiTokens, Notifiable, TrackJobs;


        public const IMPORT_TEMP_DIR = 'app/tmp/import/';
        public const EXPORT_TEMP_DIR = 'app/tmp/export/';

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */
        protected $fillable = [
            'first_name',
            'last_name',
            'api_token',
            'password',
            'image',
            'email',
            'status',
            'is_admin',
            'is_customer',
            'active_portal',
            'two_factor',
            'two_factor_code',
            'two_factor_expires_at',
            'locale',
            'sms_unit',
            'timezone',
            'provider',
            'provider_id',
            'email_verified_at',
            'two_factor_backup_code',
            'api_sending_server',
            'webhook_url',
            'dlt_entity_id',
            'dlt_telemarketer_id',
            'partner_id',
            'api_voice_sending_server',
            'api_mms_sending_server',
            'api_viber_sending_server',
            'api_whatsapp_sending_server',
            'api_otp_sending_server',
            'uid',
            'created_at',
            'updated_at',
            'password_changed_at',
        ];

        /**
         * The attributes that should be hidden for arrays.
         *
         * @var array
         */
        protected $hidden = [
            'password',
            'remember_token',
            'invitation_token',
        ];

        /**
         * The attributes that should be cast to native types.
         *
         * @var array
         */
        protected $casts = [
            'is_admin'              => 'boolean',
            'is_customer'           => 'boolean',
            'status'                => 'boolean',
            'two_factor'            => 'boolean',
            'last_access_at'        => 'datetime',
            'two_factor_expires_at' => 'datetime',
            'password_changed_at'   => 'datetime',
        ];

        /**
         * Find item by uid.
         */
        public static function findByUid($uid): object
        {
            return self::where('uid', $uid)->first();
        }

        public static function boot(): void
        {
            parent::boot();

            // Create uid when creating list.
            static::creating(function ($item) {
                // Create new uid
                $uid = uniqid();
                while (self::where('uid', $uid)->count() > 0) {
                    $uid = uniqid();
                }
                $item->uid = $uid;

                if (config('app.two_factor')) {
                    $item->two_factor_backup_code = self::generateTwoFactorBackUpCode();
                }

            });
        }

        /**
         * generate two factor backup code
         *
         * @return false|string
         */
        public static function generateTwoFactorBackUpCode(): bool|string
        {
            $backUpCode = [];
            for ($i = 0; $i < 8; $i++) {
                $backUpCode[] = rand(100000, 999999);
            }

            return json_encode($backUpCode);
        }

        public function customer(): HasOne
        {
            return $this->hasOne(Customer::class);
        }

        public function admin(): HasOne
        {
            return $this->hasOne(Admin::class);
        }

        /**
         * Check if user has admin account.
         */
        public function isAdmin(): bool
        {
            return $this->is_admin == 1;
        }

        /*
         *  Display Username
         */

        /**
         * Check if user has admin account.
         */
        public function isCustomer(): bool
        {
            return $this->is_customer == 1;
        }

        public function displayName(): string
        {
            return $this->first_name . ' ' . $this->last_name;
        }

        /**
         * generate two-factor code
         */
        public function generateTwoFactorCode(): void
        {
            $this->timestamps            = false;
            $this->two_factor_code       = rand(100000, 999999);
            $this->two_factor_expires_at = now()->addMinutes(10);
            $this->save();
        }

        /**
         * Reset two-factor code
         */
        public function resetTwoFactorCode(): void
        {
            $this->timestamps            = false;
            $this->two_factor_code       = null;
            $this->two_factor_expires_at = null;
            $this->save();
        }

        /**
         * Upload and resize avatar.
         */
        public function uploadImage($file): string
        {
            $path        = 'app/profile/';
            $upload_path = storage_path($path);

            if ( ! file_exists($upload_path)) {
                mkdir($upload_path, 0777, true);
            }

            $filename = 'avatar-' . $this->id . '.' . $file->getClientOriginalExtension();

            // save to server
            $file->move($upload_path, $filename);

            // create thumbnails
            $img = Image::make($upload_path . $filename);

            $img->fit(120, 120, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            })->save($upload_path . $filename . '.thumb.jpg');

            return $path . $filename;
        }

        /**
         * Get image thumb path.
         */
        public function imagePath(): string
        {
            if ( ! empty($this->image) && ! empty($this->id)) {
                return storage_path($this->image) . '.thumb.jpg';
            } else {
                return '';
            }
        }

        /**
         * Get image thumb path.
         */
        public function removeImage(): void
        {
            if ( ! empty($this->image) && ! empty($this->id)) {
                $path = storage_path($this->image);
                if (is_file($path)) {
                    unlink($path);
                }
                if (is_file($path . '.thumb.jpg')) {
                    unlink($path . '.thumb.jpg');
                }
            }
        }

        public function getCanDeleteAttribute(): bool
        {
            return $this->id !== auth()->id() && (Gate::check('delete customer'));
        }

        public function getIsSuperAdminAttribute(): bool
        {
            return $this->id === 1;
        }

        /**
         * Many-to-Many relations with Role.
         */
        public function roles(): BelongsToMany
        {
            return $this->belongsToMany(Role::class);
        }

        public function hasRole($name): bool
        {
            return $this->roles->contains('name', $name);
        }

        public function getPermissions(): Collection
        {
            $permissions = [];

            foreach ($this->roles as $role) {
                foreach ($role->permissions as $permission) {
                    if ( ! in_array($permission, $permissions, true)) {
                        $permissions[] = $permission;
                    }
                }
            }

            return collect($permissions);
        }

        /**
         * @return true
         */
        public function countSMSUnit($sms_unit): bool
        {
            if ($this->sms_unit != '-1') {
                $this->decrement('sms_unit', $sms_unit);
            }

            return true;
        }

        public function smsUnit()
        {
            return $this->sms_unit;
        }

        // Get the current time in Customer timezone

        /**
         * @throws Exception
         */
        public function getCurrentTime()
        {
            return $this->parseDateTime(null);
        }

        /**
         * @throws Exception
         */
        public function parseDateTime($datetime, $fallback = false)
        {
            // IMPORTANT: datetime string must NOT contain timezone information
            try {
                $dt = Carbon::parse($datetime, $this->timezone);
                $dt = $dt->timezone($this->timezone);
            } catch (Exception $ex) {
                if ($fallback) {
                    $dt = $this->parseDateTime('1900-01-01');
                } else {
                    throw $ex;
                }
            }

            return $dt;
        }

        public function sendingServers()
        {
            return $this->hasMany(CustomerBasedSendingServer::class);
        }

        /**
         * get route key by uid
         */
        public function getRouteKeyName(): string
        {
            return 'uid';
        }

        public function announcements()
        {
            return $this->belongsToMany(Announcements::class)
                ->withPivot('read_at')
                ->withTimestamps();
        }

        public function parent()
        {
            return $this->belongsTo(User::class, 'parent_id');
        }

        public function ownedWorkspaces(): HasMany
        {
            return $this->hasMany(Workspace::class, 'owner_user_id');
        }

        public function workspaceMemberships(): HasMany
        {
            return $this->hasMany(WorkspaceMembership::class, 'user_id');
        }

        public function activeWorkspaceMemberships(): HasMany
        {
            return $this->workspaceMemberships()->where('is_active', true);
        }


        public function importJobs()
        {
            return $this->jobMonitors()->orderBy('job_monitors.id', 'DESC')
                ->whereIn('job_type', [ImportCustomers::class]);
        }


        /**
         * @throws Exception
         */
        public function uploadCsv(UploadedFile $httpFile)
        {
            $filename = "import-customers-" . uniqid() . ".csv";

            // store it to storage/
            $httpFile->move($this->getImportFilePath(), $filename);

            $filepath = $this->getImportFilePath($filename);

            // Make sure file is accessible
            chmod($filepath, 0775);

            return $filepath;
        }

        /**
         * @throws Exception
         */
        public function getImportFilePath($filename = null)
        {
            return $this->getImportTempDir($filename);
        }


        /**
         * @throws Exception
         */
        public function getImportTempDir($file = null)
        {
            $base = storage_path(self::IMPORT_TEMP_DIR);
            if ( ! File::exists($base)) {
                File::makeDirectory($base, 0777, true, true);
            }

            return Helper::join_paths($base, $file);
        }


        /**
         * Read a CSV file, returning the meta information.
         *
         * @param string $file file path
         *
         * @return array [$headers, $availableFields, $total, $results]
         * @throws Exception
         */
        public function readCsv(string $file)
        {
            try {
                // Fix the problem with MAC OS's line endings
                if ( ! ini_get('auto_detect_line_endings')) {
                    ini_set('auto_detect_line_endings', '1');
                }

                // return false or an encoding name
                $encoding = StringHelper::detectEncoding($file);

                if ( ! $encoding) {
                    // Cannot detect file's encoding
                } else if ($encoding != 'UTF-8') {
                    // Convert from {$encoding} to UTF-8";
                    StringHelper::toUTF8($file, $encoding);
                } else {
                    // File encoding is UTF-8
                    StringHelper::checkAndRemoveUTF8BOM($file);
                }

                // Run this method anyway
                // to make sure mb_convert_encoding($content, 'UTF-8', 'UTF-8') is always called
                // which helps resolve the issue of
                //     "Error executing job. SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\x83??s k...' for column 'company' at row 2562 (SQL: insert into `dlk__tmp_subscribers..."
                StringHelper::toUTF8($file);

                // Read CSV files
                $reader = Reader::createFromPath($file);
                $reader->setHeaderOffset(0);
                // get the headers, using array_filter to strip empty/null header


                $headers = $reader->getHeader();

                // Make sure the headers are present
                // In case of duplicate column headers, an exception shall be thrown by League
                foreach ($headers as $index => $header) {

                    if (is_null($header) || empty(trim($header))) {
                        throw new GeneralException(__('locale.contacts.import_file_header_empty', ['index' => $index]));
                    }
                }

                // Remove leading/trailing spaces in headers, keep letter case
                $headers = array_map(function ($r) {
                    return trim($r);
                }, $headers);

                /*
                $headers = array_filter(array_map(function ($value) {
                    return strtolower(trim($value));
                }, $reader->getHeader()));


                // custom fields of the list
                $fields = collect($this->fields)->map(function ($field) {
                    return strtolower($field->tag);
                })->toArray();

                // list's fields found in the input CSV
                $availableFields = array_intersect($headers, $fields);

                // Special fields go here
                if (!in_array('tags', $availableFields)) {
                    $availableFields[] = 'tags';
                }
                // ==> phone, first_name, last_name, tags
                */

                // split the entire list into smaller batches
                $results = $reader->getRecords($headers);

                return [$headers, iterator_count($results), $results];
            } catch (Exception $ex) {
                // @todo: translation here
                // Common errors that will be caught: duplicate column, empty column
                throw new Exception('Invalid headers. Original error message is: ' . $ex->getMessage());
            }
        }


        public function generateAutoMapping($headers)
        {
            $exactMatching = function ($text1, $text2) {
                $matchRegx = '/[^a-zA-Z0-9]/';
                if (strtolower(trim(preg_replace($matchRegx, ' ', $text1))) == strtolower(trim(preg_replace($matchRegx, ' ', $text2)))) {
                    return true;
                } else {
                    return false;
                }
            };

            $relativeMatching = function ($text1, $text2) {
                $minMatchScore = 62.5;
                similar_text(strtolower(trim($text1)), strtolower(trim($text2)), $percentage);

                return $percentage >= $minMatchScore;
            };

            $automap = [];

            foreach ($this->getFields() as $field) {
                // Check for exact matching
                foreach ($headers as $key => $header) {
                    if ($exactMatching($field->tag, $header) || $exactMatching($field->label, $header)) {
                        $automap[$header] = $field->id;
                        unset($headers[$key]);
                        break;
                    }
                }

                if (in_array($field->id, array_values($automap))) {
                    continue;
                }

                // Fall back to relative match
                foreach ($headers as $key => $header) {
                    if ($relativeMatching($field->tag, $header) || $exactMatching($field->label, $header)) {
                        $automap[$header] = $field->id;
                        unset($headers[$key]);
                        break;
                    }
                }
            }

            return $automap;
        }


        /**
         * @throws Throwable
         */
        public function dispatchImportJob($filepath, $map)
        {
            $job = new ImportCustomers($this, $filepath, $map);

            return $this->dispatchWithMonitor($job);
        }

        /**
         * @throws Throwable
         */
        public function import($filePath, $mapArray, $progressCallback = null, $invalidCallback = null)
        {
            [$headers, $total, $rows] = $this->readCsv($filePath);

            $processed = 0;
            $failed    = 0;

            foreach ($rows as $row) {
                $data = [];

                // Map CSV headers to DB fields
                foreach ($row as $key => $value) {
                    if ( ! empty($mapArray[$key])) {
                        $data[$mapArray[$key]] = trim($value);
                    }
                }

                // Basic required field check
                if (empty($data['email']) || empty($data['first_name']) || empty($data['password']) || empty($data['phone'])) {
                    $failed++;
                    if ($invalidCallback) {
                        $invalidCallback($row, 'Missing required fields.');
                    }
                    continue;
                }

                try {
                    DB::transaction(function () use ($data) {
                        $user = User::updateOrCreate(
                            ['email' => $data['email']],
                            [
                                'uid'               => $data['uid'] ?? Str::uuid(),
                                'first_name'        => $data['first_name'],
                                'last_name'         => $data['last_name'] ?? '',
                                'password'          => Hash::make($data['password'] ?? '12345678'),
                                'status'            => true,
                                'is_customer'       => true,
                                'locale'            => $data['locale'] ?? config('app.locale'),
                                'timezone'          => $data['timezone'] ?? config('app.timezone'),
                                'email_verified_at' => now(),
                                'is_admin'          => false,
                                'active_portal'     => 'customer',
                            ]
                        );

                        $customer = (new Customer)->updateOrCreate(
                            ['user_id' => $user->id],
                            [
                                'uid'                => $data['customer_uid'] ?? Str::uuid(),
                                'company'            => $data['company'] ?? null,
                                'website'            => $data['website'] ?? null,
                                'address'            => $data['address'] ?? null,
                                'city'               => $data['city'] ?? null,
                                'postcode'           => $data['postcode'] ?? null,
                                'financial_address'  => $data['address'] ?? null,
                                'financial_city'     => $data['city'] ?? null,
                                'financial_postcode' => $data['postcode'] ?? null,
                                'tax_number'         => $data['tax_number'] ?? null,
                                'state'              => $data['state'] ?? null,
                                'country'            => $data['country'] ?? null,
                                'phone'              => $data['phone'] ?? null,
                                'permissions'        => Customer::customerPermissions(),
                                'notifications'      => json_encode([
                                    'login'        => 'no',
                                    'sender_id'    => 'yes',
                                    'keyword'      => 'yes',
                                    'subscription' => 'yes',
                                    'promotion'    => 'yes',
                                    'profile'      => 'yes',
                                ]),
                            ]
                        );


                        if ($customer) {
                            $permissions     = json_decode($user->customer->permissions, true);
                            $user->api_token = $user->createToken($data['email'], $permissions)->plainTextToken;
                            $user->save();

                        }

                    });


                    $processed++;
                } catch (Throwable $e) {
                    $failed++;
                    if ($invalidCallback) {
                        $invalidCallback($row, $e->getMessage());
                    }
                }

                if ($progressCallback) {
                    $progressCallback($processed, $total, $failed, 'Importing...');
                }
            }

            return compact('processed', 'failed');
        }


        // Strategy pattern here
        public function getProgress($job)
        {
            if ($job->hasBatch()) {
                $progress               = $job->getJsonData();
                $progress['status']     = $job->status;
                $progress['error']      = $job->error;
                $progress['percentage'] = $job->getBatch()->progress();
                $progress['total']      = $job->getBatch()->totalJobs;
                $progress['processed']  = $job->getBatch()->processedJobs();
                $progress['failed']     = $job->getBatch()->failedJobs;
            } else {
                $progress           = $job->getJsonData();
                $progress['status'] = $job->status;
                $progress['error']  = $job->error;
                // The following attributes are already available
                // $progress['percentage']
                // $progress['total']
                // $progress['processed']
                // $progress['failed']
            }

            return $progress;
        }

    }
