<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, $uid)
 * @method static select(string $string)
 * @method static count()
 * @method static offset(mixed $start)
 * @method static whereLike(string[] $array, mixed $search)
 * @method static cursor()
 * @method static create(array $tags)
 * @method static get()
 */
class TemplateTags extends Model
{
    use HasUid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
            'name',
            'tag',
            'type',
            'required',
    ];


    /**
     * default template tags
     *
     * @return string[]
     */
    public function defaultTemplateTags(): array
    {
        return [
                'email',
                'username',
                'company',
                'first_name',
                'last_name',
                'birth_date',
                'anniversary_date',
                'address',
        ];
    }


    /**
     * get all plans
     *
     * @return TemplateTags
     */

    public static function getAll()
    {
        return self::select('*');
    }
}
