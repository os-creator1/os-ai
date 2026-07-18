<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * @property mixed first_name
 * @property mixed last_name
 * @property mixed name
 * @method static where(string $string, string $uid)
 */
class Admin extends Model
{
    use Notifiable;
    use HasUid;

    protected $table = 'admins';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'creator_id',
        'admin_role',
    ];


    /**
     * @return string
     */
    public function displayName(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
