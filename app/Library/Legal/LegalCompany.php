<?php

namespace App\Library\Legal;

use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Models\LegalCompanyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one authoritative source of the platform operator's legal identity (the registered
 * entity behind the MotionGrove brand). Every legal page and disclosure reads it here.
 *
 * Storage is append-only: a save inserts a new revision and never edits an old row, so the
 * identity that applied at any earlier time is still on record. The current identity is the
 * highest revision. It is the operator's own and is deliberately unrelated to any tenant's
 * business, location, Agency or user address.
 */
class LegalCompany
{
    public const FIELDS = ['brand_name', 'legal_entity', 'company_number', 'registered_address', 'country', 'legal_email'];

    public const MAX = [
        'brand_name' => 120, 'legal_entity' => 190, 'company_number' => 60,
        'registered_address' => 500, 'country' => 120, 'legal_email' => 190,
    ];

    public function __construct(
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    /** The current revision's row, or null on an instance that has none yet. */
    public function currentProfile(): ?LegalCompanyProfile
    {
        return LegalCompanyProfile::query()->orderByDesc('revision')->first();
    }

    /** @return array{revision: int, id: ?int, brand_name: string, legal_entity: string, company_number: string, registered_address: string, country: string, legal_email: string} */
    public function current(): array
    {
        return $this->toArray($this->currentProfile());
    }

    /** @return array<string, mixed> */
    public function toArray(?LegalCompanyProfile $profile): array
    {
        $values = $profile
            ? $profile->only(self::FIELDS)
            : array_intersect_key((array) config('legal.company_defaults'), array_flip(self::FIELDS));

        return ['revision' => $profile ? (int) $profile->revision : 0, 'id' => $profile?->id] + $values;
    }

    /**
     * Save a new revision. $baseRevision is the revision the editor's form was loaded from: if
     * someone else saved in between, nothing is written, so stale and fresh values never mix.
     *
     * @param  array<string, mixed>  $input  Already validated by UpdateLegalCompanyRequest.
     * @return array{changed: bool, revision: int}
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws ValidationException
     */
    public function update(int $actorUserId, array $input, int $baseRevision): array
    {
        $this->authority->assertAdministrator($actorUserId);

        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }

        return DB::transaction(function () use ($actorUserId, $values, $baseRevision) {
            // Serialise writers on the current row; an empty table is guarded by the unique revision index.
            $latest = LegalCompanyProfile::query()->orderByDesc('revision')->lockForUpdate()->first();
            $latestRevision = $latest ? (int) $latest->revision : 0;

            if ($latestRevision !== $baseRevision) {
                throw ValidationException::withMessages([
                    'registered_address' => 'These details were changed by someone else while you were editing. Reload the page and try again.',
                ]);
            }

            if ($latest && $latest->only(self::FIELDS) === $values) {
                return ['changed' => false, 'revision' => $latestRevision];
            }

            $new = LegalCompanyProfile::query()->create($values + [
                'revision' => $latestRevision + 1,
                'updated_by_user_id' => $actorUserId,
            ]);

            $this->audit->record(
                $actorUserId,
                'legal_company.updated',
                'legal_company',
                (string) $new->revision,
                'Legal company information updated (revision '.$new->revision.').',
                null,
                ['changed_fields' => array_keys(array_filter(
                    $values,
                    fn ($v, $k) => ! $latest || $latest->{$k} !== $v,
                    ARRAY_FILTER_USE_BOTH
                ))],
            );

            return ['changed' => true, 'revision' => (int) $new->revision];
        });
    }
}
