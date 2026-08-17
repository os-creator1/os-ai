<?php

namespace App\Models;

use App\Enums\Usage\PaymentProvider;
use App\Enums\Usage\ProviderCustomerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProviderCustomer extends Model
{
    protected $table = 'payment_provider_customers';

    protected $fillable = [
        'provider',
        'business_id',
        'workspace_id',
        'provider_customer_id',
        'status',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'status' => ProviderCustomerStatus::class,
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
