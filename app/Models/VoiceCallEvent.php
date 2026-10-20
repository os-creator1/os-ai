<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceCallEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['voice_call_id', 'provider_event_id', 'event_type', 'occurred_at', 'applied', 'created_at'];

    protected $casts = ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
}
