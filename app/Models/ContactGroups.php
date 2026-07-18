<?php

    namespace App\Models;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Jobs\ImportContacts;
    use App\Jobs\ReplicateContacts;
    use App\Library\ContactGroupFieldMapping;
    use App\Library\StringHelper;
    use App\Library\Traits\HasCache;
    use App\Library\Traits\TrackJobs;
    use App\Rules\Phone;
    use Exception;
    use File;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Http\UploadedFile;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Validator;
    use League\Csv\Reader;
    use Throwable;

    /**
     * @method static where(string $string, string $uid)
     * @method static offset(mixed $start)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static count()
     * @method static find(mixed $target_group)
     * @method static cursor()
     * @method static whereIn(string $string, array $contact_groups)
     * @method static select(string $string, string $string1)
     * @method create(mixed $list)
     * @method jobs()
     *
     * @property mixed name
     * @property mixed cache
     */
    class ContactGroups extends Model
    {
        use HasCache;
        use TrackJobs;

        protected $table = 'contact_groups';

        public const IMPORT_TEMP_DIR = 'app/tmp/import/';
        public const EXPORT_TEMP_DIR = 'app/tmp/export/';


        protected $fillable = [
            'customer_id',
            'name',
            'sender_id',
            'send_welcome_sms',
            'unsubscribe_notification',
            'send_keyword_message',
            'status',
            'welcome_sms',
            'unsubscribe_sms',
            'cache',
            'batch_id',
            'sending_server',
        ];

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
            });

            // Create uid when list created.
            static::created(function ($item) {
                //  Create list default fields
                $item->createDefaultFields();
            });

            static::deleted(function ($item) {
                if ( ! is_null($item->contact)) {
                    $item->contact->delete();
                }
            });
        }

        /**
         * The attributes that should be cast to native types.
         *
         * @var array
         */
        protected $casts = [
            'status'                   => 'boolean',
            'send_welcome_sms'         => 'boolean',
            'unsubscribe_notification' => 'boolean',
            'send_keyword_message'     => 'boolean',
        ];

        /**
         * get subscribers
         */
        public function subscribers(): HasMany
        {
            return $this->hasMany(Contacts::class, 'group_id');
        }


        public function subscriberFields()
        {
            return $this->hasManyThrough(ContactsCustomField::class, ContactGroupFields::class, 'contact_group_id', 'id', 'id', 'contact_group_id');
        }

        /**
         * get contacts
         */
        public function contact(): BelongsTo
        {
            return $this->belongsTo(Contacts::class, 'group_id');
        }

        public function optInKeywords(): HasMany
        {
            return $this->hasMany(ContactGroupsOptinKeywords::class, 'contact_group');
        }

        public function optOutKeywords(): HasMany
        {
            return $this->hasMany(ContactGroupsOptoutKeywords::class, 'contact_group');
        }

        /**
         * Retrieve contact group cached data.
         *
         * @param null $default
         * @return mixed|null
         */
        public function readCache($key, $default = null): mixed
        {
            $cache = json_decode($this->cache, true);
            if (is_null($cache)) {
                return $default;
            }
            if (array_key_exists($key, $cache)) {
                if (is_null($cache[$key])) {
                    return $default;
                } else {
                    return $cache[$key];
                }
            } else {
                return $default;
            }
        }

        /**
         * update cache value
         *
         * @param null $key
         */
        public function updateCache($key = null): void
        {
            $index = [
                'SubscribersCount'  => function ($group) {
                    return $group->subscribersCount();
                },
                'TotalSubscribers'  => function ($group) {
                    return $group->totalSubscribers();
                },
                'UnsubscribesCount' => function ($group) {
                    return $group->unsubscribesCount();
                },
            ];

            // retrieve cached data
            $cache = json_decode($this->cache, true);
            if (is_null($cache)) {
                $cache = [];
            }

            if (is_null($key)) {
                foreach ($index as $key => $callback) {
                    $cache[$key] = $callback($this);
                }
            } else {
                $callback    = $index[$key];
                $cache[$key] = $callback($this);
            }

            // write back to the DB
            $this->cache = json_encode($cache);
            $this->save();

        }

        /**
         * get total amount of subscribers in single list
         */
        public function subscribersCount($cache = false)
        {
            if ($cache) {
                return $this->readCache('SubscribersCount', 0);
            }

            return $this->subscribers()->where('status', 'subscribe')->count();
        }

        /**
         * get total amount of subscribers in single list
         */
        public function totalSubscribers($cache = false)
        {
            if ($cache) {
                return $this->readCache('TotalSubscribers', 0);
            }

            return $this->subscribers()->count();
        }

        /**
         * get total amount of subscribers in single list
         */
        public function unsubscribesCount($cache = false)
        {
            if ($cache) {
                return $this->readCache('UnsubscribesCount', 0);
            }

            return $this->subscribers()->where('status', '!=', 'subscribe')->count();
        }


        /**
         * get route key by uid
         */
        public function getRouteKeyName(): string
        {
            return 'uid';
        }

        public function __toString(): string
        {
            return $this->name;
        }

        /*
        |--------------------------------------------------------------------------
        | Version 3.7
        |--------------------------------------------------------------------------
        |
        |
        |
        */

        public function importJobs()
        {
            return $this->jobMonitors()->orderBy('job_monitors.id', 'DESC')
                ->whereIn('job_type', [ImportContacts::class, ReplicateContacts::class]);
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

        public function customer()
        {
            return $this->belongsTo(User::class);
        }

        /*Version 3.9*/

        public function contactGroupFields(): HasMany
        {
            return $this->hasMany(ContactGroupFields::class, 'contact_group_id');
        }

        public function segments(): HasMany
        {
            return $this->hasMany(Segments::class, 'contact_group_id');
        }

        /**
         * Get all fields.
         *
         * @return object
         */
        public function getFields()
        {
            return $this->contactGroupFields();
        }

        public function createDefaultFields()
        {
            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.phone'),
                'tag'              => 'PHONE',
                'required'         => true,
                'is_phone'         => true,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.first_name'),
                'tag'              => 'FIRST_NAME',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.last_name'),
                'tag'              => 'LAST_NAME',
                'required'         => false,
                'visible'          => true,
            ]);

        }

        public function createUpdateFields()
        {
            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.phone'),
                'tag'              => 'PHONE',
                'required'         => true,
                'is_phone'         => true,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.first_name'),
                'tag'              => 'FIRST_NAME',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.last_name'),
                'tag'              => 'LAST_NAME',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.email'),
                'tag'              => 'EMAIL',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.username'),
                'tag'              => 'USERNAME',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.company'),
                'tag'              => 'COMPANY',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.address'),
                'tag'              => 'ADDRESS',
                'required'         => false,
                'visible'          => true,
            ]);

            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.birth_date'),
                'tag'              => 'BIRTH_DATE',
                'required'         => false,
                'visible'          => true,
            ]);


            $this->contactGroupFields()->create([
                'contact_group_id' => $this->id,
                'type'             => 'text',
                'label'            => __('locale.labels.anniversary_date'),
                'tag'              => 'ANNIVERSARY_DATE',
                'required'         => false,
                'visible'          => true,
            ]);

        }

        /**
         * Get Phone number field
         *
         * @return Model|HasMany|object|null
         */
        public function getPhoneField()
        {
            return $this->contactGroupFields()->where('is_phone', true)->first();
        }

        /**
         * Retrieves the field by the given tag.
         *
         * @param string $tag The tag to search for.
         */
        public function getFieldByTag(string $tag)
        {
            // Case insensitive search
            return $this->contactGroupFields()->where(DB::raw('LOWER(tag)'), '=', strtolower($tag))->first();
        }

        /**
         * Returns the rules for each field in the form.
         *
         * @return array The array containing the rules for each field.
         */
        public function getFieldRules()
        {
            $rules = [];
            foreach ($this->getFields as $field) {
                if ($field->tag == 'PHONE') {
                    $rules[$field->tag] = 'required|numeric';
                } else if ($field->required) {
                    $rules[$field->tag] = 'required';
                }
            }

            return $rules;
        }

        /**
         * Generate the function comment for the given function body in a markdown code block with the correct language syntax.
         *
         * @return Collection
         */
        public function getSegmentSelectOptions()
        {
            return $this->segments->map(function ($item) {
                return ['value' => $item->uid, 'text' => $item->name];
            });
        }

        /**
         * Get the date fields.
         *
         * @return Collection
         */
        public function getDateFields()
        {
            return $this->getFields()->whereIn('type', ['date', 'datetime'])->get();
        }

        /**
         * Retrieves the select options for a field.
         *
         * This function retrieves the select options for a field by iterating over
         * all the fields and creating an array of options with the field's label
         * as the text and the field's UID as the value.
         *
         * @return array The select options for the field.
         */
        public function getFieldSelectOptions()
        {
            $options = [];
            foreach ($this->getFields()->get() as $field) {
                $options[] = ['text' => $field->label, 'value' => $field->uid];
            }

            return $options;
        }

        /**
         * Returns the count of segments.
         *
         * @return int The count of segments.
         */
        public function segmentsCount()
        {
            return $this->segments()->count();
        }


        /**
         * @throws Exception
         */
        public function uploadCsv(UploadedFile $httpFile)
        {
            $filename = "import-" . uniqid() . ".csv";

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


        public function dispatchImportJob($filepath, $map)
        {
            $job = new ImportContacts($this, $filepath, $map);

            return $this->dispatchWithMonitor($job);
        }

        public function checkBlacklist($phone = null)
        {
            $phone       = preg_replace('/[^\d]/', '', $phone);
            $customer_id = $this->customer_id ?? auth()->id();

            $cacheKey = "blacklist_check_{$customer_id}_{$phone}";

            return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($customer_id, $phone) {
                return Blacklists::where('user_id', $customer_id)
                    ->where('number', $phone)
                    ->exists();
            });
        }


        /**
         * @throws Exception
         */
        public function import($file, $mapArray = null, $progressCallback = null, $invalidRecordCallback = null)
        {
            /* START trick: auto generate map if there is no map passed to the function */

            if (is_null($mapArray)) {
                [$headers, $total, $results] = $this->readCsv($file);
                $mapArray = [];
                foreach ($this->fields as $field) {
                    foreach ($headers as $header) {
                        if (strtolower($field->tag) == strtolower($header)) {
                            $mapArray[$header] = $field->id;
                        }
                    }
                }
            }

            /* END trick */

            $map = ContactGroupFieldMapping::parse($mapArray, $this);

            $processed = 0;
            $failed    = 0;
            $total     = 0;
            $message   = null;

            if ( ! is_null($progressCallback)) {
                $progressCallback($processed, $total, $failed, $message = __('locale.contacts.importing'));
            }

            // Read CSV files, keep original headers' case (do not lowercase or uppercase)
            [$headers, $total, $results] = $this->readCsv($file);
            try {
                // process by batches
                Helper::each_batch($results, config('app.import_batch_size'), false, function ($batch) use ($map, &$processed, &$failed, $total, &$overQuotaAttempt, &$message, $progressCallback, $invalidRecordCallback) {

                    // Create a tmp table like: __tmp_subscribers(field_1, field_2, field_3, field_4, tags)
                    [$tmpTable, $phoneFieldName] = $map->createTmpTableFromMapping();

                    $data = collect($batch)->map(function ($r) use ($phoneFieldName, $map) {

                        $record = $map->updateRecordHeaders($r);

                        // replace the non-break space (not a normal space) as well as all other spaces
                        $record[$phoneFieldName] = strtolower(preg_replace('/[ \s*]*/', '', trim($record[$phoneFieldName])));

                        // Process tag values
                        // @important: tags field must be lower-case
                        if (array_key_exists('tags', $record) && ! empty($record['tags'])) {
                            $record['tags'] = json_encode(array_filter(preg_split('/\s*,\s*/', $record['tags'])));
                        }

                        // So we need to remove it, at least for phone field
                        $record[$phoneFieldName] = StringHelper::removeExtraCharacters($record[$phoneFieldName]);

                        return $record;
                    })->toArray();

                    // make the import data table unique by phone
                    $data = Helper::array_unique_by($data, function ($r) use ($phoneFieldName) {
                        return $r[$phoneFieldName];
                    });

                    // validate and filter out invalid records
                    $data = collect($data)->filter(function ($record) use (&$failed, $invalidRecordCallback, $phoneFieldName) {
                        [$valid, $errors] = $this->validateCsvRecord($record, $phoneFieldName);

                        // Combine the blacklist check into a single condition
                        $isBlacklisted = $this->checkBlacklist($record[$phoneFieldName]);

                        if ( ! $valid || $isBlacklisted) {
                            $failed += 1;

                            // Determine the reason for failure
                            $reason = ! $valid ? implode('; ', $errors) : 'Blacklisted number';

                            // Use the new callback to log the failed record
                            if ( ! is_null($invalidRecordCallback)) {
                                $invalidRecordCallback($record, $reason);
                            }

                            return false;
                        }

                        return true;
                    })->all();

                    // INSERT TO tmp TABLE
                    DB::table('__tmp_subscribers')->insert($data);
                    $newRecordCount = DB::select("SELECT COUNT(*) AS count FROM $tmpTable tmp LEFT JOIN " . Helper::table('contacts') . " main ON (tmp.$phoneFieldName = main.phone AND main.group_id = $this->id) WHERE main.phone IS NULL")[0]->count;

                    if ($newRecordCount > 0) {
                        // Only warning at this time
                        // when there is new records to INSERT but there is no more insert credit
                        // It is just fine if $newRecordCount == 0, then only update existing subscribers
                        // Just let it proceed until finishing
                        $overQuotaAttempt = true;
                    }

                    // processing for every batch,
                    // using transaction to only commit at the end of the batch execution
                    DB::beginTransaction();

                    // Insert new subscribers from temp table to the main table
                    // Use SUBSTRING(MD5(UUID()), 1, 13) to produce a UNIQUE ID which is similar to the output of PHP uniqid()
                    // @TODO LIMITATION: tags are not updated if subscribers already exist
                    $insertToSubscribersSql = strtr(
                        '
                    INSERT INTO %contacts (uid, customer_id ,group_id, phone, status, created_at, updated_at)
                    SELECT SUBSTRING(MD5(UUID()), 1, 13), %customer_id, %list_id, uniq.phone, %status, NOW(), NOW()
                    FROM (
                        SELECT tmp.%phone_field AS phone, tmp.tags
                        FROM %tmp tmp
                        LEFT JOIN %contacts main ON (tmp.%phone_field = main.phone AND main.group_id = %list_id)
                        WHERE main.phone IS NULL) uniq',
                        [
                            '%contacts'    => Helper::table('contacts'),
                            '%customer_id' => $this->customer->id,
                            '%list_id'     => $this->id,
                            '%status'      => Helper::db_quote('subscribe'),
                            '%tmp'         => $tmpTable,
                            '%phone_field' => $phoneFieldName,
                        ]
                    );

                    DB::statement($insertToSubscribersSql);

                    // Insert subscribers' custom fields to the fields table
                    // Before inserting, delete records from `subscriber_fields` whose phone matches that of the $tmpTable
                    // As a result, any existing attributes of subscribers shall be overwritten

                    // OPTION 1: DELETE WHERE IN
                    // DB::statement('DELETE FROM '.table('subscriber_fields').' WHERE subscriber_id IN (SELECT main.id FROM '.Helper::table('contacts')." main JOIN {$tmpTable} tmp ON main.phone = tmp.phone WHERE contact_group_id = ".$this->id.')');

                    // OPTION 2: DELETE JOIN
                    DB::statement(sprintf(
                        'DELETE f
                    FROM %s main
                    JOIN %s tmp ON main.phone = tmp.%s
                    JOIN %s f ON main.id = f.contact_id
                    WHERE group_id = %s;',
                        Helper::table('contacts'),
                        $tmpTable,
                        $phoneFieldName,
                        Helper::table('contacts_custom_field'),
                        $this->id
                    ));

                    foreach ($map->mapping as $fieldId) {
                        $fieldName                   = $map->generateFieldNameFromId($fieldId);
                        $insertToSubscriberFieldsSql = strtr('
                        INSERT INTO %contacts_custom_field (contact_id, field_id, value, created_at, updated_at)
                        SELECT t.contact_id, %fid, t.value, NOW(), NOW()
                        FROM (
                            SELECT main.id AS contact_id, tmp.`%field_name` AS value
                            FROM %contacts main JOIN %tmp tmp ON tmp.`%phone_field` = main.phone
                        ) t
                    ', [
                            '%contacts'              => Helper::table('contacts'),
                            '%contacts_custom_field' => Helper::table('contacts_custom_field'),
                            '%fid'                   => $fieldId,
                            '%field_name'            => $fieldName,
                            '%tmp'                   => $tmpTable,
                            '%phone_field'           => $phoneFieldName,
                        ]);

                        DB::statement($insertToSubscriberFieldsSql);
                    }


                    // update status, finish one batch
                    $processed += sizeof($batch);
                    if ( ! is_null($progressCallback)) {
                        $progressCallback($processed, $total, $failed, $message = 'Inserting contacts to database...');
                    }

                    // Actually write to the database
                    DB::commit();

                    // Cleanup
                    DB::statement("DROP TABLE IF EXISTS $tmpTable;");

                    // Trigger updating related campaigns cache
                    $this->updateCache();

                });

                if ( ! is_null($progressCallback)) {
                    $progressCallback($processed, $total, $failed, $message = "Processed: $processed/$total records, skipped: $failed records.");
                }

            } catch (Throwable $e) {
                // IMPORTANT: rollback first before throwing, otherwise it will be a deadlock
                DB::rollBack();

                if ( ! is_null($progressCallback)) {
                    $progressCallback($processed, $total, $failed, $message = $e->getMessage());
                }

                // Is is weird that, in certain case, the $e->getMessage() string is too long, making the job "hang";
                throw new Exception(substr($e->getMessage(), 0, 512));
            } finally {
                // @IMPORTANT: if process fails here, something weird occurs
                $this->reformatDateFields();
                $this->updateCache();

                if ( ! is_null($progressCallback)) {
                    $progressCallback($processed, $total, $failed, $message);
                }
            }
        }

        private function validateCsvRecord($record, $phoneFieldName = 'phone')
        {
            // Check if the phone number field is empty.
            if (empty($record[$phoneFieldName])) {
                return [false, [__('locale.contacts.empty_phone_number')]];
            }

            $rules = [
                $phoneFieldName => ['required', new Phone($record[$phoneFieldName])],
            ];

            $messages = [
                $phoneFieldName . '.required' => __('locale.contacts.empty_phone_number'),
                $phoneFieldName               => __('locale.customer.invalid_phone_number', ['phone' => $record[$phoneFieldName]]),
            ];

            $validator = Validator::make($record, $rules, $messages);

            return [$validator->passes(), $validator->errors()->all()];
        }

        /**
         *
         *
         * @param $contact_id
         * @return void
         * @throws Exception
         */
        public function reformatDateFields($contact_id = null)
        {
            $type = 'date';

            $query = $this->subscriberFields()
                ->where('contact_group_fields.type', $type)
                ->select('contacts_custom_field.id', 'contacts_custom_field.value');

            if ( ! is_null($contact_id)) {
                $query = $query->where('contact_id', $contact_id);
            }

            $query->perPage(1000, function ($batch) {
                $fixedValues = $batch->get()->map(function ($r) {
                    return [
                        'id'    => $r->id,
                        'value' => $this->customer->parseDateTime($r->value, true)->format(config('custom.date_format')),
                    ];
                })->toArray();

                $this->createTemporaryTableFromArray(
                    "_tmp_{$this->customer->uid}_date_values",
                    $fixedValues,
                    [
                        'id BIGINT',
                        'value VARCHAR(191) CHARACTER SET utf8 COLLATE utf8_unicode_ci',
                    ],
                    function ($table) {
                        DB::statement(sprintf("UPDATE %s sf INNER JOIN %s t ON sf.id = t.id SET sf.value = t.value", Helper::table('contact_group_fields'), Helper::table($table)));
                    }
                );
            });
        }


        /**
         * @throws Exception
         */
        public function createTemporaryTableFromArray($tableName, $data, $fields, $callback = null)
        {
            // Note: data must be an array of hash
            // Note: fields format looks like this:

            try {
                DB::beginTransaction();

                $table     = Helper::table($tableName); // with prefix
                $fieldsSql = implode(',', $fields);

                DB::statement("DROP TABLE IF EXISTS $table;");
                DB::statement("CREATE TABLE $table($fieldsSql);");

                // Actually insert data
                DB::table($tableName)->insert($data);


                // Pass to the controller for handling
                if ( ! is_null($callback)) {
                    $callback($tableName); // Note: it is without prefix
                }

                // Cleanup
                DB::statement("DROP TABLE IF EXISTS $table;");

                // It is all done
                DB::commit();
            } catch (Exception $e) {
                // finish the transaction
                DB::rollBack();
                throw $e;
            }
        }


        /**
         * Version 3.11
         *
         * @return mixed
         *
         * @throws Exception
         */

        public function getExportFilePath()
        {
            $name = preg_replace('/[^a-zA-Z0-9_\-\.]+/', '_', "{$this->uid}-{$this->name}.csv");

            return $this->getExportTempDir($name);
        }

        /**
         * @throws Exception
         */
        public function getExportTempDir($file = null)
        {
            $base = storage_path(self::EXPORT_TEMP_DIR);
            if ( ! \Illuminate\Support\Facades\File::exists($base)) {
                File::makeDirectory($base, 0777, true, true);
            }

            return Helper::join_paths($base, $file);
        }


    }
