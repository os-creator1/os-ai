<?php

namespace Tests\Unit\Navigation;

use App\Library\Navigation\PageHeadingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shell prints its own page heading only when the page does not already render one. */
class PageHeadingResolverTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'page h1 owns the heading' => ['<h1 class="cx-page-title">Forms</h1>', 'Forms', null, true],
            'any h1, even with a different text' => ['<h1>Growth Center</h1>', 'Growth Center — Advisor', null, true],
            'h2 equal to the title (entities decoded)' => ['<h2>Search &amp; keywords</h2>', 'Search & keywords', null, true],
            'h4 equal to the title' => ['<div><h4> Content </h4></div>', 'Content', null, true],
            'card heading with another text keeps the shell heading' => ['<div class="card"><h4>Details</h4></div>', 'Forms', null, false],
            'modal heading never counts' => ['<div class="modal fade"><h2>Forms</h2></div>', 'Forms', null, false],
            'offcanvas and template headings never count' => ['<div class="offcanvas"><h1>Forms</h1></div><template><h1>Forms</h1></template>', 'Forms', null, false],
            'visually hidden h1 does not count' => ['<h1 class="visually-hidden">Conversations</h1>', 'Conversations', null, false],
            'no heading at all keeps the shell heading' => ['<p>Hello</p>', 'Calendar', null, false],
            'explicit own' => ['<p>x</p>', 'Calendar', 'own', true],
            'explicit shared wins over a detected heading' => ['<h1>Calendar</h1>', 'Calendar', 'shared', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_detects_a_page_owned_heading(string $content, string $title, ?string $explicit, bool $expected): void
    {
        $this->assertSame($expected, PageHeadingResolver::ownsHeading($content, $title, $explicit));
    }
}
