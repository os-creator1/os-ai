<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, string $uid)
 * @method static create(array $array)
 * @method static insert(array $template_data)
 * @method whereIn(string $string, array $ids)
 * @method static offset(mixed $start)
 * @method static count()
 * @method static whereLike(string[] $array, mixed $search)
 */
class Templates extends Model
{
    use HasUid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
            'name',
            'user_id',
            'message',
            'status',
            'sender_id',
            'dlt_template_id',
            'dlt_category',
            'approved',
    ];


    /**
     *
     * @var string[]
     */
    protected $casts = [
            'status' => 'boolean',
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
     * get sender id
     *
     * @return BelongsTo
     *
     */
    public function senderid(): BelongsTo
    {
        return $this->belongsTo(Senderid::class);
    }
}
