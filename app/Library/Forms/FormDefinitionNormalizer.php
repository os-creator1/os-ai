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
 * ONE DEFINITION FOR FORMS AND QUESTIONNAIRES. The content is an ordered list of
 * PAGES and a flat, ordered list of FIELDS, every field carrying the key of the
 * one page it belongs to. An ordinary form is one page; a questionnaire is two or
 * more (Blueprint §16). There is deliberately NO branching or conditional engine:
 * pages are shown in their stored order, always.
 *
 * BOUNDED ON PURPOSE: at most MAX_PAGES pages, MAX_FIELDS_PER_PAGE fields on a
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
 * whitespace never create a version.
 */
final class FormDefinitionNormalizer
{
    public const MAX_PAGES = 8;

    public const MAX_FIELDS_PER_PAGE = 25;

    public const MAX_FIELDS = 40;

    public const NAME_MAX = 120;

    public const LABEL_MAX = 120;

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
     * @return array{intro: ?string, submit_label: string, success_message: string, pages: list<array{key: string, title: ?string}>, fields: list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page: string}>, create_opportunity: bool, opportunity_pipeline_id: ?int}
     */
    public function content(Business $business, array $input): array
    {
        [$pages, $fields] = $this->pagesAndFields($input['pages'] ?? [], $input['fields'] ?? []);

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
            'pages' => $pages,
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
     * @return array{0: list<array{key: string, title: ?string}>, 1: list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page: string}>}
     */
    private function pagesAndFields(mixed $rawPages, mixed $rawFields): array
    {
        if (! is_array($rawFields)) {
            throw new FormRuleException('Add at least one question.');
        }

        $declared = $this->declaredPages($rawPages);
        $firstKey = array_key_first($declared);

        $fields = $this->fields($rawFields, $declared, $firstKey);

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
            if (count($byPage[$key]) > self::MAX_FIELDS_PER_PAGE) {
                throw new FormRuleException('A page can have at most '.self::MAX_FIELDS_PER_PAGE.' questions.');
            }

            $pages[] = ['key' => $key, 'title' => $page['title']];
            array_push($ordered, ...$byPage[$key]);
        }

        if (count($ordered) > self::MAX_FIELDS) {
            throw new FormRuleException('A form can have at most '.self::MAX_FIELDS.' questions in total.');
        }

        if (collect($ordered)->where('type', FormFieldType::Phone->value)->count() > 1) {
            throw new FormRuleException('A form can have only one phone number question.');
        }

        if (collect($ordered)->where('contact_name', true)->count() > 1) {
            throw new FormRuleException('Only one question can be used as the person\'s name.');
        }

        return [$pages, $ordered];
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
     * @return list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page: string}>
     */
    private function fields(array $rawFields, array $declared, string $firstPageKey): array
    {
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

            $page = trim((string) ($raw['page'] ?? ''));
            $page = $page === '' ? $firstPageKey : $page;
            if (! isset($declared[$page])) {
                throw new FormRuleException('"'.$label.'" is on a page that does not exist.');
            }

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
                'page' => $page,
            ];
        }

        if ($fields === []) {
            throw new FormRuleException('Add at least one question.');
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
