<?php

namespace Tests\Feature\Documents\Templates;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Library\Documents\DocumentManager;
use App\Library\Documents\Templates\RecommendedPlatformTemplates;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\CatalogItemImage;
use App\Models\DocumentTemplate;
use Illuminate\Support\Collection;
use Tests\Feature\Documents\Editor\EditorTestHelpers;

/**
 * Fixtures for the Contract 17B stage 5 template tests. The using class also
 * uses SendsDocuments, CreatesDocumentsTestData and CreatesCatalogHttpFixtures.
 */
trait TemplateTestHelpers
{
    use EditorTestHelpers;

    protected function ownImage(Business $business, string $name = 'Own Package'): CatalogItemImage
    {
        return CatalogItemImage::create([
            'catalog_item_id' => $this->catalogItem($business, $name)->id,
            'disk' => 'public', 'path' => 'x/' . uniqid() . '.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'position' => 0,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function layoutBlocks(?string $imageUid = null): array
    {
        return array_values(array_filter([
            ['id' => 'b-title', 'type' => 'heading', 'data' => ['level' => 1, 'align' => 'center', 'runs' => [['t' => 'Proposal for ', 'b' => true], ['merge' => 'contact.first_name']]]],
            ['id' => 'b-intro', 'type' => 'text', 'data' => ['align' => 'left', 'runs' => [['t' => 'Thank you for choosing '], ['merge' => 'business.name'], ['t' => '.']]]],
            $imageUid === null ? null : ['id' => 'b-image', 'type' => 'image', 'data' => ['catalog_image_uid' => $imageUid, 'alt' => 'Hero', 'width_pct' => 60]],
            ['id' => 'b-sec', 'type' => 'section', 'data' => ['title' => 'Investment']],
            ['id' => 'b-products', 'type' => 'product_list', 'data' => ['show_description' => false, 'show_quantity' => true]],
            ['id' => 'b-payment', 'type' => 'payment_terms', 'data' => []],
            ['id' => 'b-sign', 'type' => 'signature', 'data' => ['label' => 'Client signature']],
        ]));
    }

    /**
     * A draft that carries EVERYTHING a template must not copy: contact, recipient,
     * a line with a price, a deposit plan and compiled schedule.
     */
    protected function richDocument(array $tenant, ?string $imageUid = null, string $title = 'Smith wedding proposal'): BusinessDocument
    {
        $manager = app(DocumentManager::class);
        $document = $this->blankDocument($tenant, $title);
        $manager->saveBlocks($document, $this->layoutBlocks($imageUid));
        $manager->addCustomLine($document, 'Design work', 'Secret scope', 1, 100000);
        $manager->setPaymentPlan($document, ['structure' => 'deposit', 'deposit_minor' => 20000, 'full_due' => 'on_signing', 'balance_due' => 'after_deposit']);

        return $document->refresh();
    }

    /** A Business-owned ACTIVE template, inserted directly (no service) so tests can plant arbitrary content. */
    protected function plantTemplate(Business $business, array $blocks, array $attributes = []): DocumentTemplate
    {
        $template = new DocumentTemplate($attributes + [
            'business_id' => $business->id,
            'template_type' => 'proposal',
            'name' => 'Planted template',
            'description' => null,
            'blocks' => $blocks,
            'schema_version' => 2,
        ]);
        $template->status = DocumentTemplateStatus::Active;
        $template->save();

        return $template->refresh();
    }

    protected function platformTemplate(array $blocks = [], string $name = 'Platform template'): DocumentTemplate
    {
        return $this->plantTemplate(new Business(['id' => null]), $blocks, ['business_id' => null, 'name' => $name]);
    }

    /** Bind a RecommendedPlatformTemplates that recommends exactly these templates. */
    protected function recommend(DocumentTemplate ...$templates): void
    {
        $this->app->bind(RecommendedPlatformTemplates::class, fn () => new class(collect($templates)) extends RecommendedPlatformTemplates {
            public function __construct(private Collection $templates)
            {
            }

            public function forBusiness(Business $business): Collection
            {
                return $this->templates;
            }
        });
    }

    protected function tpl(string $name, array $tenant, ?DocumentTemplate $template = null, array $extra = []): string
    {
        $parameters = [$tenant['workspace']->uid, $tenant['business']->uid];
        if ($template !== null) {
            $parameters[] = $template->uid;
        }

        return route('customer.workspaces.businesses.document-templates.' . $name, [...$parameters, ...$extra]);
    }

    /** @return array<string, mixed> the bootstrap JSON embedded in an editor page */
    protected function pageBootstrap(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="document-editor-bootstrap">(.*?)</script>#s', $html, $match), 'bootstrap script missing');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    protected function docsStore(array $tenant, array $payload)
    {
        return $this->post(route('customer.workspaces.businesses.documents.store', [$tenant['workspace']->uid, $tenant['business']->uid]), $payload + [
            'kind' => 'proposal', 'via' => 'editor', 'title' => 'From template', 'contact_uid' => $tenant['contact']->uid,
        ]);
    }
}
