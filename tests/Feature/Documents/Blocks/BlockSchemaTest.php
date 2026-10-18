<?php

namespace Tests\Feature\Documents\Blocks;

use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Library\Documents\Blocks\BlockSchema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract 17B §2 — BlockSchema accept / reject matrix. Pure validation: no
 * database is touched.
 */
class BlockSchemaTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function validBlocks(): array
    {
        $imageUid = '7d9a1c1e-3a4b-4c55-9b0e-2f1d6a8b9c10';

        return [
            'heading' => [['type' => 'heading', 'data' => ['level' => 1, 'align' => 'center', 'runs' => [['t' => 'Hello', 'b' => true]]]]],
            'text with link and merge' => [['type' => 'text', 'data' => ['align' => 'left', 'runs' => [
                ['t' => 'Hi '], ['merge' => 'contact.first_name'], ['t' => ' see ', 'i' => true, 'u' => true, 'href' => 'https://example.com/a?b=1'],
                ['t' => 'mail', 'href' => 'mailto:hello@example.com'], ['t' => 'call', 'href' => 'tel:+14155550100'],
            ]]]],
            'image' => [['type' => 'image', 'data' => ['catalog_image_uid' => $imageUid, 'alt' => 'Kitchen', 'width_pct' => 60]]],
            'divider' => [['type' => 'divider']],
            'spacer' => [['type' => 'spacer', 'data' => ['height' => 40]]],
            'page_break' => [['type' => 'page_break']],
            'section' => [['type' => 'section', 'data' => ['title' => 'Scope of work']]],
            'business_details' => [['type' => 'business_details', 'data' => ['show' => ['name', 'email']]]],
            'product_list' => [['type' => 'product_list', 'data' => ['show_description' => false, 'show_quantity' => true]]],
            'payment_terms' => [['type' => 'payment_terms']],
            'signature' => [['type' => 'signature', 'data' => ['label' => 'Client signature']]],
        ];
    }

    /**
     * @dataProvider validBlocks
     *
     * @param  array<string, mixed>  $block
     */
    public function test_every_block_type_is_accepted_and_normalised(array $block): void
    {
        $out = BlockSchema::normalize([$block]);

        $this->assertCount(1, $out);
        $this->assertSame($block['type'], $out[0]['type']);
        $this->assertTrue(Str::isUuid($out[0]['id']), 'a missing id is generated');
        $this->assertIsArray($out[0]['data']);
    }

    public function test_telephone_href_with_a_space_is_rejected_but_plain_digits_pass(): void
    {
        $this->assertFalse(BlockSchema::isSafeHref('tel:+1 4155550100'));
        $this->assertTrue(BlockSchema::isSafeHref('tel:+14155550100'));
    }

    public function test_defaults_are_filled_in(): void
    {
        $out = BlockSchema::normalize([
            ['type' => 'heading', 'data' => ['runs' => [['t' => 'x']]]],
            ['type' => 'spacer'],
            ['type' => 'product_list'],
            ['type' => 'signature'],
            ['type' => 'business_details'],
        ]);

        $this->assertSame(2, $out[0]['data']['level']);
        $this->assertSame('left', $out[0]['data']['align']);
        $this->assertSame(24, $out[1]['data']['height']);
        $this->assertSame(['show_description' => true, 'show_quantity' => true], $out[2]['data']);
        $this->assertSame('Signature', $out[3]['data']['label']);
        $this->assertSame(['name', 'phone', 'email', 'website'], $out[4]['data']['show']);
    }

    public function test_document_wrapper_carries_the_schema_version(): void
    {
        $doc = BlockSchema::document([['type' => 'divider']]);

        $this->assertSame(2, $doc['schema_version']);
        $this->assertCount(1, $doc['blocks']);
    }

    public function test_unknown_keys_are_dropped_by_default_and_rejected_when_strict(): void
    {
        $out = BlockSchema::normalize([['type' => 'text', 'evil' => 'x', 'data' => ['runs' => [['t' => 'a', 'onclick' => 'x']], 'style' => 'x']]]);

        $this->assertSame(['id', 'type', 'data'], array_keys($out[0]));
        $this->assertSame(['align', 'runs'], array_keys($out[0]['data']));
        $this->assertSame(['t'], array_keys($out[0]['data']['runs'][0]));

        $this->assertRejected([['type' => 'text', 'evil' => 'x', 'data' => ['runs' => []]]], 'blocks.0.evil', ['strict_keys' => true]);
        $this->assertRejected([['type' => 'text', 'data' => ['runs' => [['t' => 'a', 'onclick' => 'x']]]]], 'blocks.0.data.runs.0.onclick', ['strict_keys' => true]);
    }

    public function test_rejection_matrix(): void
    {
        $this->assertRejected([['type' => 'script', 'data' => []]], 'blocks.0.type');
        $this->assertRejected([['data' => []]], 'blocks.0.type');
        $this->assertRejected(['not-a-block'], 'blocks.0');
        $this->assertRejected('nope', 'blocks');
        $this->assertRejected(['a' => ['type' => 'divider']], 'blocks');
        $this->assertRejected([['type' => 'heading', 'data' => ['level' => 4, 'runs' => []]]], 'blocks.0.data.level');
        $this->assertRejected([['type' => 'heading', 'data' => ['level' => 'x', 'runs' => []]]], 'blocks.0.data.level');
        $this->assertRejected([['type' => 'text', 'data' => ['align' => 'justify', 'runs' => []]]], 'blocks.0.data.align');
        $this->assertRejected([['type' => 'spacer', 'data' => ['height' => 7]]], 'blocks.0.data.height');
        $this->assertRejected([['type' => 'spacer', 'data' => ['height' => 121]]], 'blocks.0.data.height');
        $this->assertRejected([['type' => 'text', 'data' => ['runs' => [['merge' => 'contact.password']]]]], 'blocks.0.data.runs.0.merge');
        $this->assertRejected([['type' => 'text', 'data' => ['runs' => [['merge' => '{{ 1+1 }}']]]]], 'blocks.0.data.runs.0.merge');
        $this->assertRejected([['type' => 'business_details', 'data' => ['show' => ['name', 'ssn']]]], 'blocks.0.data.show');
        $this->assertRejected([['type' => 'image', 'data' => ['catalog_image_uid' => 'not-a-uuid']]], 'blocks.0.data.catalog_image_uid');
        $this->assertRejected([['type' => 'image', 'data' => ['catalog_image_uid' => '7d9a1c1e-3a4b-4c55-9b0e-2f1d6a8b9c10', 'width_pct' => 5]]], 'blocks.0.data.width_pct');
        $this->assertRejected([['type' => 'text', 'data' => ['runs' => 'abc']]], 'blocks.0.data.runs');
        $this->assertRejected([['type' => 'text', 'data' => 'abc']], 'blocks.0.data');
        $this->assertRejected([['type' => 'section', 'data' => ['title' => str_repeat('a', 201)]]], 'blocks.0.data.title');
        $this->assertRejected([['type' => 'divider', 'data' => ['x' => 1]]], 'blocks.0.data.x', ['strict_keys' => true]);
    }

    /**
     * @dataProvider hostileHrefs
     */
    public function test_unsafe_hrefs_are_rejected(string $href): void
    {
        $this->assertFalse(BlockSchema::isSafeHref($href));
        $this->assertRejected([['type' => 'text', 'data' => ['runs' => [['t' => 'x', 'href' => $href]]]]], 'blocks.0.data.runs.0.href');
    }

    /** @return array<string, array{0: string}> */
    public static function hostileHrefs(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
            'javascript with tab' => ["java\tscript:alert(1)"],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'protocol relative' => ['//evil.example/x'],
            'relative' => ['/etc/passwd'],
            'file' => ['file:///etc/passwd'],
            'http without host' => ['http://'],
            'leading space scheme' => [' javascript:alert(1)'],
            'backslash' => ['https:\\\\evil.example'],
            'newline' => ["https://example.com/\nx"],
            'bad mailto' => ['mailto:not-an-email'],        ];
    }

    public function test_oversize_href_is_rejected(): void
    {
        $this->assertFalse(BlockSchema::isSafeHref('https://example.com/' . str_repeat('a', BlockSchema::MAX_HREF)));
    }

    public function test_limits(): void
    {
        $this->assertSame(200, count(BlockSchema::normalize(array_fill(0, 200, ['type' => 'divider']))));
        $this->assertRejected(array_fill(0, 201, ['type' => 'divider']), 'blocks');

        $this->assertRejected([['type' => 'text', 'data' => ['runs' => [['t' => str_repeat('a', 2001)]]]]], 'blocks.0.data.runs.0.t');
        BlockSchema::normalize([['type' => 'text', 'data' => ['runs' => [['t' => str_repeat('a', 2000)]]]]]);

        // 200 blocks of near-max text is far above the 200 KB document cap.
        $big = array_fill(0, 120, ['type' => 'text', 'data' => ['runs' => [['t' => str_repeat('a', 2000)]]]]);
        $this->assertRejected($big, 'blocks');
    }

    public function test_duplicate_ids_are_rejected_and_valid_ids_kept(): void
    {
        $this->assertRejected([['id' => 'a', 'type' => 'divider'], ['id' => 'a', 'type' => 'divider']], 'blocks.1.id');
        $this->assertRejected([['id' => 'bad id!', 'type' => 'divider']], 'blocks.0.id');

        $out = BlockSchema::normalize([['id' => 'keep-me_1', 'type' => 'divider']]);
        $this->assertSame('keep-me_1', $out[0]['id']);
    }

    public function test_only_one_product_block_and_one_signature_block(): void
    {
        $this->assertRejected([['type' => 'product_list'], ['type' => 'product_list']], 'blocks.1.type');
        $this->assertRejected([['type' => 'signature'], ['type' => 'signature']], 'blocks.1.type');
    }

    public function test_assert_sendable_requires_exactly_one_signature_when_signing_is_required(): void
    {
        BlockSchema::assertSendable(BlockSchema::normalize([['type' => 'signature']]), true);
        BlockSchema::assertSendable(BlockSchema::normalize([['type' => 'divider']]), false);

        $this->expectException(InvalidDocumentBlocksException::class);
        BlockSchema::assertSendable(BlockSchema::normalize([['type' => 'divider']]), true);
    }

    public function test_images_can_be_forbidden_and_ownership_is_checked(): void
    {
        $uid = '7d9a1c1e-3a4b-4c55-9b0e-2f1d6a8b9c10';
        $block = [['type' => 'image', 'data' => ['catalog_image_uid' => $uid]]];

        $this->assertRejected($block, 'blocks.0.data', ['allow_images' => false]);
        $this->assertRejected($block, 'blocks.0.data.catalog_image_uid', ['image_owned' => fn () => false]);

        $seen = [];
        BlockSchema::normalize($block, ['image_owned' => function ($u) use (&$seen) {
            $seen[] = $u;

            return true;
        }]);
        $this->assertSame([$uid], $seen);
    }

    public function test_script_markup_is_stored_as_inert_text_not_executed_or_rejected(): void
    {
        $xss = '<script>alert(1)</script><img src=x onerror=alert(1)>';
        $out = BlockSchema::normalize([['type' => 'text', 'data' => ['runs' => [['t' => $xss]]]]]);

        // Stored verbatim as TEXT; the renderer is the escaping boundary.
        $this->assertSame($xss, $out[0]['data']['runs'][0]['t']);
    }

    public function test_control_characters_are_stripped_from_prose(): void
    {
        $out = BlockSchema::normalize([['type' => 'text', 'data' => ['runs' => [['t' => "a\x00b\x07c\nd\te"]]]]]);

        $this->assertSame("abc\nd\te", $out[0]['data']['runs'][0]['t']);
    }

    public function test_validation_error_carries_one_message_per_path_and_does_not_echo_values(): void
    {
        try {
            BlockSchema::normalize([['type' => 'text', 'data' => ['runs' => [['t' => 'x', 'href' => 'javascript:evil()']]]], ['type' => 'nope']]);
            $this->fail('expected exception');
        } catch (InvalidDocumentBlocksException $e) {
            $this->assertArrayHasKey('blocks.0.data.runs.0.href', $e->errors());
            $this->assertArrayHasKey('blocks.1.type', $e->errors());
            $this->assertStringNotContainsString('evil', implode(' ', $e->errors()));
        }
    }

    /**
     * @param  array<string, mixed>|mixed  $blocks
     * @param  array<string, mixed>  $options
     */
    private function assertRejected(mixed $blocks, string $path, array $options = []): void
    {
        try {
            BlockSchema::normalize($blocks, $options);
        } catch (InvalidDocumentBlocksException $e) {
            $this->assertArrayHasKey($path, $e->errors(), 'errors were: ' . json_encode($e->errors()));

            return;
        }

        $this->fail("Expected rejection at {$path}.");
    }
}
