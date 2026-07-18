<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    /**
     * @method static where(string $string, string $uid)
     * @method static select(string $string)
     * @method static offset($start)
     * @method static whereLike(string[] $array, $search)
     * @method static count()
     * @method static cursor()
     * @method static insert(array $insert_data)
     * @method static create(array $blacklist)
     * @property mixed name
     * @property mixed user_id
     */
    class Blacklists extends Model
    {

        use HasUid, HasFactory;

        /**
         * The attributes that are mass assignable.
         *
         * @var array
         */

        protected $fillable = [
            'uid',
            'user_id',
            'number',
            'reason',
        ];

        /**
         * get user
         *
         * @return BelongsTo
         *
         */
        public function user(): BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        /**
         * get all plans
         *
         * @return Blacklists
         */

        public static function getAll()
        {
            return self::select('*');
        }

    }
