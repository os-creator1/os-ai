<?php

namespace Tests\Feature\Seo\Content;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * SEO -> Content in the sidebar is ONE leaf entry (no nested submenu); the four sections live in the top tabs. It is
 * the selected row on every Content route, with SEO expanded around it. (Replaces the Slice 0 nested-group test.)
 */
class ContentSidebarActiveStateTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    /** @return array{0: DOMElement|null, 1: DOMXPath} */
    private function contentGroup(string $html): array
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($doc);

        $group = $xpath->query("//li[@data-nav-key='seo-content']")->item(0);

        return [$group instanceof DOMElement ? $group : null, $xpath];
    }

    private function activeClassOf(?DOMElement $el): bool
    {
        return $el !== null && in_array('active', preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true);
    }

    private function listItem(DOMXPath $xpath, string $key): ?DOMElement
    {
        $li = $xpath->query("//li[@data-nav-key='" . $key . "']")->item(0);

        return $li instanceof DOMElement ? $li : null;
    }

    /** The four Content sections are the top tabs only: the sidebar shows SEO expanded with ONE selected "Content" leaf. */
    public function test_every_content_route_selects_the_single_content_leaf_and_never_a_grandchild(): void
    {
        $tenant = $this->photoBoothContentTenant(withBlueprint: true);
        $this->authenticateAsSeoCustomer($tenant[0]);

        foreach (['autopilot', 'articles.index', 'plan', 'opportunities'] as $name) {
            $html = $this->get(route('customer.workspaces.businesses.seo.content.' . $name, [$tenant[2]->uid, $tenant[1]->uid]))->assertOk()->getContent();
            [$group, $xpath] = $this->contentGroup($html);

            $this->assertNotNull($group, "the Content entry renders on {$name}");
            $this->assertSame(1, $xpath->query("//li[@data-nav-key='seo-content']")->length, 'Content exists exactly once');
            $this->assertSame(0, $xpath->query('./ul', $group)->length, "Content has no nested submenu on {$name}");
            $this->assertTrue($this->activeClassOf($group), "Content is the selected row on {$name}");

            foreach (['seo-content-autopilot', 'seo-content-articles', 'seo-content-plan', 'seo-content-opportunities'] as $gone) {
                $this->assertNull($this->listItem($xpath, $gone), "{$gone} is not in the sidebar");
            }

            $seo = $this->listItem($xpath, 'seo');
            $this->assertNotNull($seo);
            $this->assertTrue(str_contains($seo->getAttribute('class'), 'open'), "SEO stays expanded on {$name}");
            $this->assertFalse($this->activeClassOf($seo), "the SEO parent is not the selected row on {$name}");

            // The rest of the SEO group is untouched: same entries, in order, none of them selected.
            $keys = [];
            foreach ($xpath->query("./ul[contains(@class,'menu-content')]/li", $seo) as $li) {
                $keys[] = $li->getAttribute('data-nav-key');
                if ($li->getAttribute('data-nav-key') !== 'seo-content') {
                    $this->assertFalse($this->activeClassOf($li), $li->getAttribute('data-nav-key') . " is not selected on {$name}");
                }
            }
            $this->assertSame(['seo-keywords', 'seo-content', 'gbp', 'seo-citations', 'seo-reviews'], array_values(array_intersect($keys, ['seo-keywords', 'seo-content', 'gbp', 'seo-citations', 'seo-reviews'])), "SEO order on {$name}");
        }
    }
}
