<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Forms V1 — one immutable version of what a form asks.
 *
 * Written once by `FormManager` and then never touched: an update or a delete
 * through Eloquent is refused, because a submission points at this row to stay
 * intelligible after the form is edited. There is no `updated_at`.
 *
 * ONE DEFINITION MODEL FOR FORMS AND QUESTIONNAIRES. `pages` is the ordered list
 * of `{key, title}`; every field carries the key of the ONE page it belongs to.
 * An ordinary form is one page, a questionnaire is two or more. A version written
 * before pages existed has a NULL `pages` and reads as one implicit page holding
 * every field, so nothing already stored changes meaning.
 *
 * @property int $id
 * @property int $form_id
 * @property int $version
 * @property string $content_hash
 * @property ?string $intro
 * @property string $submit_label
 * @property string $success_message
 * @property ?list<array{key: string, title: ?string}> $pages
 * @property list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page?: string}> $fields
 * @property bool $create_opportunity
 * @property ?int $opportunity_pipeline_id
 */
class FormVersion extends Model
{
    public const UPDATED_AT = null;

    /** The key of the single implicit page of a version that predates pages. */
    public const IMPLICIT_PAGE_KEY = 'page_1';

    protected $fillable = [
        'form_id',
        'version',
        'content_hash',
        'intro',
        'submit_label',
        'success_message',
        'pages',
        'design',
        'fields',
        'create_opportunity',
        'opportunity_pipeline_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'pages' => 'array',
        'design' => 'array',
        'fields' => 'array',
        'create_opportunity' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A form version is immutable; write a new version instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('A form version is never deleted; submissions depend on it.');
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return array<string, array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page?: string}> */
    public function fieldsByKey(): array
    {
        $byKey = [];
        foreach ($this->fields ?? [] as $field) {
            $byKey[$field['key']] = $field;
        }

        return $byKey;
    }

    /**
     * The ordered pages. Never empty: a version without a stored structure is one
     * implicit untitled page.
     *
     * @return list<array{key: string, title: ?string}>
     */
    public function pages(): array
    {
        $pages = $this->pages;

        if (! is_array($pages) || $pages === []) {
            return [['key' => self::IMPLICIT_PAGE_KEY, 'title' => null]];
        }

        return array_values($pages);
    }

    public function isMultiPage(): bool
    {
        return count($this->pages()) > 1;
    }

    /** @return list<string> page keys in order */
    public function pageKeys(): array
    {
        return array_column($this->pages(), 'key');
    }

    /**
     * The fields of one page, in the form's field order.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options: list<string>, contact_name: bool, page?: string}>
     */
    public function fieldsOnPage(string $pageKey): array
    {
        $first = $this->pageKeys()[0];

        return array_values(array_filter(
            $this->fields ?? [],
            fn (array $field) => ($field['page'] ?? $first) === $pageKey
        ));
    }

    /**
     * The form style, never null: a version that never set one has no overrides
     * and renders with the platform defaults.
     *
     * @return array{accent?: string, background?: string, button_align?: string, radius?: string, width?: string}
     */
    public function style(): array
    {
        $design = $this->getAttribute('design');

        return is_array($design) ? $design : [];
    }

    /**
     * Only the elements that collect an answer (headings, paragraphs, dividers and
     * spacers carry none), in the form's order.
     *
     * @return list<array<string, mixed>>
     */
    public function inputFields(): array
    {
        return array_values(array_filter(
            $this->fields ?? [],
            fn (array $field) => \App\Enums\Forms\FormFieldType::from($field['type'])->isInput()
        ));
    }

    /** 0-based position of a page, or null when this version has no such page. */
    public function pageIndex(string $pageKey): ?int
    {
        $index = array_search($pageKey, $this->pageKeys(), true);

        return $index === false ? null : (int) $index;
    }
}
