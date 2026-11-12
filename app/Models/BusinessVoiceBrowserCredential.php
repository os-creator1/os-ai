<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Telnyx telephony credential provisioned for one user in one Business (browser calling). Holds only identifiers:
 * the credential's SIP username (so the server can dial the browser leg) and its Telnyx id (to mint login tokens).
 * The credential's SIP password is never stored and no token is persisted.
 */
class BusinessVoiceBrowserCredential extends Model
{
    protected $table = 'business_voice_browser_credentials';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];
}
