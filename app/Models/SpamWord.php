<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static where(string $string, string $uid)
 * @method static count()
 * @method static offset($start)
 * @method static whereLike(string[] $array, $search)
 * @method static cursor()
 * @method static truncate()
 * @method static create(string[] $word)
 * @method static whereRaw(string $string, array $array)
 * @method static whereIn(string $string, array $array_map)
 * @property mixed name
 */
class SpamWord extends Model
{
    use HasUid;

    /**
     * The attributes for assign table
     *
     * @var string
     */

    protected $table = 'spam_word';


    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $fillable = [
            'word',
    ];
}
