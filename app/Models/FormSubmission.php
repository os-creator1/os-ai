<?php

namespace App\Models;

use App\Enums\Forms\FormContactResolution;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Forms V1 — one logical submission, an immutable historical fact.
 *
 * Written ONLY by `FormSubmissionService`, which inserts the row as its
 * idempotency claim and then links the Contact/Opportunity inside the same
 * transaction through a query-builder update (see `FormSubmissionService::
 * link()`). Any Eloquent update or delete is refused here, so nothing else —
 * a controller, a seeder, a later "tidy up" — can rewrite history.
 *
 * `values` is keyed by field key; the submission's `FormVersion` says what each
 * key meant. `occurrence_key` is the stable identity a later Automations lane
 * dedupes on.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $business_location_id
 * @property int $form_id
 * @property int $form_version_id
 * @property int $form_deployment_id
 * @property string $source
 * @property string $operation_nonce
 * @property string $payload_hash
 * @property array<string, mixed> $values
 * @property ?int $contact_id
 * @property FormContactResolution $contact_resolution
 * @property ?int $crm_opportunity_id
 * @property string $occurrence_key
 */
class FormSubmission extends Model
{
    use HasUid;

    /** Never mass-assigned from a request; the service sets every column explicitly. */
    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'contact_resolution' => FormContactResolution::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A form submission is immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('A form submission is never deleted.');
        });
    }

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(FormDeployment::class, 'form_deployment_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(CrmOpportunity::class, 'crm_opportunity_id');
    }
}
