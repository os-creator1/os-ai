<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * External Website Audit — the owner's own tracking of one improvement, keyed by the
 * stable rule key (hashed with the Business), so it survives from one crawl to the
 * next. It records what the OWNER says (open, in progress, waiting on a helper,
 * done, won't do); whether the fix actually worked is established separately by
 * the next crawl (`resolved_in_crawl_id`). MotionGrove never changes the external
 * website, so "done" only ever means "the owner says they did it".
 *
 * @property int $id
 * @property int $business_id
 * @property string $item_key
 * @property string $rule_key
 * @property string $status open|in_progress|waiting|done|wont_do
 */
class ExternalSitePlanItem extends Model
{
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const WONT_DO = 'wont_do';

    public const STATUSES = [self::OPEN => 'Not started', self::IN_PROGRESS => 'In progress', self::WAITING => 'Waiting on my developer or helper', self::DONE => 'I made this change', self::WONT_DO => 'I will not do this'];

    protected $guarded = ['id'];

    protected $casts = ['status_changed_at' => 'datetime'];

    public static function keyFor(string $ruleKey): string
    {
        return sha1('plan-item|'.$ruleKey);
    }
}
