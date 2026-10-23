<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Append-only evidence of an affirmative acceptance of an exact legal document version.
 * See the create_legal_acceptances_table migration.
 */
class LegalAcceptance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->uid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('Legal acceptance evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Legal acceptance evidence is append-only.'));
    }
}
