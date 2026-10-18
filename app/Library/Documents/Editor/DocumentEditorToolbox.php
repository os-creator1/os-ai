<?php

namespace App\Library\Documents\Editor;

use App\Models\Business;
use App\Models\CatalogItemImage;

/**
 * Implementation Contract 17B — the single definition of the editor's block
 * toolbox. The Blade view renders the buttons from it (server-side icons) and
 * the JS reads the same ids, so the two cannot drift. It only lists what the
 * editor can actually place: text / date / checkbox signing fields are
 * deliberately absent (contract 17B §1: deferred).
 *
 * Each item: id (stable key the JS maps to a block factory), label, icon
 * (a lucide name), `type` (the BlockSchema type it inserts, or null for an
 * action that is not a block) and `action` (null = insert a block; 'product' =
 * insert the single product block if absent and open the product wizard;
 * 'custom_line' = open the one-off line form).
 */
final class DocumentEditorToolbox
{
    /**
     * @return array<int, array{id: string, label: string, items: array<int, array<string, mixed>>}>
     */
    public static function categories(): array
    {
        return [
            ['id' => 'content', 'label' => 'Content', 'items' => [
                self::item('text', 'Text', 'type', 'text'),
                self::item('heading', 'Heading', 'heading', 'heading'),
                self::item('image', 'Image', 'image', 'image'),
                self::item('divider', 'Divider', 'minus', 'divider'),
                self::item('spacer', 'Spacer', 'move-vertical', 'spacer'),
            ]],
            ['id' => 'commerce', 'label' => 'Commerce', 'items' => [
                self::item('product', 'Add product', 'package', 'product_list', 'product'),
                self::item('custom_line', 'Custom line item', 'circle-plus', 'product_list', 'custom_line'),
                self::item('payment_terms', 'Payment terms', 'wallet', 'payment_terms'),
            ]],
            ['id' => 'fields', 'label' => 'Fields', 'items' => [
                self::item('signature', 'Signature', 'pen-line', 'signature'),
            ]],
            ['id' => 'structure', 'label' => 'Structure', 'items' => [
                self::item('section', 'Section', 'panel-top', 'section'),
                self::item('page_break', 'Page break', 'file-minus', 'page_break'),
            ]],
            ['id' => 'business', 'label' => 'Business', 'items' => [
                self::item('business_details', 'Business details', 'building-2', 'business_details'),
                self::item('contact_name', 'Contact name', 'user', 'text'),
                self::item('contact_email', 'Contact email', 'mail', 'text'),
            ]],
        ];
    }

    /**
     * The toolbox a PLATFORM template is built from (17B §6b): the same list without the Image block - there is no
     * platform media seam in V1 and BlockSchema refuses image blocks in a platform template.
     *
     * @return array<int, array{id: string, label: string, items: array<int, array<string, mixed>>}>
     */
    public static function platformCategories(): array
    {
        return array_map(function (array $category): array {
            $category['items'] = array_values(array_filter($category['items'], fn (array $item) => $item['type'] !== 'image'));

            return $category;
        }, self::categories());
    }

    /**
     * Every icon the editor chrome draws, rendered once server-side through the
     * design system's icon seam and cloned by the JS.
     *
     * @return array<int, string>
     */
    public static function icons(): array
    {
        $tools = [];
        foreach (self::categories() as $category) {
            foreach ($category['items'] as $item) {
                $tools[] = $item['icon'];
            }
        }

        return array_values(array_unique(array_merge($tools, [
            'arrow-left', 'eye', 'save', 'send', 'ellipsis', 'panel-left', 'grip-vertical', 'chevron-up', 'chevron-down',
            'copy', 'trash-2', 'bold', 'italic', 'underline', 'link', 'unlink', 'align-left', 'align-center', 'align-right',
            'plus', 'check', 'x', 'search', 'triangle-alert', 'loader-circle', 'pencil', 'calendar-days', 'file-plus', 'layers-2',
        ])));
    }

    /**
     * The Business's own catalog images, for the image block's picker. Image
     * blocks may reference nothing else (contract 17B §1: no media seam).
     *
     * @return array<int, array{uid: string, url: string, alt: string, name: string}>
     */
    public static function images(Business $business, int $limit = 100): array
    {
        return CatalogItemImage::query()
            ->whereHas('catalogItem', fn ($q) => $q->where('business_id', $business->id))
            ->with('catalogItem:id,name')
            ->orderBy('catalog_item_id')->orderBy('position')->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (CatalogItemImage $image) => [
                'uid' => (string) $image->uid,
                'url' => $image->url(),
                'alt' => (string) $image->alt_text,
                'name' => (string) ($image->catalogItem?->name ?? ''),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $id, string $label, string $icon, ?string $type, ?string $action = null): array
    {
        return ['id' => $id, 'label' => $label, 'icon' => $icon, 'type' => $type, 'action' => $action];
    }
}
