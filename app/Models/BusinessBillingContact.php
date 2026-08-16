<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessBillingContact extends Model
{
    protected $table = 'business_billing_contacts';

    protected $fillable = [
        'business_id',
        'contact_user_id',
        'contact_name',
        'contact_email',
        'notification_opt_in',
        'updated_by_user_id',
    ];

    protected $casts = [
        'notification_opt_in' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function contactUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contact_user_id');
    }
}
