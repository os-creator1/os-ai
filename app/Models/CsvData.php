<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(array $array)
 * @method static find(mixed $csv_data_file_id)
 */
class CsvData extends Model
{
    const TYPE_CONTACT = 'contact';
    const TYPE_CAMPAIGN = 'campaign';

    protected $fillable = [
            'user_id',
            'csv_filename',
            'csv_header',
            'csv_data',
            'ref_id',
            'ref_type',
    ];

    /**
     * A CSV upload is a transient wizard step belonging to the user who made it.
     * It is never shared with team members, sub-accounts or other businesses, so
     * the only authorised consumer is that same authenticated user.
     */
    public static function findOwnedBy(mixed $id, mixed $userId, ?string $refType = null): ?self
    {
        if (! is_scalar($id) || ! is_scalar($userId) || (string) $id === '' || (int) $userId <= 0) {
            return null;
        }

        $query = static::query()->whereKey($id)->where('user_id', (int) $userId);

        if ($refType !== null) {
            $query->where('ref_type', $refType);
        }

        return $query->first();
    }
}
