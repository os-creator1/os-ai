<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Attributes one public registration (and its checkout) to exactly one Agency. */
class AgencySiteSignup extends Model
{
    use HasUid;

    public const STARTED = 'started';

    public const PROVISIONED = 'provisioned';

    public const CHECKOUT_STARTED = 'checkout_started';

    public const ACTIVE = 'active';

    protected $fillable = [
        'agency_workspace_id', 'agency_site_id', 'agency_saas_plan_id', 'email', 'business_name',
        'user_id', 'client_workspace_id', 'status', 'consent_ip', 'terms_accepted_at',
    ];

    protected $casts = ['terms_accepted_at' => 'datetime'];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }
}
