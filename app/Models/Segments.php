<?php

namespace App\Models;

use App\Library\Tool;
use App\Library\Traits\HasUid;
use Carbon\Carbon;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;

class Segments extends Model
{
    use HasUid;

    /**
     * @var string[]
     */
    protected $fillable = [
        'name', 'matching',
    ];

    /**
     * Retrieves the ContactGroups model associated with this instance.
     *
     * @return BelongsTo The ContactGroups model associated with this instance.
     */
    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo('App\Models\ContactGroups');
    }

    /**
     * A description of the segmentConditions function.
     */
    public function segmentConditions(): HasMany
    {
        return $this->hasMany('App\Models\SegmentConditions');
    }

    /**
     * Returns the type options for the function.
     *
     * @return array An array of type options.
     */
    public static function getTypeOptions()
    {
        return [
            ['text' => __('locale.labels.all'), 'value' => 'all'],
            ['text' => __('locale.labels.any'), 'value' => 'any'],
        ];
    }

    /**
     * Get the list of operators.
     *
     * @return array The list of operators.
     */
    public static function operators()
    {
        return [
            ['text' => __('locale.labels.equal'), 'value' => 'equal'],
            ['text' => __('locale.labels.not_equal'), 'value' => 'not_equal'],
            ['text' => __('locale.labels.contains'), 'value' => 'contains'],
            ['text' => __('locale.labels.not_contains'), 'value' => 'not_contains'],
            ['text' => __('locale.labels.starts'), 'value' => 'starts'],
            ['text' => __('locale.labels.ends'), 'value' => 'ends'],
            ['text' => __('locale.labels.not_starts'), 'value' => 'not_starts'],
            ['text' => __('locale.labels.not_ends'), 'value' => 'not_ends'],
            ['text' => __('locale.labels.greater'), 'value' => 'greater'],
            ['text' => __('locale.labels.less'), 'value' => 'less'],
            ['text' => __('locale.labels.blank'), 'value' => 'blank'],
            ['text' => __('locale.labels.not_blank'), 'value' => 'not_blank'],
        ];
    }

    /**
     * Returns an array of date operators.
     *
     * @return array
     */
    public static function dateOperators()
    {
        return [
            ['text' => __('locale.labels.later'), 'value' => 'greater'],
            ['text' => __('locale.labels.earlier'), 'value' => 'less'],
            ['text' => __('locale.labels.is'), 'value' => 'equal'],
            ['text' => __('locale.labels.is_not'), 'value' => 'not_equal'],
            ['text' => __('locale.labels.blank'), 'value' => 'blank'],
            ['text' => __('locale.labels.not_blank'), 'value' => 'not_blank'],
        ];
    }

    /**
     * Generates an array of verification operators.
     *
     * @return array An array of verification operators.
     */
    public static function verificationOperators()
    {
        return [
            ['text' => __('locale.labels.equal'), 'value' => 'verification_equal'],
            ['text' => __('locale.labels.not_equal'), 'value' => 'verification_not_equal'],
        ];
    }

    /**
     * Generates an array of created date operators.
     *
     * @return array The array of created date operators.
     */
    public static function createdDateOperators()
    {
        return [
            ['text' => __('locale.labels.greater_than'), 'value' => 'created_date_greater'],
            ['text' => __('locale.labels.less_than'), 'value' => 'created_date_less'],
            ['text' => __('locale.labels.last_x_days'), 'value' => 'created_date_last_x_days'],
        ];
    }

    /**
     * Return an array of tag operators.
     *
     * @return array An array of tag operators.
     */
    public static function tagOperators()
    {
        return [
            ['text' => __('locale.labels.contains'), 'value' => 'tag_contains'],
            ['text' => __('locale.labels.not_contains'), 'value' => 'tag_not_contains'],
        ];
    }

    /**
     * Retrieves the conditions for the subscribers.
     *
     * @return array|null Returns an array containing the joins and conditions for the subscribers, or null if no conditions exist.
     *
     * @throws Exception Throws an exception if an unknown segment condition type (operator) is encountered.
     */
    public function getSubscribersConditions()
    {
        if (! $this->segmentConditions()->exists()) {
            return null;
        }

        $conditions = [];
        $joins = [];

        foreach ($this->segmentConditions as $condition) {
            $number = uniqid();

            $keyword = $condition->value;
            $keyword = str_replace('[EMPTY]', '', $keyword);
            $keyword = str_replace('[DATETIME]', date('Y-m-d H:i:s'), $keyword);
            $keyword = str_replace('[DATE]', date('Y-m-d'), $keyword);

            $keyword = trim(strtolower($keyword));

            // If conditions with fields
            if (isset($condition->field_id)) {
                $type = $condition->field->type;
                switch ($condition->operator) {
                    case 'equal':
                        if ($type == 'number') {
                            $cond = sprintf('CAST(sf%s.value AS SIGNED) = CAST(%s AS SIGNED)', $number, Tool::db_quote($keyword));
                        } else {
                            $cond = 'LOWER(sf'.$number.'.value) = '.Tool::db_quote($keyword);
                        }
                        break;
                    case 'not_equal':
                        if ($type == 'number') {
                            $cond = sprintf('CAST(sf%s.value AS SIGNED) != CAST(%s AS SIGNED)', $number, Tool::db_quote($keyword));
                        } else {
                            $cond = 'LOWER(sf'.$number.'.value) != '.Tool::db_quote($keyword);
                        }
                        break;
                    case 'contains':
                        $cond = 'LOWER(sf'.$number.'.value) LIKE '.Tool::db_quote('%'.$keyword.'%');
                        break;
                    case 'not_contains':
                        $cond = '(LOWER(sf'.$number.'.value) NOT LIKE '.Tool::db_quote('%'.$keyword.'%').' OR sf'.$number.'.value IS NULL)';
                        break;
                    case 'starts':
                        $cond = 'LOWER(sf'.$number.'.value) LIKE '.Tool::db_quote($keyword.'%');
                        break;
                    case 'ends':
                        $cond = 'LOWER(sf'.$number.'.value) LIKE '.Tool::db_quote('%'.$keyword);
                        break;
                    case 'greater':
                        if ($type == 'number') {
                            $cond = sprintf('CAST(sf%s.value AS SIGNED) > CAST(%s AS SIGNED)', $number, Tool::db_quote($keyword));
                        } else {
                            $cond = 'sf'.$number.'.value > '.Tool::db_quote($keyword);
                        }
                        break;
                    case 'less':
                        if ($type == 'number') {
                            $cond = sprintf('CAST(sf%s.value AS SIGNED) < CAST(%s AS SIGNED)', $number, Tool::db_quote($keyword));
                        } else {
                            $cond = 'sf'.$number.'.value < '.Tool::db_quote($keyword);
                        }

                        break;
                    case 'not_starts':
                        $cond = 'sf'.$number.'.value NOT LIKE '.Tool::db_quote($keyword.'%');
                        break;
                    case 'not_ends':
                        $cond = 'LOWER(sf'.$number.'.value) NOT LIKE '.Tool::db_quote('%'.$keyword);
                        break;
                    case 'not_blank':
                        $cond = '(LOWER(sf'.$number.".value) != '' AND LOWER(sf".$number.'.value) IS NOT NULL)';
                        break;
                    case 'blank':
                        $cond = '(LOWER(sf'.$number.".value) = '' OR LOWER(sf".$number.'.value) IS NULL)';
                        break;
                    default:
                        throw new Exception('Unknown segment condition type (operator): '.$condition->operator);
                }

                // add to joins array
                $joins[] = [
                    'table' => DB::raw(DB::getTablePrefix().'subscriber_fields as sf'.$number),
                    'ons' => [
                        [DB::raw('sf'.$number.'.subscriber_id'), DB::raw(DB::getTablePrefix().'contacts.id')],
                        [DB::raw('sf'.$number.'.field_id'), DB::raw($condition->field_id)],
                    ],
                ];

                // add condition
                $conditions[] = $cond;
            } else {
                switch ($condition->operator) {

                    case 'tag_contains':
                        // add condition
                        $conditions[] = '('.DB::getTablePrefix().'contacts.tags LIKE '.Tool::db_quote('%"'.$keyword.'"%').')';
                        break;

                    case 'tag_not_contains':
                        // add condition
                        $conditions[] = '('.DB::getTablePrefix().'contacts.tags NOT LIKE '.Tool::db_quote('%"'.$keyword.'"%').')';
                        break;

                    case 'created_date_greater':
                        $ts = Carbon::createFromFormat('Y-m-d H:i:s', $condition->value)->timestamp;
                        $conditions[] = '(UNIX_TIMESTAMP('.DB::getTablePrefix().'contacts.created_at) > '.$ts.')';
                        break;

                    case 'created_date_less':
                        $ts = Carbon::createFromFormat('Y-m-d H:i:s', $condition->value)->timestamp;
                        $conditions[] = '(UNIX_TIMESTAMP('.DB::getTablePrefix().'contacts.created_at) < '.$ts.')';
                        break;

                    case 'created_date_last_x_days':
                        $ts = Carbon::now()->subDays($condition->value)->timestamp;
                        $conditions[] = '(UNIX_TIMESTAMP('.DB::getTablePrefix().'contacts.created_at) >= '.$ts.')';
                        break;
                    default:
                        throw new Exception('Unknown segment condition type (operator): '.$condition->operator);
                }
            }
        }

        //return $conditions;
        if ($this->matching == 'any') {
            $conditions = implode(' OR ', $conditions);
        } else {
            $conditions = implode(' AND ', $conditions);
        }

        return [
            'joins' => $joins,
            'conditions' => $conditions,
        ];
    }

    /**
     * Retrieves the subscribers for the contact group.
     *
     * @return Builder The query builder for retrieving subscribers.
     *
     * @throws Exception
     */
    public function subscribers()
    {
        $query = $this->contactGroup->subscribers();
        $query->select('contacts.*');

        $conditions = $this->getSubscribersConditions();

        // Apply the filter
        if (! empty($conditions['joins'])) {
            foreach ($conditions['joins'] as $joining) {
                $query = $query->leftJoin($joining['table'], function ($join) use ($joining) {
                    $join->on($joining['ons'][0][0], '=', $joining['ons'][0][1]);
                    if (isset($joining['ons'][1])) {
                        $join->on($joining['ons'][1][0], '=', $joining['ons'][1][1]);
                    }
                });
            }
        }

        if (! empty($conditions['conditions'])) {
            $query = $query->whereRaw('('.$conditions['conditions'].')');
        }

        return $query;
    }

    /**
     * Checks if a subscriber is included.
     *
     * @param  Contacts  $contact The subscriber to check.
     * @return bool Returns true if the subscriber is included, false otherwise.
     *
     * @throws Exception
     */
    public function isSubscriberIncluded(Contacts $contact)
    {
        return $this->subscribers()
            ->where('uid', $contact->uid)
            ->exists();
    }

    /**
     * Updates the conditions of the segment.
     *
     * @param  array  $conditions The conditions to update.
     */
    public function updateConditions(array $conditions)
    {
        if ($this->id) {
            $this->segmentConditions()->delete();
        }
        foreach ($conditions as $param) {
            $condition = new SegmentConditions();
            $condition->fill($param);

            if (str_starts_with($condition->operator, 'created_date') && $condition->operator !== 'created_date_last_x_days') {
                $zone = $this->contactGroup->customer->getTimezone();
                $date = Carbon::createFromFormat('Y-m-d, H:i', $condition->value, $zone);
                $condition->value = $date->toDateTimeString();
            }

            $condition->segment_id = $this->id;
            $field = ContactGroupFields::findByUid($param['field_id']);
            if ($field) {
                $condition->field_id = $field->id;
            } else {
                $condition->field_id = null;
            }

            $condition->save();
        }
    }
}
