<?php

namespace App\Library\Forms;

use App\Enums\Forms\FormFieldType;
use App\Library\CustomFields\FormFieldMapping;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Models\Business;
use App\Models\CrmPipeline;
use Illuminate\Support\Str;

/**
 * Forms V1 — the one place a form definition's content is bounded and
 * normalized. Pure rules over the input plus ONE persistence read (the
 * optional Opportunity pipeline, re-proven against the Business).
 *
 * ONE DEFINITION FOR FORMS AND QUESTIONNAIRES. The content is an ordered list of
 * PAGES and a flat, ordered list of FIELDS, every field carrying the key of the
 * one page it belongs to. An ordinary form is one page; a questionnaire is two or
 * more (Blueprint §16). There is deliberately NO branching or conditional engine:
 * pages are shown in their stored order, always.
 *
 * FIELDS ARE ELEMENTS. A field is an input (an answer), a consent (an input that
 * records an explicit agreement) or a content block (heading, paragraph, divider,
 * spacer — presentation only). See FormFieldType. Only inputs count toward the
 * question limits; content blocks have their own small bound.
 *
 * BOUNDED ON PURPOSE: at most MAX_PAGES pages, MAX_FIELDS_PER_PAGE inputs on a
 * page and MAX_FIELDS in total, one closed type set, at most one phone field (the
 * Contact identity key) and at most one "contact name" field across the WHOLE
 * definition. This is a lead/questionnaire form, not an application builder.
 *
 * Input shape: `pages` is a list of `{key?, title?, position?}`; each field names
 * its page with `page` (a page key). With no `pages`, every field is on one page.
 * A page with no field is an unused spare and is dropped; remaining pages are
 * ordered by `position` (ties keep submitted order), so reordering pages is an
 * edit of `position`, and a page keeps its key (and its fields' answers keep
 * their meaning) wherever it moves.
 *
 * The normalized array is also what the content hash is taken over, so two
 * saves with the same meaning always hash the same — key order, blank rows and
 * whitespace never create a version. Every optional key (a mapping, a placeholder,
 * the form `design`, …) is present ONLY when set, so a definition that uses none
 * of them hashes exactly as it did before they existed.
 */
final class FormDefinitionNormalizer
{
    public const MAX_PAGES = 8;

    public const MAX_FIELDS_PER_PAGE = 25;

    public const MAX_FIELDS = 40;

    /** Content blocks (heading/paragraph/divider/spacer) in the whole definition. */
    public const MAX_BLOCKS = 30;

    public const NAME_MAX = 120;

    public const LABEL_MAX = 120;

    /** A paragraph's text, which is its `label`. */
    public const PARAGRAPH_MAX = 1000;

    /** A consent statement is its `label`; it may need to be a full sentence or two. */
    public const CONSENT_MAX = 500;

    public const PLACEHOLDER_MAX = 120;

    public const HELP_MAX = 300;

    public const DEFAULT_MAX = 200;

    public const PAGE_TITLE_MAX = 120;

    public const OPTION_MAX = 80;

    public const MAX_OPTIONS = 20;

    public const INTRO_MAX = 1000;

    public const SUBMIT_LABEL_MAX = 40;

    public const SUCCESS_MESSAGE_MAX = 300;

    public const DEFAULT_SUBMIT_LABEL = 'Send';

    public const DEFAULT_SUCCESS_MESSAGE = 'Thanks — we got your message and will be in touch.';

    /** Request-input names the public form itself uses; a field may never shadow one. */
    public const RESERVED_KEYS = ['form_hp', 'operation_token', 'location_uid', 'page'];

    public const BUTTON_ALIGNS = ['left', 'center', 'right', 'full'];

    public const RADII = ['none', 'sm', 'md', 'lg'];

    public const WIDTHS = ['narrow', 'medium', 'wide'];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    private const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

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
     * @return array<string, mixed> intro, submit_label, success_message, pages, fields, create_opportunity, opportunity_pipeline_id and — only when set — design
     */
    public function content(Business $business, array $input, array $previousFields = []): array
    {
        [$pages, $fields] = $this->pagesAndFields($input['pages'] ?? [], $input['fields'] ?? [], $business, $previousFields);

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

        $content = [
            'intro' => $this->optionalText($input['intro'] ?? null, self::INTRO_MAX, 'The introduction'),
            'submit_label' => $this->boundedText($input['submit_label'] ?? null, self::SUBMIT_LABEL_MAX, self::DEFAULT_SUBMIT_LABEL, 'The button label'),
            'success_message' => $this->boundedText($input['success_message'] ?? null, self::SUCCESS_MESSAGE_MAX, self::DEFAULT_SUCCESS_MESSAGE, 'The thank-you message'),
            'pages' => $pages,
            'fields' => $fields,
            'create_opportunity' => $createOpportunity,
            'opportunity_pipeline_id' => $pipelineId,
        ];

        $design = $this->design($input['design'] ?? null);
        if ($design !== []) {
            $content['design'] = $design;
        }

        return $content;
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
     * The lightweight Form Style: a few closed choices, never free CSS. Returns
     * only the keys that were actually set (in a fixed order), so "no style" is
     * the empty array and is not stored.
     *
     * @return array<string, string>
     */
    public function design(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $design = [];

        foreach (['accent', 'background'] as $colorKey) {
            $color = trim((string) ($raw[$colorKey] ?? ''));

            if ($color === '') {
                continue;
            }

            if (preg_match(self::COLOR_PATTERN, $color) !== 1) {
                throw new FormRuleException('Colors must look like #1a73e8.');
            }

            $design[$colorKey] = strtolower($color);
        }

        foreach (['button_align' => self::BUTTON_ALIGNS, 'radius' => self::RADII, 'width' => self::WIDTHS] as $choiceKey => $allowed) {
            $choice = trim((string) ($raw[$choiceKey] ?? ''));

            if ($choice === '') {
                continue;
            }

            if (! in_array($choice, $allowed, true)) {
                throw new FormRuleException('That form style choice is not available.');
            }

            $design[$choiceKey] = $choice;
        }

        // Fixed order, whatever order they were submitted in.
        $order = ['accent', 'background', 'button_align', 'radius', 'width'];

        return array_intersect_key(array_replace(array_flip($order), $design), $design);
    }

    /**
     * @return array{0: list<array{key: string, title: ?string}>, 1: list<array<string, mixed>>}
     */
    private function pagesAndFields(mixed $rawPages, mixed $rawFields, Business $business, array $previousFields): array
    {
        if (! is_array($rawFields)) {
            throw new FormRuleException('Add at least one question.');
        }

        $declared = $this->declaredPages($rawPages);
        $firstKey = array_key_first($declared);

        $fields = $this->fields($rawFields, $declared, $firstKey, $business, $previousFields);

        // Group by page, drop pages nobody uses, order the rest.
        $byPage = [];
        foreach ($fields as $field) {
            $byPage[$field['page']][] = $field;
        }

        $used = array_filter($declared, fn (array $page, string $key) => isset($byPage[$key]), ARRAY_FILTER_USE_BOTH);

        uasort($used, fn (array $a, array $b) => [$a['position'], $a['order']] <=> [$b['position'], $b['order']]);

        if (count($used) > self::MAX_PAGES) {
            throw new FormRuleException('A form can have at most '.self::MAX_PAGES.' pages.');
        }

        $pages = [];
        $ordered = [];
        foreach ($used as $key => $page) {
            $inputsOnPage = count(array_filter($byPage[$key], fn (array $field) => FormFieldType::from($field['type'])->isInput()));

            if ($inputsOnPage > self::MAX_FIELDS_PER_PAGE) {
                throw new FormRuleException('A page can have at most '.self::MAX_FIELDS_PER_PAGE.' questions.');
            }

            $pages[] = ['key' => $key, 'title' => $page['title']];
            array_push($ordered, ...$byPage[$key]);
        }

        $inputs = array_filter($ordered, fn (array $field) => FormFieldType::from($field['type'])->isInput());

        if ($inputs === []) {
            throw new FormRuleException('Add at least one question.');
        }

        if (count($inputs) > self::MAX_FIELDS) {
            throw new FormRuleException('A form can have at most '.self::MAX_FIELDS.' questions in total.');
        }

        if (count($ordered) - count($inputs) > self::MAX_BLOCKS) {
            throw new FormRuleException('A form can have at most '.self::MAX_BLOCKS.' headings, paragraphs, dividers and spacers in total.');
        }

        $this->assertIdentityRules($ordered);

        return [$pages, $ordered];
    }

    /**
     * The whole-definition rules about which questions identify the person.
     *
     * @param  list<array<string, mixed>>  $ordered
     */
    private function assertIdentityRules(array $ordered): void
    {
        $fields = collect($ordered);

        if ($fields->where('type', FormFieldType::Phone->value)->count() > 1) {
            throw new FormRuleException('A form can have only one phone number question.');
        }

        if ($fields->where('contact_name', true)->count() > 1) {
            throw new FormRuleException('Only one question can be used as the person\'s name.');
        }

        foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $part => $words) {
            if ($fields->where('contact_part', $part)->count() > 1) {
                throw new FormRuleException('Only one question can be used as the person\'s '.$words.'.');
            }
        }

        if ($fields->contains('contact_name', true) && $fields->contains(fn (array $field) => isset($field['contact_part']))) {
            throw new FormRuleException('Use either one full-name question or separate first- and last-name questions, not both.');
        }

        foreach ([FormFieldType::ConsentTransactional, FormFieldType::ConsentMarketing] as $consent) {
            if ($fields->where('type', $consent->value)->count() > 1) {
                throw new FormRuleException('A form can have only one '.mb_strtolower($consent->label()).' question.');
            }
        }
    }

    /**
     * @return array<string, array{key: string, title: ?string, position: int, order: int}> keyed by page key, in submitted order
     */
    private function declaredPages(mixed $rawPages): array
    {
        $list = is_array($rawPages) ? array_values(array_filter($rawPages, 'is_array')) : [];

        if ($list === []) {
            $list = [['key' => 'page_1']];
        }

        // Slots the editor always posts are bounded; anything beyond is refused
        // outright rather than silently truncated.
        if (count($list) > self::MAX_PAGES * 2) {
            throw new FormRuleException('A form can have at most '.self::MAX_PAGES.' pages.');
        }

        $declared = [];

        foreach ($list as $order => $raw) {
            $key = trim((string) ($raw['key'] ?? ''));
            $key = $key === '' ? $this->unusedPageKey($declared) : $key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new FormRuleException('A page has an invalid internal key.');
            }

            if (isset($declared[$key])) {
                throw new FormRuleException('Two pages share the internal key "'.$key.'".');
            }

            $title = trim((string) ($raw['title'] ?? ''));
            if (mb_strlen($title) > self::PAGE_TITLE_MAX) {
                throw new FormRuleException('A page title can be at most '.self::PAGE_TITLE_MAX.' characters.');
            }

            $position = isset($raw['position']) && is_numeric($raw['position']) ? (int) $raw['position'] : $order + 1;

            $declared[$key] = ['key' => $key, 'title' => $title === '' ? null : $title, 'position' => $position, 'order' => $order];
        }

        return $declared;
    }

    /**
     * @param  array<string, array<string, mixed>>  $declared
     */
    private function unusedPageKey(array $declared): string
    {
        for ($n = 1; ; $n++) {
            if (! isset($declared['page_'.$n])) {
                return 'page_'.$n;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $declared
     * @return list<array<string, mixed>>
     */
    private function fields(array $rawFields, array $declared, string $firstPageKey, Business $business, array $previousFields): array
    {
        $fields = [];
        $seen = [];
        $mapped = [];
        $mapper = app(FormFieldMapping::class);
        $previousMappings = [];

        foreach ($previousFields as $previous) {
            if (is_array($previous) && ! empty($previous['custom_field_uid'])) {
                $previousMappings[(string) ($previous['key'] ?? '')] = (string) $previous['custom_field_uid'];
            }
        }

        foreach (array_values($rawFields) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $label = trim((string) ($raw['label'] ?? ''));

            $type = FormFieldType::tryFrom((string) ($raw['type'] ?? ''));

            // A blank row is an unused spare row in the editor, not an error. A
            // divider or spacer is legitimately blank.
            if ($label === '' && ! ($type?->allowsEmptyLabel() ?? false)) {
                continue;
            }

            if ($type === null) {
                throw new FormRuleException('"'.$label.'" has an unknown answer type.');
            }

            $labelMax = match (true) {
                $type === FormFieldType::Paragraph => self::PARAGRAPH_MAX,
                $type->isConsent() => self::CONSENT_MAX,
                default => self::LABEL_MAX,
            };

            if (mb_strlen($label) > $labelMax) {
                throw new FormRuleException(match (true) {
                    $type === FormFieldType::Paragraph => 'A paragraph can be at most '.$labelMax.' characters.',
                    $type->isConsent() => 'A consent statement can be at most '.$labelMax.' characters.',
                    default => 'A question label can be at most '.$labelMax.' characters.',
                });
            }

            $key = trim((string) ($raw['key'] ?? ''));
            $key = $key === '' ? $this->keyFromLabel($label !== '' ? $label : $type->value, $seen) : $key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_KEYS, true)) {
                throw new FormRuleException('"'.($label !== '' ? $label : $type->label()).'" has an invalid internal key.');
            }

            if (isset($seen[$key])) {
                throw new FormRuleException('Two questions share the internal key "'.$key.'".');
            }
            $seen[$key] = true;

            $page = trim((string) ($raw['page'] ?? ''));
            $page = $page === '' ? $firstPageKey : $page;
            if (! isset($declared[$page])) {
                throw new FormRuleException('"'.($label !== '' ? $label : $type->label()).'" is on a page that does not exist.');
            }

            $contactName = (bool) ($raw['contact_name'] ?? false);
            if ($contactName && $type !== FormFieldType::Text) {
                throw new FormRuleException('Only a short-text question can be used as the person\'s name.');
            }

            $contactPart = trim((string) ($raw['contact_part'] ?? ''));
            if ($contactPart !== '') {
                if (! in_array($contactPart, ['first_name', 'last_name'], true)) {
                    throw new FormRuleException('"'.$label.'" has an unknown contact detail.');
                }

                if ($type !== FormFieldType::Text) {
                    throw new FormRuleException('Only a short-text question can be used as the person\'s first or last name.');
                }

                if ($contactName) {
                    throw new FormRuleException('"'.$label.'" cannot be both the full name and a part of it.');
                }
            }

            $field = [
                'key' => $key,
                'label' => $label,
                'type' => $type->value,
                'required' => $type->isInput() && (bool) ($raw['required'] ?? false),
                'options' => $type->hasOptions() ? $this->options($label, $raw['options'] ?? []) : [],
                'contact_name' => $contactName,
                'page' => $page,
            ];

            // EXPLICIT "Save answer to" mapping, by the custom field's stable uid.
            // Present only when set, so a form without a mapping hashes exactly as
            // it did before this key existed.
            $uid = trim((string) ($raw['custom_field_uid'] ?? ''));

            if ($uid !== '') {
                if (! $type->isInput() || $type->isConsent()) {
                    throw new FormRuleException('"'.($label !== '' ? $label : $type->label()).'" cannot be saved to a contact field.');
                }

                if (isset($mapped[$uid])) {
                    throw new FormRuleException('Two questions are set to save to the same contact field.');
                }

                $mapped[$uid] = true;
                $field['custom_field_uid'] = $mapper->assertMappable(
                    $business,
                    $field,
                    $uid,
                    ($previousMappings[$key] ?? null) === $uid,
                );
            }

            // Optional presentation keys — present only when set.
            if ($type->isInput() && ! $type->isConsent()) {
                $placeholder = $this->optionalText($raw['placeholder'] ?? null, self::PLACEHOLDER_MAX, 'A placeholder');
                if ($placeholder !== null && in_array($type, [FormFieldType::Text, FormFieldType::Textarea, FormFieldType::Email, FormFieldType::Phone, FormFieldType::Number, FormFieldType::Currency, FormFieldType::Select], true)) {
                    $field['placeholder'] = $placeholder;
                }
            }

            if ($type->isInput()) {
                $help = $this->optionalText($raw['help'] ?? null, self::HELP_MAX, 'Help text');
                if ($help !== null) {
                    $field['help'] = $help;
                }

                if (($raw['width'] ?? null) === 'half') {
                    $field['width'] = 'half';
                }

                $default = $this->defaultValue($type, $raw['default'] ?? null, $field['options'], $label);
                if ($default !== null) {
                    $field['default'] = $default;
                }
            }

            if ($contactPart !== '') {
                $field['contact_part'] = $contactPart;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * A default value, only where it is safe: plain text-like answers and a
     * choice that is one of the owner's own options. Never for consent (a
     * consent is never pre-checked) or a checkbox, and never for a date (a
     * stale default date silently becomes the answer).
     *
     * @param  list<string>  $options
     */
    private function defaultValue(FormFieldType $type, mixed $raw, array $options, string $label): ?string
    {
        if (! in_array($type, [FormFieldType::Text, FormFieldType::Textarea, FormFieldType::Select, FormFieldType::Radio, FormFieldType::Number, FormFieldType::Currency], true)) {
            return null;
        }

        $value = $this->optionalText($raw, self::DEFAULT_MAX, 'A default value');

        if ($value === null) {
            return null;
        }

        if ($type->hasOptions() && ! in_array($value, $options, true)) {
            throw new FormRuleException('The default for "'.$label.'" must be one of its options.');
        }

        if (in_array($type, [FormFieldType::Number, FormFieldType::Currency], true) && ! is_numeric($value)) {
            throw new FormRuleException('The default for "'.$label.'" must be a number.');
        }

        return $value;
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
