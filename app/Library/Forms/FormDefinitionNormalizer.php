<?php

namespace App\Library\Forms;

use App\Enums\Forms\FormFieldType;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Models\Business;
use App\Models\CrmPipeline;
use Illuminate\Support\Str;

/**
 * Forms V1 — the one place a form definition's content is bounded and
 * normalized. Pure rules over the input plus ONE persistence read (the
 * optional Opportunity pipeline, re-proven against the Business).
 *
 * BOUNDED ON PURPOSE: at most MAX_FIELDS fields, one closed type set, at most
 * one phone field (the Contact identity key) and at most one "contact name"
 * field. This is a lead/questionnaire form, not an application builder.
 *
 * The normalized array is also what the content hash is taken over, so two
 * saves with the same meaning always hash the same — key order, blank rows and
 * whitespace never create a version.
 */
final class FormDefinitionNormalizer
{
    public const MAX_FIELDS = 25;

    public const MAX_OPTIONS = 20;

    public const NAME_MAX = 120;

    public const LABEL_MAX = 120;

    public const OPTION_MAX = 80;

    public const INTRO_MAX = 1000;

    public const SUBMIT_LABEL_MAX = 40;

    public const SUCCESS_MESSAGE_MAX = 300;

    public const DEFAULT_SUBMIT_LABEL = 'Send';

    public const DEFAULT_SUCCESS_MESSAGE = 'Thanks — we got your message and will be in touch.';

    /** Request-input names the public form itself uses; a field may never shadow one. */
    public const RESERVED_KEYS = ['form_hp', 'operation_token', 'location_uid'];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public function name(mixed $name): string
    {
        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new FormRuleException('Give the form a name of up to '.self::NAME_MAX.' characters.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{intro: ?string, submit_label: string, success_message: string, fields: list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool}>, create_opportunity: bool, opportunity_pipeline_id: ?int}
     */
    public function content(Business $business, array $input): array
    {
        $fields = $this->fields($input['fields'] ?? []);

        $createOpportunity = (bool) ($input['create_opportunity'] ?? false);
        $pipelineId = null;

        if ($createOpportunity) {
            if (! collect($fields)->contains(fn (array $field) => $field['type'] === FormFieldType::Phone->value)) {
                throw new FormRuleException('To create an opportunity from each response, add a phone number field — it is how the person is recognized.');
            }

            $rawPipeline = $input['opportunity_pipeline_id'] ?? null;
            if ($rawPipeline !== null && $rawPipeline !== '') {
                $pipeline = CrmPipeline::query()
                    ->where('id', (int) $rawPipeline)
                    ->where('business_id', $business->id)
                    ->whereNull('archived_at')
                    ->first();

                if ($pipeline === null) {
                    throw new FormRuleException('Choose one of this Business\'s active pipelines.');
                }

                $pipelineId = (int) $pipeline->id;
            }
        }

        return [
            'intro' => $this->optionalText($input['intro'] ?? null, self::INTRO_MAX, 'The introduction'),
            'submit_label' => $this->boundedText($input['submit_label'] ?? null, self::SUBMIT_LABEL_MAX, self::DEFAULT_SUBMIT_LABEL, 'The button label'),
            'success_message' => $this->boundedText($input['success_message'] ?? null, self::SUCCESS_MESSAGE_MAX, self::DEFAULT_SUCCESS_MESSAGE, 'The thank-you message'),
            'fields' => $fields,
            'create_opportunity' => $createOpportunity,
            'opportunity_pipeline_id' => $pipelineId,
        ];
    }

    /**
     * Stable across key order and whitespace: the hash of the normalized content.
     *
     * @param  array<string, mixed>  $content  the return value of content()
     */
    public function hash(array $content): string
    {
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool}>
     */
    private function fields(mixed $rawFields): array
    {
        if (! is_array($rawFields)) {
            throw new FormRuleException('Add at least one question.');
        }

        $fields = [];
        $seen = [];

        foreach (array_values($rawFields) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $label = trim((string) ($raw['label'] ?? ''));

            // A blank row is an unused spare row in the editor, not an error.
            if ($label === '') {
                continue;
            }

            if (mb_strlen($label) > self::LABEL_MAX) {
                throw new FormRuleException('A question label can be at most '.self::LABEL_MAX.' characters.');
            }

            $type = FormFieldType::tryFrom((string) ($raw['type'] ?? ''));
            if ($type === null) {
                throw new FormRuleException('"'.$label.'" has an unknown answer type.');
            }

            $key = trim((string) ($raw['key'] ?? ''));
            $key = $key === '' ? $this->keyFromLabel($label, $seen) : $key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_KEYS, true)) {
                throw new FormRuleException('"'.$label.'" has an invalid internal key.');
            }

            if (isset($seen[$key])) {
                throw new FormRuleException('Two questions share the internal key "'.$key.'".');
            }
            $seen[$key] = true;

            $contactName = (bool) ($raw['contact_name'] ?? false);
            if ($contactName && $type !== FormFieldType::Text) {
                throw new FormRuleException('Only a short-text question can be used as the person\'s name.');
            }

            $fields[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type->value,
                'required' => (bool) ($raw['required'] ?? false),
                'options' => $type === FormFieldType::Select ? $this->options($label, $raw['options'] ?? []) : [],
                'contact_name' => $contactName,
            ];
        }

        if ($fields === []) {
            throw new FormRuleException('Add at least one question.');
        }

        if (count($fields) > self::MAX_FIELDS) {
            throw new FormRuleException('A form can have at most '.self::MAX_FIELDS.' questions.');
        }

        if (collect($fields)->where('type', FormFieldType::Phone->value)->count() > 1) {
            throw new FormRuleException('A form can have only one phone number question.');
        }

        if (collect($fields)->where('contact_name', true)->count() > 1) {
            throw new FormRuleException('Only one question can be used as the person\'s name.');
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function options(string $label, mixed $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n|,/', (string) $raw);

        $options = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if (mb_strlen($item) > self::OPTION_MAX) {
                throw new FormRuleException('An option for "'.$label.'" can be at most '.self::OPTION_MAX.' characters.');
            }
            $options[$item] = $item;
        }

        $options = array_values($options);

        if (count($options) < 2) {
            throw new FormRuleException('"'.$label.'" needs at least two options to pick from.');
        }

        if (count($options) > self::MAX_OPTIONS) {
            throw new FormRuleException('"'.$label.'" can have at most '.self::MAX_OPTIONS.' options.');
        }

        return $options;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function keyFromLabel(string $label, array $seen): string
    {
        $base = Str::of(Str::ascii($label))->snake()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->limit(24, '')->toString();

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'field_'.$base;
        }

        $base = rtrim(substr($base, 0, 28), '_');
        $key = $base;
        $n = 2;

        while (isset($seen[$key]) || in_array($key, self::RESERVED_KEYS, true)) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    private function optionalText(mixed $value, int $max, string $what): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new FormRuleException($what.' can be at most '.$max.' characters.');
        }

        return $value;
    }

    private function boundedText(mixed $value, int $max, string $default, string $what): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $default;
        }

        if (mb_strlen($value) > $max) {
            throw new FormRuleException($what.' can be at most '.$max.' characters.');
        }

        return $value;
    }
}
