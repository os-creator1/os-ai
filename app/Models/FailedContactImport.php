<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static where(string $string, mixed $id)
     * @method static create(array $array)
     */
    class FailedContactImport extends Model
    {

        protected $fillable = [
            'user_id',
            'contact_group_id',
            'record_data',
            'reason',
            'job_id',
        ];

        protected $casts = [
            'record_data' => 'array',
        ];

        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        public function contactGroup(): BelongsTo
        {
            return $this->belongsTo(ContactGroups::class);
        }

    }
