<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A reserved channel uid awaiting connection (see the migration). Not a channel: it can
 * neither send nor receive.
 */
class AgencyChannelSetupReservation extends Model
{
    protected $fillable = ['uid', 'workspace_id', 'provider', 'created_by_user_id'];

    /** The Workspace's open reservation for a provider, created on first use and reused thereafter. */
    public static function openFor(Workspace $workspace, string $provider, ?int $userId): self
    {
        $existing = static::where('workspace_id', $workspace->id)->where('provider', $provider)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return static::create([
                'uid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'provider' => $provider,
                'created_by_user_id' => $userId,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent request reserved it first; use that one.
            return static::where('workspace_id', $workspace->id)->where('provider', $provider)->firstOrFail();
        }
    }
}
