<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An internal note, follow-up task or manual-review flag on an account. Never shown to the customer. */
class PlatformAccountNote extends Model
{
    public const KIND_NOTE = 'note';
    public const KIND_TASK = 'task';
    public const KIND_REVIEW_FLAG = 'review_flag';

    protected $table = 'platform_account_notes';

    protected $fillable = [
        'uid', 'target_type', 'target_id', 'workspace_id', 'business_id', 'kind', 'body', 'state',
        'created_by_user_id', 'source_run_id',
    ];
}
