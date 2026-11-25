<?php

namespace Tests\Unit\BusinessEmail;

use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Library\BusinessEmail\EmailBlockRenderer;
use PHPUnit\Framework\TestCase;

final class EmailBlockRendererTest extends TestCase
{
    public function test_shared_blocks_render_inline_email_html_and_plain_text(): void
    {
        $renderer = new EmailBlockRenderer;
        $blocks = [
            ['id' => 'heading', 'type' => 'heading', 'data' => [
                'level' => 2, 'align' => 'center', 'runs' => [['t' => 'Welcome']],
            ]],
            ['id' => 'body', 'type' => 'text', 'data' => ['runs' => [
                ['t' => 'Hello '],
                ['merge' => 'contact.first_name', 'b' => true],
                ['t' => "\n"],
                ['t' => 'Book now', 'href' => 'https://example.com/book?a=1&b=2'],
            ]]],
            ['id' => 'divider', 'type' => 'divider', 'data' => []],
            ['id' => 'spacer', 'type' => 'spacer', 'data' => ['height' => 24]],
            ['id' => 'section', 'type' => 'section', 'data' => ['title' => 'More']],
        ];

        $result = $renderer->render($blocks, function (string $token): string {
            self::assertSame('contact.first_name', $token);

            return '<Alex>';
        });

        self::assertStringContainsString('role="presentation"', $result['html']);
        self::assertStringContainsString('max-width:600px', $result['html']);
        self::assertStringContainsString('text-align:center', $result['html']);
        self::assertStringContainsString('<b>&lt;Alex&gt;</b>', $result['html']);
        self::assertStringContainsString('href="https://example.com/book?a=1&amp;b=2"', $result['html']);
        self::assertStringContainsString('height="24"', $result['html']);
        self::assertStringNotContainsString('<Alex>', $result['html']);
        self::assertSame("Welcome\n\nHello <Alex>\nBook now (https://example.com/book?a=1&b=2)\n\nMore", $result['text']);
    }

    public function test_user_markup_is_escaped(): void
    {
        $result = (new EmailBlockRenderer)->render([
            ['id' => 'body', 'type' => 'text', 'data' => ['runs' => [
                ['t' => '<img src=x onerror=alert(1)>'],
            ]]],
        ], static fn (string $token): string => '');

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $result['html']);
        self::assertStringNotContainsString('<img', $result['html']);
    }

    public function test_unsafe_links_are_rejected(): void
    {
        $this->expectException(InvalidDocumentBlocksException::class);

        (new EmailBlockRenderer)->render([
            ['id' => 'body', 'type' => 'text', 'data' => ['runs' => [
                ['t' => 'Click', 'href' => 'javascript:alert(1)'],
            ]]],
        ], static fn (string $token): string => '');
    }

    public function test_contract_signature_block_is_rejected(): void
    {
        $this->expectException(InvalidDocumentBlocksException::class);

        (new EmailBlockRenderer)->normalize([
            ['id' => 'signature', 'type' => 'signature', 'data' => []],
        ]);
    }

    public function test_document_only_merge_field_is_rejected(): void
    {
        $this->expectException(InvalidDocumentBlocksException::class);

        (new EmailBlockRenderer)->normalize([
            ['id' => 'body', 'type' => 'text', 'data' => ['runs' => [
                ['merge' => 'document.title'],
            ]]],
        ]);
    }

    public function test_arbitrary_images_are_not_accepted_before_media_ownership_is_supported(): void
    {
        $this->expectException(InvalidDocumentBlocksException::class);

        (new EmailBlockRenderer)->normalize([
            ['id' => 'image', 'type' => 'image', 'data' => [
                'catalog_image_uid' => '5ae88981-5060-4ca6-94fc-3ed690ea3804',
            ]],
        ]);
    }
}
