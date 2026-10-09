<?php

namespace App\Models;

use App\Library\Acquisition\Economics\EconomicsCalculators;
use App\Library\Acquisition\Economics\EconomicsProfile;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Acquisition Purpose V1 — what a Business is trying to win through its
 * marketing (a student, a hired teacher, a class booking...), and the single
 * join between an ad campaign and everything downstream of it:
 *
 *   campaign -> destination -> Form -> CRM pipeline -> outcome -> economics
 *
 * It REFERENCES canonical records (pipeline, form, website page) and never
 * copies them. The Business's answers live in `economics`; the question
 * schema/labels/guidance are the copy taken from the installed Blueprint
 * component, so editing the niche later never rewrites a Business's setup.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property string $purpose_key
 * @property string $name
 * @property string $outcome_type
 * @property string $calculator_key
 * @property bool $is_active
 * @property ?int $crm_pipeline_id
 * @property ?int $form_id
 * @property string $destination_type
 * @property ?int $destination_page_id
 * @property ?string $destination_url
 */
class AcquisitionPurpose extends Model
{
    use HasUid;

    public const DESTINATION_NONE = 'none';

    public const DESTINATION_HOSTED_PAGE = 'hosted_page';

    public const DESTINATION_EXTERNAL_URL = 'external_url';

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'labels' => 'array',
        'question_schema' => 'array',
        'economics' => 'array',
        'guidance' => 'array',
        'website_intent' => 'array',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(CrmPipeline::class, 'crm_pipeline_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function googleCampaigns(): HasMany
    {
        return $this->hasMany(GoogleAdsCampaign::class, 'acquisition_purpose_id');
    }

    public function metaCampaigns(): HasMany
    {
        return $this->hasMany(MetaAdsCampaign::class, 'acquisition_purpose_id');
    }

    /** @return array<string, mixed> the owner's answers, keyed by question key */
    public function answers(): array
    {
        $answers = $this->economics['answers'] ?? [];

        return is_array($answers) ? $answers : [];
    }

    /** @return list<string> questions the owner answered "I don't know yet" */
    public function unknownKeys(): array
    {
        $unknown = $this->economics['unknown'] ?? [];

        return is_array($unknown) ? array_values(array_filter($unknown, 'is_string')) : [];
    }

    public function economicsProfile(): EconomicsProfile
    {
        $calculators = app(EconomicsCalculators::class);
        $calculator = $calculators->has($this->calculator_key) ? $calculators->get($this->calculator_key) : $calculators->get('simple_outcome');

        return $calculator->profile($this->answers(), $this->unknownKeys());
    }

    /**
     * Owner-facing nouns, with safe generic defaults so a purpose created by
     * hand reads sensibly. Everything here is wording; nothing is arithmetic.
     */
    public function label(string $key): string
    {
        $defaults = [
            'person' => 'customer',
            'lead' => 'qualified inquiry',
            'leads' => 'qualified inquiries',
            'outcome' => 'customer',
            'outcomes' => 'customers',
            'cost_per_lead' => 'Cost / qualified inquiry',
            'cost_per_outcome' => 'Cost / customer',
            'pipeline_cta' => 'Open ' . $this->name . ' pipeline',
            'review_leads_cta' => 'Open ' . $this->name . ' pipeline',
        ];

        $value = $this->labels[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : ($defaults[$key] ?? $key);
    }

    public function hasDestination(): bool
    {
        return match ($this->destination_type) {
            self::DESTINATION_HOSTED_PAGE => $this->destination_page_id !== null,
            self::DESTINATION_EXTERNAL_URL => filled($this->destination_url),
            default => false,
        };
    }
}
