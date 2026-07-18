<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;

    /**
     * @method static count()
     * @method static offset(mixed $start)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static whereIn(string $string, $announcementIds)
     * @method static truncate()
     * @method static where(string $string, mixed $id)
     */
    class Announcements extends Model
    {
        use HasUid;

        protected $fillable = [
            'user_id',
            'title',
            'type',
            'description',
        ];

        /**
         * get user
         */
        public function users(): BelongsToMany
        {
            return $this->belongsToMany(User::class)
                ->withPivot('read_at')
                ->withTimestamps();
        }

    }
