<?php

namespace Tests\Feature\Seo\Content;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Content Autopilot — Slice 0. The Content group in the sidebar is a neutral, expanded parent: only the selected
 * child carries the active/accent state. (A parent `li.active` paints the whole group, submenu included, as one
 * accent block.)
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

    public function test_only_the_selected_content_child_is_active_and_the_parent_stays_neutral(): void
    {
        $tenant = $this->photoBoothContentTenant(withBlueprint: true);
        $this->authenticateAsSeoCustomer($tenant[0]);

        foreach (['articles.index' => '/seo/content/articles', 'opportunities' => '/seo/content/opportunities', 'plan' => '/seo/content'] as $name => $needle) {
            $html = $this->get(route('customer.workspaces.businesses.seo.content.' . $name, [$tenant[2]->uid, $tenant[1]->uid]))->assertOk()->getContent();
            [$group, $xpath] = $this->contentGroup($html);

            $this->assertNotNull($group, "the Content group renders on {$name}");
            $this->assertFalse($this->activeClassOf($group), "the Content parent is never the selected row on {$name}");

            $items = $xpath->query("./ul[contains(@class,'menu-content')]/li", $group);
            $this->assertGreaterThanOrEqual(2, $items->length, 'Content has child entries');

            $active = [];
            foreach ($items as $li) {
                if ($this->activeClassOf($li)) {
                    $active[] = $xpath->query('./a', $li)->item(0)->getAttribute('href');
                }
            }

            $this->assertCount(1, $active, "exactly one Content child is active on {$name}");
            $this->assertStringEndsWith($needle, $active[0]);
            $this->assertTrue(str_contains($group->getAttribute('class'), 'open'), "the Content group stays expanded on {$name}");
        }
    }
}
