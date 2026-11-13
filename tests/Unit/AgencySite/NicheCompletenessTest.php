<?php

namespace Tests\Unit\AgencySite;

use App\Library\AgencySite\LegacySampleCleaner;
use App\Library\AgencySite\NichePresets;
use App\Library\AgencySite\SiteContentBuilder;
use App\Library\AgencySite\SiteContext;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The dog-business niche must leave no trace of the platform's event / photo-booth demo on ANY page, section or
 * illustration — including sections hidden by default and features not yet confirmed.
 */
class NicheCompletenessTest extends TestCase
{
    private const PAGES = ['home', 'product', 'solutions', 'how-it-works', 'pricing', 'customers'];

    private function html(string $goal, ?string $niche, string $page): string
    {
        config(['marketing.show_unverified_features' => true]);
        $context = new SiteContext('Pawsome Software', null, '#1a73e8', null, null, null, null, null, null, null, [], [], 'https://book.example/b/1', $goal, $goal,
            fn ($p) => 'https://site.example/'.($p === 'home' ? '' : $p), fn ($u) => 'https://site.example/signup/'.$u, 'https://site.example/login', 'https://site.example/css');
        $values = ['x.goal' => $goal, 'x.home_shown' => ['people', 'commitment'], 'x.testimonials' => [['customer_name' => 'Dana', 'business_name' => 'Dana Dogs', 'quote' => 'It keeps my bookings tidy.', 'approved' => true]]]
            + ($niche ? ['x.niche' => $niche] : []);
        $mg = app(SiteContentBuilder::class)->site($values, $context);

        return view('marketing.pages.'.$page, ['mg' => $mg, 'page' => $page, 'meta' => $mg->meta($page)])->render();
    }

    public function test_the_dog_preset_covers_every_illustration_data_key_with_no_event_wording(): void
    {
        $preset = NichePresets::for(NichePresets::DOG, 'Pawsome Software');

        $this->assertEqualsCanonicalizing(array_keys(config('marketing.demo')), array_keys($preset['demo']), 'every demo field has a dog value');

        foreach ($preset['demo'] as $key => $value) {
            $this->assertSame(is_array(config('marketing.demo.'.$key)) ? count(config('marketing.demo.'.$key)) : null, is_array($value) ? count($value) : null, "demo.{$key} keeps its shape");
        }

        $this->assertDoesNotMatchRegularExpression(LegacySampleCleaner::EVENT_TERMS, json_encode($preset, JSON_UNESCAPED_UNICODE));
    }

    public function test_every_demo_key_used_by_a_template_exists_in_the_platform_defaults(): void
    {
        $used = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/marketing'))) as $file) {
            if ($file->isFile() && preg_match_all("/->demo\('([a-z_.0-9]+)'\)/", (string) file_get_contents($file->getPathname()), $m)) {
                $used = array_merge($used, $m[1]);
            }
        }

        $this->assertNotEmpty($used);
        foreach (array_unique($used) as $key) {
            $this->assertNotNull(Arr::get(config('marketing.demo'), $key), "demo('{$key}') has a platform default");
        }
    }

    public function test_no_dog_business_page_contains_event_demo_content_in_any_selling_mode(): void
    {
        foreach (['both', 'book_call'] as $goal) {
            foreach (self::PAGES as $page) {
                $html = $this->html($goal, NichePresets::DOG, $page);
                $this->assertDoesNotMatchRegularExpression(LegacySampleCleaner::EVENT_TERMS, $html, "{$goal}/{$page} leaks event demo content");
                $this->assertDoesNotMatchRegularExpression('/\bevents?\b|Alex\b|DJs?\b|caterers?/iu', strip_tags(preg_replace('/<script.*?<\/script>|<style.*?<\/style>/s', '', $html)), "{$goal}/{$page}: event wording");
            }
        }
    }

    public function test_the_general_niche_keeps_the_original_event_demo(): void
    {
        $html = $this->html('both', null, 'home');
        $this->assertStringContainsString('Lumen Events', $html);
        $this->assertStringContainsString('photo booth rental near me', $html);
    }
}
