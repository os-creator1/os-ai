<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static where(string $string, string $uid)
 * @method static create(array $array)
 * @method static whereIn(string $string, $contact_groups)
 */
class ContactGroupsOptinKeywords extends Model
{
    use HasUid;

    protected $table = 'contact_groups_optin_keywords';

    protected $fillable = [
            'contact_group',
            'keyword',
    ];

    /**
     * @return BelongsTo
     */
    public function ContactGroups(): BelongsTo
    {
        return $this->belongsTo(ContactGroups::class,'contact_group');
    }
}
