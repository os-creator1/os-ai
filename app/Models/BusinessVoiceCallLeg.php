<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One ringing leg of an inbound call: a user's browser, or the forwarding phone. */
class BusinessVoiceCallLeg extends Model
{
    public const KIND_BROWSER = 'browser';
    public const KIND_PHONE = 'phone';

    protected $table = 'business_voice_call_legs';

    protected $guarded = [];
}
