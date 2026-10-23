<?php

namespace Tests\Unit\Legal;

use App\Library\Legal\LegalDocument;
use App\Library\Legal\LegalRenderer;
use PHPUnit\Framework\TestCase;

class LegalRendererTest extends TestCase
{
    private function render(string $source, array $facts = []): array
    {
        return (new LegalRenderer)->render($source, $facts);
    }

    public function test_sections_get_stable_anchors_and_numbers_for_the_contents(): void
    {
        $r = $this->render("## 1. Agreement and scope\n\n1.1. First clause.\n\n## 12. Governing law & forum\n\ntext\n\n## Annex A — Processing\n\ntext\n");

        $this->assertSame(['1-agreement-and-scope', '12-governing-law-forum', 'annex-a-processing'], array_column($r['sections'], 'id'));
        $this->assertSame(['1', '12', null], array_column($r['sections'], 'number'));
        $this->assertStringContainsString('id="12-governing-law-forum"', $r['html']);
        $this->assertStringContainsString('<span class="lg-cn">1.1</span>', $r['html']);
    }

    public function test_duplicate_headings_get_distinct_anchors(): void
    {
        $r = $this->render("## Notes\n\na\n\n## Notes\n\nb\n");

        $this->assertCount(2, array_unique(array_column($r['sections'], 'id')));
    }

    public function test_raw_html_scripts_event_handlers_and_unsafe_links_are_neutralised(): void
    {
        $r = $this->render("## 1. Hostile\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))\n\n<iframe src=\"//evil\"></iframe>\n");

        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringNotContainsString('<img', $r['html']);
        $this->assertStringNotContainsString('<iframe', $r['html']);
        $this->assertStringNotContainsString('javascript:', $r['html']);
        // The attribute text can only survive as inert, escaped text, never inside a tag.
        $this->assertDoesNotMatchRegularExpression('~<[^>]*onerror~i', $r['html']);
    }

    public function test_company_facts_are_escaped_never_rendered_as_markup(): void
    {
        $r = $this->render("## 1. Contact\n\nWrite to {{legal_email}}.\n", [
            'legal_email' => ['label' => 'legal email', 'value' => '<script>alert(1)</script>**x**[l](javascript:alert(2))'],
        ]);

        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringNotContainsString('<strong>x', $r['html']);
        $this->assertStringNotContainsString('<a href="javascript', $r['html']);
        $this->assertStringContainsString('&lt;script&gt;', $r['html']);
    }

    public function test_an_unset_fact_stays_visibly_unresolved(): void
    {
        $r = $this->render("## 1. Contact\n\n{{legal_email}} and {{registered_entity}}\n", [
            'legal_email' => ['label' => 'legal notices email', 'value' => ''],
            'registered_entity' => ['label' => 'registered entity', 'value' => null],
        ]);

        $this->assertSame(['[VERIFY: legal notices email]', '[VERIFY: registered entity]'], $r['unresolved']);
        $this->assertStringContainsString('<mark class="lg-pending">[VERIFY: legal notices email]</mark>', $r['html']);
    }

    public function test_a_filled_fact_resolves_the_placeholder(): void
    {
        $r = $this->render("## 1. Contact\n\n{{legal_email}}\n", ['legal_email' => ['label' => 'x', 'value' => 'legal@example.test']]);

        $this->assertSame([], $r['unresolved']);
        $this->assertStringContainsString('legal@example.test', $r['html']);
    }

    public function test_tables_and_notices_get_their_wrappers(): void
    {
        $r = $this->render("## 1. T\n\n> **Notice** text\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n");

        $this->assertStringContainsString('class="lg-notice"', $r['html']);
        $this->assertStringContainsString('class="lg-table-wrap"', $r['html']);
    }

    public function test_the_hash_ignores_line_endings_and_trailing_whitespace_but_not_content(): void
    {
        $a = LegalDocument::hashOf("## 1. A\n\ntext\n");

        $this->assertSame($a, LegalDocument::hashOf("## 1. A\r\n\r\ntext\r\n\r\n"));
        $this->assertNotSame($a, LegalDocument::hashOf("## 1. A\n\ntext!\n"));
        $this->assertSame(64, strlen($a));
    }
}
