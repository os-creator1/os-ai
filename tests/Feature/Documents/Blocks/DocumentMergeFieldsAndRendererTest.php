<?php

namespace Tests\Feature\Documents\Blocks;

use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Blocks\DocumentBlockRenderer;
use App\Library\Documents\Blocks\DocumentMergeFields;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

/**
 * Contract 17B §2 / §4 — the merge-field allow-list and the one renderer, in
 * every mode. Pure rendering: no database.
 */
class DocumentMergeFieldsAndRendererTest extends TestCase
{
    private const XSS = '<script>alert(1)</script><img src=x onerror=alert(1)>"\'';

    /** The markup without the inline stylesheet (whose selectors mention class names). */
    private function body(string $html): string
    {
        return (string) preg_replace('#<style>.*?</style>#s', '', $html);
    }

    private function renderer(): DocumentBlockRenderer
    {
        return new DocumentBlockRenderer();
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function blocks(array $blocks): array
    {
        return BlockSchema::normalize($blocks);
    }

    // ---- merge fields -------------------------------------------------

    public function test_catalog_is_the_exact_allow_list(): void
    {
        $this->assertSame([
            'contact.first_name', 'contact.full_name', 'contact.email',
            'business.name', 'business.phone', 'business.email', 'business.website',
            'document.title',
        ], DocumentMergeFields::tokens());

        foreach (DocumentMergeFields::catalog() as $row) {
            $this->assertNotSame('', $row['label']);
            $this->assertTrue(DocumentMergeFields::isAllowed($row['token']));
        }

        foreach (['contact.password', 'business', '', 'contact.first_name ', "contact.first_name\n", '{{contact.first_name}}', 'config("app.key")', 'document.title|upper'] as $bad) {
            $this->assertFalse(DocumentMergeFields::isAllowed($bad), $bad);
        }

        $this->assertFalse(DocumentMergeFields::isAllowed(['contact.first_name']));
        $this->assertFalse(DocumentMergeFields::isAllowed(null));
    }

    public function test_unknown_token_resolves_to_nothing_even_if_the_context_holds_it(): void
    {
        $context = ['contact.first_name' => 'Pat', 'app.key' => 'SECRET', 'contact.password' => 'hunter2'];

        $this->assertSame('Pat', DocumentMergeFields::resolve('contact.first_name', $context));
        $this->assertSame('', DocumentMergeFields::resolve('app.key', $context));
        $this->assertSame('', DocumentMergeFields::resolve('contact.password', $context));
        $this->assertSame('', DocumentMergeFields::resolve('business.name', $context), 'missing value is empty');
        $this->assertSame('', DocumentMergeFields::resolve('contact.first_name', ['contact.first_name' => ['x']]), 'non-string value is empty');
    }

    public function test_frozen_parties_only_trust_allow_listed_string_values(): void
    {
        $context = DocumentMergeFields::fromFrozenParties([
            'business_name' => 'Legacy Co',
            'document_title' => 'Legacy title',
            'recipient_name' => 'Pat Rivera',
            'merge' => ['business.name' => 'Frozen Co', 'evil.token' => 'x', 'business.email' => ['array']],
        ]);

        $this->assertSame('Frozen Co', $context['business.name'], 'explicit frozen value wins over legacy key');
        $this->assertSame('Legacy title', $context['document.title']);
        $this->assertSame('Pat Rivera', $context['contact.full_name']);
        $this->assertSame('Pat', $context['contact.first_name']);
        $this->assertSame('', $context['business.email']);
        $this->assertArrayNotHasKey('evil.token', $context);
        $this->assertSame(DocumentMergeFields::tokens(), array_keys($context));
    }

    public function test_sample_context_covers_every_token(): void
    {
        $sample = DocumentMergeFields::sample();

        $this->assertSame(DocumentMergeFields::tokens(), array_keys($sample));
        foreach ($sample as $value) {
            $this->assertNotSame('', $value);
        }
    }

    // ---- renderer: escaping ------------------------------------------

    public function test_all_text_and_attributes_are_escaped_in_every_mode(): void
    {
        $blocks = $this->blocks([
            ['type' => 'heading', 'data' => ['level' => 2, 'runs' => [['t' => self::XSS]]]],
            ['type' => 'text', 'data' => ['runs' => [['t' => self::XSS, 'b' => true, 'href' => 'https://example.com/?a=1&b="x"']]]],
            ['type' => 'section', 'data' => ['title' => self::XSS]],
            ['type' => 'signature', 'data' => ['label' => self::XSS]],
            ['type' => 'text', 'data' => ['runs' => [['merge' => 'contact.first_name']]]],
        ]);
        $merge = ['contact.first_name' => self::XSS];

        foreach (DocumentBlockRenderer::MODES as $mode) {
            $html = (string) $this->renderer()->render($blocks, $mode, ['merge' => $merge]);

            $this->assertStringNotContainsString('<script>alert', $html, $mode);
            $this->assertStringNotContainsString('<img src=x', $html, $mode);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $mode);
            $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html, $mode);
            $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=&quot;x&quot;"', $html, $mode);
        }
    }

    public function test_a_hostile_array_that_skipped_the_schema_is_still_rendered_safely(): void
    {
        $html = (string) $this->renderer()->render([
            ['id' => 'a"><script>', 'type' => 'text', 'data' => ['align' => 'x" onload="evil()', 'runs' => [
                ['t' => 'link', 'href' => 'javascript:alert(1)'],
                ['merge' => 'app.key'],
            ]]],
            ['type' => 'heading', 'data' => ['level' => 99, 'runs' => []]],
            ['type' => 'spacer', 'data' => ['height' => 99999]],
            ['type' => 'unknown_type', 'data' => []],
        ], 'public', ['merge' => ['app.key' => 'SECRET']]);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('SECRET', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onload="evil', $html);
        $this->assertStringContainsString('<h3', $html, 'heading level is clamped');
        $this->assertStringContainsString('height: 120px', $html, 'spacer height is clamped');
        $this->assertStringNotContainsString('unknown_type', $html);
    }

    public function test_runs_render_only_b_i_u_and_a(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([
            ['type' => 'text', 'data' => ['runs' => [['t' => 'x', 'b' => true, 'i' => true, 'u' => true, 'href' => 'https://example.com']]]],
        ]), 'preview');

        $this->assertStringContainsString('<a href="https://example.com" rel="noopener noreferrer nofollow" target="_blank"><u><i><b>x</b></i></u></a>', $html);
    }

    // ---- renderer: modes ----------------------------------------------

    public function test_editor_shows_merge_chips_but_preview_and_public_resolve_them(): void
    {
        $blocks = $this->blocks([['type' => 'text', 'data' => ['runs' => [['t' => 'Hi '], ['merge' => 'contact.first_name']]]]]);
        $merge = ['contact.first_name' => 'Pat'];

        $editor = (string) $this->renderer()->render($blocks, 'editor', ['merge' => $merge]);
        $this->assertStringContainsString('data-token="contact.first_name"', $editor);
        $this->assertStringContainsString('Contact first name', $editor);
        $this->assertStringNotContainsString('Pat', $editor);

        foreach (['preview', 'public'] as $mode) {
            $html = (string) $this->renderer()->render($blocks, $mode, ['merge' => $merge]);
            $this->assertStringContainsString('Hi Pat', $html, $mode);
            $this->assertStringNotContainsString('doc-merge', $this->body($html), $mode);
        }

        // Public with no value: inert empty, not a chip. Preview falls back to a chip.
        $this->assertStringNotContainsString('doc-merge', $this->body((string) $this->renderer()->render($blocks, 'public', ['merge' => []])));
        $this->assertStringContainsString('doc-merge', (string) $this->renderer()->render($blocks, 'preview', ['merge' => []]));

        $template = (string) $this->renderer()->render($blocks, 'template_preview', ['merge' => DocumentMergeFields::sample()]);
        $this->assertStringContainsString('Hi Alex', $template);
    }

    public function test_unknown_mode_falls_back_to_preview(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([['type' => 'signature']]), 'bogus');

        $this->assertStringContainsString('data-mode="preview"', $html);
    }

    public function test_page_break_is_a_visible_separator_in_editor_and_preview_and_css_break_in_public(): void
    {
        $blocks = $this->blocks([['type' => 'page_break']]);

        foreach (['editor', 'preview', 'template_preview'] as $mode) {
            $html = (string) $this->renderer()->render($blocks, $mode);
            $this->assertStringContainsString('doc-pagebreak-marker', $html, $mode);
        }

        $public = (string) $this->renderer()->render($blocks, 'public');
        $this->assertStringNotContainsString('<div class="doc-pagebreak-marker">', $public);
        $this->assertStringContainsString('<div class="doc-page-break"></div>', $public);
        $this->assertStringContainsString('break-after:page', $public, 'print stylesheet is emitted');
    }

    public function test_signature_is_a_placeholder_outside_public_and_a_slot_in_public(): void
    {
        $blocks = $this->blocks([['type' => 'signature', 'data' => ['label' => 'Sign here']]]);
        $form = new HtmlString('<form data-test="real-form"></form>');

        foreach (['editor', 'preview', 'template_preview'] as $mode) {
            $html = (string) $this->renderer()->render($blocks, $mode, ['signature_html' => $form]);
            $this->assertStringContainsString('data-role="signature-placeholder"', $html, $mode);
            $this->assertStringNotContainsString('real-form', $html, $mode, 'the real form is public-mode only');
        }

        $public = (string) $this->renderer()->render($blocks, 'public', ['signature_html' => $form]);
        $this->assertStringContainsString('<form data-test="real-form"></form>', $public);
        $this->assertStringNotContainsString('signature-placeholder', $public);

        // A plain string is never trusted as markup.
        $asString = (string) $this->renderer()->render($blocks, 'public', ['signature_html' => '<b>nope</b>']);
        $this->assertStringNotContainsString('<b>nope</b>', $asString);
        $this->assertStringNotContainsString('signature-slot', $this->body($asString));
    }

    public function test_product_block_renders_canonical_lines_totals_and_schedule_with_formatted_money(): void
    {
        $blocks = $this->blocks([['type' => 'product_list'], ['type' => 'payment_terms']]);
        $lines = [
            (object) ['name' => 'Design <b>work</b>', 'description' => 'Initial drawings', 'quantity' => 2, 'unit_price_minor' => 25000, 'line_total_minor' => 50000],
            (object) ['name' => 'Survey', 'description' => null, 'quantity' => 1, 'unit_price_minor' => 100000, 'line_total_minor' => 100000],
        ];
        $schedule = [
            (object) ['kind' => 'deposit', 'amount_minor' => 45000, 'due_at' => null, 'status' => 'pending'],
            (object) ['kind' => 'balance', 'amount_minor' => 105000, 'due_at' => '2027-03-05T04:59:59Z', 'status' => 'paid'],
        ];

        $html = (string) $this->renderer()->render($blocks, 'preview', [
            'lines' => $lines, 'schedule' => $schedule,
            'subtotal_minor' => 150000, 'total_minor' => 150000, 'currency_code' => 'USD',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertStringContainsString('Design &lt;b&gt;work&lt;/b&gt;', $html);
        $this->assertStringContainsString('Initial drawings', $html);
        $this->assertStringContainsString('USD 250.00', $html, 'unit price');
        $this->assertStringContainsString('USD 500.00', $html, 'line total');
        $this->assertStringContainsString('USD 1,500.00', $html, 'total');
        $this->assertStringContainsString('USD 450.00', $html, 'deposit');
        $this->assertStringContainsString('Due after signing', $html);
        $this->assertStringContainsString('Due 4 March 2027', $html, 'a dated due_at renders in the Business timezone');
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringNotContainsString('minor', $html, 'minor units never appear');
        $this->assertStringNotContainsString('25000', $html);
        $this->assertStringNotContainsString('150000', $html);
    }

    public function test_deposit_and_balance_are_listed_once_whichever_block_owns_them(): void
    {
        $schedule = [
            (object) ['kind' => 'deposit', 'amount_minor' => 45000, 'due_at' => null, 'status' => 'pending'],
            (object) ['kind' => 'balance', 'amount_minor' => 105000, 'due_at' => null, 'status' => 'pending'],
        ];
        $context = [
            'lines' => [(object) ['name' => 'Booth', 'description' => null, 'quantity' => 1, 'unit_price_minor' => 150000, 'line_total_minor' => 150000]],
            'schedule' => $schedule, 'subtotal_minor' => 150000, 'total_minor' => 150000, 'currency_code' => 'USD',
        ];

        $both = (string) $this->renderer()->render($this->blocks([['type' => 'product_list'], ['type' => 'payment_terms']]), 'preview', $context);
        $this->assertSame(1, substr_count($both, 'USD 450.00'), 'with a payment_terms block the deposit is listed once');
        $this->assertStringNotContainsString('data-role="schedule-row"', $both);

        $alone = (string) $this->renderer()->render($this->blocks([['type' => 'product_list']]), 'preview', $context);
        $this->assertSame(1, substr_count($alone, 'USD 450.00'), 'without one, the product block still shows the schedule');
        $this->assertStringContainsString('data-role="schedule-row"', $alone);
    }

    public function test_balance_without_a_date_reads_after_the_deposit(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([['type' => 'product_list']]), 'preview', [
            'lines' => [(object) ['name' => 'A', 'description' => null, 'quantity' => 1, 'unit_price_minor' => 1000, 'line_total_minor' => 1000]],
            'schedule' => [
                (object) ['kind' => 'deposit', 'amount_minor' => 400, 'due_at' => null, 'status' => 'pending'],
                (object) ['kind' => 'balance', 'amount_minor' => 600, 'due_at' => null, 'status' => 'pending'],
            ],
            'total_minor' => 1000, 'subtotal_minor' => 1000, 'currency_code' => 'USD',
        ]);

        $this->assertStringContainsString('Due after signing', $html);
        $this->assertStringContainsString('Due after the deposit is paid', $html);
    }

    public function test_product_options_hide_description_and_quantity(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([['type' => 'product_list', 'data' => ['show_description' => false, 'show_quantity' => false]]]), 'preview', [
            'lines' => [(object) ['name' => 'A', 'description' => 'SECRET-DESC', 'quantity' => 7, 'unit_price_minor' => 1000, 'line_total_minor' => 7000]],
            'total_minor' => 7000, 'subtotal_minor' => 7000, 'currency_code' => 'USD',
        ]);

        $this->assertStringNotContainsString('SECRET-DESC', $html);
        $this->assertStringNotContainsString('<th class="num">Qty</th>', $html);
    }

    public function test_template_preview_renders_generic_placeholders_even_when_lines_are_supplied(): void
    {
        $blocks = $this->blocks([['type' => 'product_list'], ['type' => 'payment_terms'], ['type' => 'business_details']]);

        $html = (string) $this->renderer()->render($blocks, 'template_preview', [
            'lines' => [(object) ['name' => 'LEAKED-LINE', 'description' => null, 'quantity' => 1, 'unit_price_minor' => 1, 'line_total_minor' => 1]],
            'schedule' => [(object) ['kind' => 'full', 'amount_minor' => 1, 'due_at' => null, 'status' => 'pending']],
            'total_minor' => 1, 'currency_code' => 'USD',
            'merge' => DocumentMergeFields::sample(),
        ]);

        $this->assertStringContainsString('Product / pricing block', $html);
        $this->assertStringContainsString('data-role="payment-placeholder"', $html);
        $this->assertStringNotContainsString('LEAKED-LINE', $html);
        $this->assertStringContainsString('Your Business', $html, 'business details resolve from sample data');
    }

    public function test_product_block_without_lines_shows_the_placeholder_in_the_editor(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([['type' => 'product_list']]), 'editor');

        $this->assertStringContainsString('data-role="product-placeholder"', $html);
    }

    public function test_business_details_use_only_the_supplied_context_and_link_safe_websites(): void
    {
        $blocks = $this->blocks([['type' => 'business_details', 'data' => ['show' => ['name', 'website', 'phone']]]]);
        $html = (string) $this->renderer()->render($blocks, 'public', ['merge' => [
            'business.name' => 'Harbor <i>Lane</i>',
            'business.website' => 'https://harbor.example',
            'business.phone' => '',
            'business.email' => 'must-not-show@example.com',
        ]]);

        $this->assertStringContainsString('<strong>Harbor &lt;i&gt;Lane&lt;/i&gt;</strong>', $html);
        $this->assertStringContainsString('href="https://harbor.example"', $html);
        $this->assertStringNotContainsString('must-not-show', $html, 'only the chosen fields');

        $unsafe = (string) $this->renderer()->render($blocks, 'public', ['merge' => ['business.website' => 'javascript:alert(1)']]);
        $this->assertStringNotContainsString('href="javascript', $unsafe);
    }

    public function test_images_without_a_business_scope_render_nothing_in_public_and_a_placeholder_in_editor(): void
    {
        $blocks = $this->blocks([['type' => 'image', 'data' => ['catalog_image_uid' => '7d9a1c1e-3a4b-4c55-9b0e-2f1d6a8b9c10', 'alt' => 'A']]]);

        $this->assertStringNotContainsString('<img', (string) $this->renderer()->render($blocks, 'public'));
        $this->assertStringContainsString('doc-placeholder', (string) $this->renderer()->render($blocks, 'editor'));
        $this->assertStringNotContainsString('<img', (string) $this->renderer()->render($blocks, 'template_preview', ['business_id' => 1]));
    }

    public function test_page_markup_uses_the_printable_width_and_design_tokens(): void
    {
        $html = (string) $this->renderer()->render($this->blocks([['type' => 'text', 'data' => ['runs' => [['t' => 'x']]]]]), 'preview');

        $this->assertStringContainsString('max-width:794px', $html);
        $this->assertStringContainsString('var(--color-text-primary', $html);
    }
}
