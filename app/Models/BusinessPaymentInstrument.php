<?php

namespace App\Models;

use App\Enums\Usage\PaymentInstrumentStatus;
use App\Enums\Usage\PaymentInstrumentType;
use App\Enums\Usage\PaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPaymentInstrument extends Model
{
    protected $table = 'business_payment_instruments';

    public $timestamps = false;

    protected $fillable = [
        'provider_customer_id',
        'provider',
        'provider_payment_method_id',
        'type',
        'brand',
        'last_four',
        'expiry_month',
        'expiry_year',
        'is_default',
        'status',
        'created_at',
        'detached_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'type' => PaymentInstrumentType::class,
        'status' => PaymentInstrumentStatus::class,
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'detached_at' => 'datetime',
    ];

    public function providerCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class, 'provider_customer_id');
    }
}
