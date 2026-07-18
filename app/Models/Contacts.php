<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    /**
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     * @method static insert(array $list)
     * @method static whereIn(string $string, $list_id)
     * @method static find(mixed $id)
     * @method limit(int $int)
     *
     * @property mixed name
     */
    class Contacts extends Model
    {
        use HasUid;

        public const STATUS_SUBSCRIBE   = 'subscribe';
        public const STATUS_UNSUBSCRIBE = 'unsubscribe';

        protected $table = 'contacts';

        protected $fillable = [
            'customer_id',
            'group_id',
            'phone',
            'status',
            'created_at',
            'updated_at',
        ];

        protected $casts = [
            'phone' => 'integer',
        ];

        /**
         * display contact group name
         */
        public function contactGroup(): BelongsTo
        {
            return $this->belongsTo(ContactGroups::class, 'group_id');
        }

        /**
         * Retrieves the contacts fields associated with this model.
         *
         * @return HasMany The contacts fields associated with this model.
         */
        public function contactsFields(): HasMany
        {
            return $this->hasMany('App\Models\ContactsCustomField', 'contact_id');
        }

        /**
         * Retrieve the tracking logs associated with this model.
         */
        public function trackingLogs(): HasMany
        {
            return $this->hasMany('App\Models\TrackingLogs');
        }

        /**
         * return contact name
         */
        public function display_name(): string
        {
            return $this->first_name . ' ' . $this->last_name;
        }

        public static function boot()
        {
            parent::boot();

            // Create uid when creating list.
            static::creating(function ($item) {
                $item->uid = uniqid();
            });
        }

        /**
         * Get field value by list field.
         *
         * @return string
         */
        public function getValueByField($field)
        {
            $fv = $this->contactsFields->filter(function ($r) use ($field) {
                return $r->field_id == $field->id;
            })->first();
            if ($fv) {
                return $fv->value;
            } else {
                return '';
            }
        }

        /**
         * Retrieves the value of a custom field for a contact based on the given tag.
         *
         * @param string $tag The tag of the custom field.
         * @return mixed The value of the custom field.
         */
        public function getValueByTag(string $tag)
        {
            $fv = ContactsCustomField::leftJoin('contact_group_fields as fields', 'fields.id', '=', 'contacts_custom_field.field_id')
                ->where('contact_id', '=', $this->id)->where('fields.tag', '=', $tag)->first();
            if ($fv) {
                return $fv->value;
            } else {
                return '';
            }
        }

        /**
         * Checks if the contact is listed in the blacklist.
         *
         * @return bool True if the contact is listed in the blacklist, false otherwise.
         */
        public function isListedInBlacklist()
        {
            return Blacklists::where('number', $this->phone)->exists();
        }

        /**
         * Get the full name.
         *
         * @param mixed|null $default The default value to return if the full name is empty.
         * @return string The full name.
         */
        public function getFullName(mixed $default = null)
        {

            $full = trim($this->getValueByTag('FIRST_NAME') . ' ' . $this->getValueByTag('LAST_NAME'));

            return empty($full) ? $default : $full;
        }

        /**
         * Retrieve the tags associated with this object.
         *
         * @return array The tags associated with this object.
         */
        public function getTags(): array
        {
            // Notice: json_decode() returns null if input is null or empty
            return json_decode($this->tags, true) ?: [];
        }

        /**
         * Retrieves the options for the tags.
         *
         * @return array The options for the tags.
         */
        public function getTagOptions(): array
        {
            $arr = [];
            foreach ($this->getTags() as $tag) {
                $arr[] = ['text' => $tag, 'value' => $tag];
            }

            return $arr;
        }

        /**
         * Adds tags to the existing array of tags.
         *
         * @param array $arr The array of tags to be added.
         * @return void
         */
        public function addTags(array $arr)
        {
            $tags = $this->getTags();

            $nTags = array_values(array_unique(array_merge($tags, $arr)));

            $this->tags = json_encode($nTags);
            $this->save();
        }

        /**
         * Updates the tags of the object.
         *
         * @param array $newTags The new tags to be updated.
         * @param bool  $merge Whether to merge the new tags with the existing tags.
         * @return void
         */
        public function updateTags(array $newTags, bool $merge = false)
        {
            // remove trailing space
            array_walk($newTags, function (&$val) {
                $val = trim($val);
            });

            // remove empty tag
            $newTags = array_filter($newTags, function ($val) {
                return ! empty($val);
            });

            if ($merge) {
                $currentTags = $this->getTags();
                $newTags     = array_values(array_unique(array_merge($currentTags, $newTags)));
            }

            $this->tags = json_encode($newTags, JSON_UNESCAPED_UNICODE);
            $this->save();
        }

        /**
         * Removes a specific tag from the list of tags.
         *
         * @param string $tag The tag to be removed.
         * @return void
         */
        public function removeTag(string $tag)
        {
            $tags = $this->getTags();

            if (($key = array_search($tag, $tags)) !== false) {
                unset($tags[$key]);
            }

            $this->tags = json_encode($tags);
            $this->save();
        }

        /**
         * Reformat date fields.
         */
        public function reformatDateFields()
        {
            $this->contactGroup->reformatDateFields($this->id);
        }

        public function updateFields($params)
        {
            foreach ($this->contactGroup->getFields as $field) {
                if ( ! isset($params[$field->tag])) {
                    $params[$field->tag] = null;
                }
            }


            foreach ($params as $tag => $value) {
                $field = $this->contactGroup->getFieldByTag(str_replace('[]', '', $tag));

                if ($field && $value !== null) {
                    $fv = ContactsCustomField::where('contact_id', '=', $this->id)->where('field_id', '=', $field->id)->first();
                    if ( ! $fv) {
                        $fv             = new ContactsCustomField();
                        $fv->contact_id = $this->id;
                        $fv->field_id   = $field->id;
                    }
                    if (is_array($value)) {
                        $fv->value = implode(',', $value);
                    } else {
                        $fv->value = $value;
                    }

                    $fv->save();

                    // update email attribute of subscriber
                    if ($field->tag == 'PHONE') {
                        $this->phone = str_replace(['+', '-', '(', ')', ' '], '', $fv->value);
                        $this->save();
                    }
                }
            }
        }


        public function getRules()
        {

            $rules          = $this->contactGroup->getFieldRules();
            $item_id        = isset($this->id) ? $this->id : 'NULL';
            $rules['PHONE'] = $rules['PHONE'] . '|unique:contacts,phone,' . $item_id . ',id,group_id,' . $this->contactGroup->id;

            return $rules;
        }

        public function contactGroupFields()
        {
            return $this->hasMany(ContactGroupFields::class, 'contact_group_id', 'group_id')->select('id', 'contact_group_id', 'tag');
        }

        public function chatBoxes()
        {
            return $this->hasMany(ChatBox::class, 'to', 'phone');
        }


    }
