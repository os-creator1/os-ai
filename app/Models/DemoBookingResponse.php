<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A prospect's answers to the Book a Demo form, frozen when the appointment was booked. */
class DemoBookingResponse extends Model
{
    protected $fillable = ['appointment_id', 'contact_id', 'form_version_id', 'answers'];

    protected $casts = ['answers' => 'array'];
}
