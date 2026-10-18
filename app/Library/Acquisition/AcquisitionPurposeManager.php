<?php

namespace App\Library\Acquisition;

use App\Library\Acquisition\Economics\EconomicsCalculators;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\GoogleAdsCampaign;
use App\Models\MetaAdsCampaign;
use App\Models\WebsitePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Acquisition Purpose V1 — the ONE writer of acquisition_purposes and of the
 * campaign -> purpose assignment.
 *
 * Every method is scoped by Business: a purpose, campaign, pipeline, form or
 * page named by a request is re-resolved INSIDE the Business, so a guessed
 * foreign id resolves to nothing. Assignment is explicit and the only
 * authority — nothing here (or anywhere) infers a purpose from a campaign
 * name.
 */
final class AcquisitionPurposeManager
{
    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_META = 'meta';

    public function __construct(private readonly EconomicsCalculators $calculators)
    {
    }

    /** @return Collection<int, AcquisitionPurpose> active purposes first, in their display order */
    public function forBusiness(Business $business, bool $activeOnly = true): Collection
    {
        return AcquisitionPurpose::query()
            ->where('business_id', $business->id)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(Business $business, string $uid): ?AcquisitionPurpose
    {
        return AcquisitionPurpose::query()->where('business_id', $business->id)->where('uid', $uid)->first();
    }

    /**
     * A purpose the owner creates by hand (a Business with no niche Blueprint,
     * or one more goal). It uses the simple calculator: only the owner's own
     * limits, never a revenue formula.
     */
    public function createManual(Business $business, string $name, ?int $pipelineId): AcquisitionPurpose
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new AcquisitionPurposeException('Give the goal a name of up to 120 characters.');
        }

        $pipeline = $pipelineId === null ? null : $this->pipelineFor($business, $pipelineId);
        $key = $this->uniqueKey($business, Str::slug($name, '_') ?: 'goal');

        return AcquisitionPurpose::query()->create([
            'business_id' => $business->id,
            'purpose_key' => $key,
            'name' => $name,
            'outcome_type' => 'customer',
            'calculator_key' => 'simple_outcome',
            'is_active' => true,
            'sort_order' => 100 + (int) AcquisitionPurpose::query()->where('business_id', $business->id)->count(),
            'crm_pipeline_id' => $pipeline?->id,
            'destination_type' => AcquisitionPurpose::DESTINATION_NONE,
        ]);
    }

    /**
     * Saves the owner's economics answers. Input shape:
     *   answers[key]  the answered value (blank = unanswered)
     *   unknown[key]  "1" when the owner chose "I don't know yet" (wins over a value)
     *
     * Only keys the purpose's calculator declares are stored; a hard maximum
     * below its target is refused. Unknown stays unknown — it is never
     * replaced by 0 or by a suggestion.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveEconomics(Business $business, AcquisitionPurpose $purpose, array $input): AcquisitionPurpose
    {
        $this->assertOwned($business, $purpose);

        $calculator = $this->calculators->has($purpose->calculator_key)
            ? $this->calculators->get($purpose->calculator_key)
            : $this->calculators->get('simple_outcome');

        $rawAnswers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $rawUnknown = is_array($input['unknown'] ?? null) ? $input['unknown'] : [];
        $answers = [];
        $unknown = [];

        foreach ($calculator->inputs() as $key => $definition) {
            if (! empty($rawUnknown[$key])) {
                $unknown[] = $key;

                continue;
            }

            $value = $rawAnswers[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                continue;
            }

            $answers[$key] = $this->clean($definition, $key, $value);
        }

        $this->assertCeilingsHold($answers);

        $purpose->forceFill(['economics' => ['answers' => $answers, 'unknown' => $unknown]])->save();

        return $purpose->refresh();
    }

    /**
     * Explicit campaign assignment (or null to unassign). The campaign is
     * resolved by uid INSIDE the Business; the purpose must be the same
     * Business's own and active.
     */
    public function assignCampaign(Business $business, string $provider, string $campaignUid, ?string $purposeUid): void
    {
        $campaign = match ($provider) {
            self::PROVIDER_GOOGLE => GoogleAdsCampaign::query()->where('business_id', $business->id)->where('uid', $campaignUid)->first(),
            self::PROVIDER_META => MetaAdsCampaign::query()->where('business_id', $business->id)->where('uid', $campaignUid)->first(),
            default => null,
        };

        if ($campaign === null) {
            throw new AcquisitionPurposeException('That campaign could not be found.');
        }

        $purposeId = null;

        if ($purposeUid !== null && $purposeUid !== '') {
            $purpose = $this->find($business, $purposeUid);

            if ($purpose === null || ! $purpose->is_active) {
                throw new AcquisitionPurposeException('That goal could not be found.');
            }

            $purposeId = (int) $purpose->id;
        }

        $campaign->forceFill(['acquisition_purpose_id' => $purposeId])->save();
    }

    /**
     * Points the purpose at a pipeline, form and destination of THIS Business.
     * Nothing is created or copied; null clears a reference.
     *
     * @param  array{pipeline_id?: ?int, form_id?: ?int, destination_type?: ?string, destination_page_id?: ?int, destination_url?: ?string}  $links
     */
    public function saveLinks(Business $business, AcquisitionPurpose $purpose, array $links): AcquisitionPurpose
    {
        $this->assertOwned($business, $purpose);
        $changes = [];

        if (array_key_exists('pipeline_id', $links)) {
            $changes['crm_pipeline_id'] = $links['pipeline_id'] === null ? null : $this->pipelineFor($business, (int) $links['pipeline_id'])->id;
        }

        if (array_key_exists('form_id', $links)) {
            $changes['form_id'] = $links['form_id'] === null ? null : $this->formFor($business, (int) $links['form_id'])->id;
        }

        if (array_key_exists('destination_type', $links)) {
            $type = (string) ($links['destination_type'] ?? AcquisitionPurpose::DESTINATION_NONE);

            $changes += match ($type) {
                AcquisitionPurpose::DESTINATION_HOSTED_PAGE => [
                    'destination_type' => $type,
                    'destination_page_id' => $this->pageFor($business, (int) ($links['destination_page_id'] ?? 0))->id,
                    'destination_url' => null,
                ],
                AcquisitionPurpose::DESTINATION_EXTERNAL_URL => [
                    'destination_type' => $type,
                    'destination_page_id' => null,
                    'destination_url' => $this->publicUrl((string) ($links['destination_url'] ?? '')),
                ],
                default => ['destination_type' => AcquisitionPurpose::DESTINATION_NONE, 'destination_page_id' => null, 'destination_url' => null],
            };
        }

        $purpose->forceFill($changes)->save();

        return $purpose->refresh();
    }

    public function setActive(Business $business, AcquisitionPurpose $purpose, bool $active): void
    {
        $this->assertOwned($business, $purpose);
        $purpose->forceFill(['is_active' => $active])->save();

        if (! $active) {
            // An inactive goal judges nothing: its campaigns go back to "assign a goal".
            GoogleAdsCampaign::query()->where('business_id', $business->id)->where('acquisition_purpose_id', $purpose->id)->update(['acquisition_purpose_id' => null]);
            MetaAdsCampaign::query()->where('business_id', $business->id)->where('acquisition_purpose_id', $purpose->id)->update(['acquisition_purpose_id' => null]);
        }
    }

    /** The https/http URL the owner typed, normalised; anything else is refused. */
    public function publicUrl(string $raw): string
    {
        $raw = trim($raw);

        if ($raw !== '' && ! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || mb_strlen($raw) > 2048) {
            throw new AcquisitionPurposeException('Enter a full web address that starts with http:// or https://.');
        }

        return $raw;
    }

    private function assertOwned(Business $business, AcquisitionPurpose $purpose): void
    {
        if ((int) $purpose->business_id !== (int) $business->id) {
            throw new AcquisitionPurposeException('That goal could not be found.');
        }
    }

    private function pipelineFor(Business $business, int $id): CrmPipeline
    {
        return CrmPipeline::query()->where('business_id', $business->id)->whereNull('archived_at')->whereKey($id)->first()
            ?? throw new AcquisitionPurposeException('Choose one of this business\'s active pipelines.');
    }

    private function formFor(Business $business, int $id): Form
    {
        return Form::query()->where('business_id', $business->id)->whereKey($id)->first()
            ?? throw new AcquisitionPurposeException('Choose one of this business\'s forms.');
    }

    private function pageFor(Business $business, int $id): WebsitePage
    {
        return WebsitePage::query()
            ->whereKey($id)
            ->whereIn('website_id', fn ($q) => $q->select('id')->from('websites')->where('business_id', $business->id))
            ->first()
            ?? throw new AcquisitionPurposeException('Choose one of this business\'s website pages.');
    }

    private function uniqueKey(Business $business, string $base): string
    {
        $base = mb_substr($base, 0, 56);
        $key = $base;
        $n = 2;

        while (AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', $key)->exists()) {
            $key = $base . '_' . $n++;
        }

        return $key;
    }

    /** @param array{type: string, label: string, options?: list<string>} $definition */
    private function clean(array $definition, string $key, mixed $value): mixed
    {
        $label = $definition['label'];

        switch ($definition['type']) {
            case 'money':
            case 'count':
            case 'percent':
                if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1_000_000_000) {
                    throw new AcquisitionPurposeException("\"{$label}\" must be a number that is zero or more.");
                }

                if ($definition['type'] === 'percent' && (float) $value > 100) {
                    throw new AcquisitionPurposeException("\"{$label}\" must be between 0 and 100.");
                }

                if ($definition['type'] === 'count') {
                    return (int) round((float) $value);
                }

                return round((float) $value, 2);

            case 'choice':
                if (! in_array($value, $definition['options'] ?? [], true)) {
                    throw new AcquisitionPurposeException("Choose one of the listed options for \"{$label}\".");
                }

                return (string) $value;

            default:
                return mb_substr(trim((string) $value), 0, 200);
        }
    }

    /** @param array<string, mixed> $answers */
    private function assertCeilingsHold(array $answers): void
    {
        foreach ([['target_cac', 'hard_cac', 'cost to win one customer'], ['target_qualified_cpl', 'hard_cpl', 'cost per qualified inquiry']] as [$target, $hard, $what]) {
            if (isset($answers[$target], $answers[$hard]) && (float) $answers[$hard] < (float) $answers[$target]) {
                throw new AcquisitionPurposeException("Your hard maximum {$what} cannot be lower than your target.");
            }
        }
    }
}
