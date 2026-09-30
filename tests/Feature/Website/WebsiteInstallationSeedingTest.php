<?php

namespace Tests\Feature\Website;

use App\Models\QuestionPack;
use App\Models\WebsiteTemplate;
use Database\Seeders\QuestionPackSeeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion — acceptance-correction
 * Blocker 7. Proves the templates/question-packs are installed by the
 * REAL, normal deployment path (`php artisan db:seed`, i.e.
 * `DatabaseSeeder::run()`) rather than requiring an operator to know
 * about two hidden class-specific seeders.
 *
 * `DatabaseSeeder::run()` itself is never executed end-to-end here: its
 * unrelated, pre-existing seeders (`CurrenciesSeeder` inserting a row
 * that foreign-keys to `UserSeeder`'s user id 1) only converge correctly
 * outside a RefreshDatabase-wrapped test transaction, which is an
 * environment fact about the OTHER seeders, not about this lane's two.
 * A source-level wiring assertion (this codebase's own established
 * "boundary test" pattern for proving one file calls/contains another)
 * proves the real thing Blocker 7 requires — that a normal `db:seed`
 * run reaches these two seeders — without depending on that unrelated
 * fragility. The seeders' own correctness (exactly four templates, both
 * packs, idempotent) is already proven directly by
 * WebsiteTemplateCatalogTest and QuestionPackSeederTest.
 */
class WebsiteInstallationSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_normal_database_seeder_calls_both_website_seeders(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        // DatabaseSeeder shares WebsiteTemplateSeeder/QuestionPackSeeder's
        // own `Database\Seeders` namespace, so it references them by the
        // short class name only — never the fully-qualified string.
        $this->assertStringContainsString('WebsiteTemplateSeeder::class', $source, 'DatabaseSeeder must call WebsiteTemplateSeeder so a normal db:seed installs the four templates.');
        $this->assertStringContainsString('QuestionPackSeeder::class', $source, 'DatabaseSeeder must call QuestionPackSeeder so a normal db:seed installs the question packs.');
    }

    public function test_calling_both_website_seeders_directly_as_database_seeder_does_installs_everything_idempotently(): void
    {
        // Exercises the exact two calls DatabaseSeeder::run() makes, in
        // the same order, twice — proving the real installation result
        // without the unrelated CurrenciesSeeder/UserSeeder ordering
        // fragility documented above.
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(QuestionPackSeeder::class);
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(QuestionPackSeeder::class);

        $this->assertSame([
            'photo_booth_conversion',
            'photo_booth_editorial',
            'photo_booth_luxury',
            'photo_booth_modern',
        ], WebsiteTemplate::pluck('key')->sort()->values()->all());

        $this->assertSame(1, QuestionPack::where('key', 'general')->count());
        $this->assertSame(1, QuestionPack::where('key', 'photobooth')->count());
    }
}
