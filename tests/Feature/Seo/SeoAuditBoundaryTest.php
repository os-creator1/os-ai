<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Seo\SeoAuditRuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18G §12/§15.G/§16 — the STRUCTURAL guarantees of the
 * Website SEO audit, proved by reading the source rather than by exercising
 * it: no write path to any Website table, no URL fetch, no crawler, no
 * provider, no AI, no auto-fix — and the whole surface still fail-closed
 * while both SEO features are Planned.
 */
class SeoAuditBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    /** Every file Sub-slice 18G adds or drives. */
    private const AUDIT_FILES = [
        'app/Library/Seo/SeoAuditRuleRegistry.php',
        'app/Library/Seo/SeoAuditEvaluator.php',
        'app/Library/Seo/SeoAuditFindingDraft.php',
        'app/Library/Seo/SeoAuditRunner.php',
        'app/Library/Seo/SeoAuditPageReader.php',
        'app/Library/Seo/SeoAuditPage.php',
        'app/Library/Seo/SeoAuditFindingView.php',
        'app/Jobs/Seo/RunSeoAuditForRevision.php',
        'app/Listeners/Seo/QueueSeoAuditOnWebsitePublished.php',
        'app/Http/Controllers/Customer/Business/SeoAuditController.php',
        'app/Models/SeoAuditRun.php',
        'app/Models/SeoAuditFinding.php',
        'resources/views/customer/business/seo/audit.blade.php',
    ];

    /** @return array<string, array{0: string}> */
    public static function auditFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $cases = [];

        foreach (self::AUDIT_FILES as $relative) {
            $cases[basename($relative)] = [$root . '/' . $relative];
        }

        return $cases;
    }

    // -----------------------------------------------------------------
    // T-SEO-BOUND — no Website write, no fetch, no provider, no AI.
    // -----------------------------------------------------------------

    #[DataProvider('auditFiles')]
    public function test_no_audit_file_can_write_a_website_table(string $file): void
    {
        $code = $this->codeWithoutComments($file);
        $this->assertNotSame('', $code);

        // The four protected Website tables and their models (§12.1), in any
        // write shape: Eloquent, query builder or raw.
        foreach (['Website', 'WebsitePage', 'WebsiteRevision', 'WebsiteAsset'] as $model) {
            $this->assertSame(
                0,
                preg_match('/\b' . $model . '::(create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate|destroy)\b/', $code),
                basename($file) . " must never write via {$model}."
            );
        }

        foreach (['websites', 'website_pages', 'website_revisions', 'website_assets'] as $table) {
            $this->assertSame(
                0,
                preg_match('/DB::table\(\s*[\x27"]' . $table . '[\x27"]\s*\)\s*->\s*(insert|update|delete|upsert|increment|decrement|truncate)/', $code),
                basename($file) . " must never write the {$table} table."
            );
        }

        // The Website write seams are off limits entirely (§12.3): no auto-fix,
        // no draft mutation, no publish.
        $this->assertSame(0, preg_match('/WebsiteDraftPageService/', $code), basename($file) . ' must not reach the Website draft-write seam.');
        $this->assertSame(0, preg_match('/WebsitePublisher/', $code), basename($file) . ' must not publish.');
    }

    #[DataProvider('auditFiles')]
    public function test_no_audit_file_fetches_a_url_crawls_or_calls_a_provider_or_ai(string $file): void
    {
        $code = $this->codeWithoutComments($file);

        $forbidden = [
            // §8.7 — never crawls, never fetches a URL.
            '/\bHttp::/', '/GuzzleHttp/', '/\bcurl_/', '/file_get_contents\s*\(\s*\$/', '/fopen\s*\(\s*\x27https?:/',
            '/\bSocialite\b/', '/DomCrawler/', '/simplexml_load_file/', '/\bget_headers\b/',
            // No SEO/Google provider.
            '/GoogleBusinessProfileReadClient/', '/SearchConsole/i', '/GoogleClient/',
            // §12.3 — Slice 18 ships no LLM call and no AI-generated content.
            '/OpenAI/i', '/Anthropic/i', '/App\\\\Library\\\\Ai\\\\/', '/AiGateway/',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), basename($file) . ' must not match ' . $pattern . ' (Contract 18 §8.7/§12).');
        }
    }

    public function test_the_runner_is_the_only_writer_and_writes_only_its_own_two_tables(): void
    {
        $root = dirname(__DIR__, 3);
        $code = $this->codeWithoutComments($root . '/app/Library/Seo/SeoAuditRunner.php');

        // Every Eloquent entry point it uses, and the models behind them.
        preg_match_all('/\b([A-Z]\w+)::(?:query|create|insert|upsert|updateOrCreate|firstOrCreate)\b/', $code, $matches);

        $this->assertEqualsCanonicalizing(
            ['SeoAuditRun', 'SeoAuditFinding', 'Website', 'WebsiteRevision'],
            array_values(array_unique($matches[1])),
            'The runner may only reach these models at all.'
        );

        // ...and of those, only the two SEO tables may be WRITTEN.
        $this->assertSame(1, preg_match('/SeoAuditRun::query\(\)->create\(/', $code));
        $this->assertSame(1, preg_match('/SeoAuditFinding::query\(\)->insert\(/', $code));
        $this->assertSame(0, preg_match('/\bWebsite(Revision)?::(create|insert|upsert|updateOrCreate|firstOrCreate|destroy)\b/', $code));
        $this->assertSame(0, preg_match('/\$(website|revision)->(save|update|fill|forceFill|delete)\s*\(/', $code));

        // Its only delete is SEO history pruning, by id, on its own table.
        $this->assertSame(1, preg_match('/SeoAuditRun::query\(\)->whereIn\(\x27id\x27, \$doomed\)->delete\(\)/', $code));
    }

    public function test_every_other_audit_class_is_structurally_incapable_of_writing_anything(): void
    {
        $root = dirname(__DIR__, 3);

        // These are NOT excluded from SeoFoundationBoundaryTest's blanket
        // no-write scan; this asserts the same thing locally and explicitly.
        foreach ([
            'app/Library/Seo/SeoAuditRuleRegistry.php',
            'app/Library/Seo/SeoAuditEvaluator.php',
            'app/Library/Seo/SeoAuditFindingDraft.php',
            'app/Library/Seo/SeoAuditPageReader.php',
        ] as $relative) {
            $code = $this->codeWithoutComments($root . '/' . $relative);

            $this->assertSame(0, preg_match('/->(save|update|delete|forceDelete|insert|create|firstOrCreate|updateOrCreate|upsert|increment|decrement|truncate)\s*\(/', $code), $relative . ' must not write.');
            $this->assertSame(0, preg_match('/::(create|insert|upsert|updateOrCreate|firstOrCreate|destroy|forceCreate)\s*\(/', $code), $relative . ' must not write.');
            $this->assertSame(0, preg_match('/DB::(insert|update|delete|statement|unprepared)\b/', $code), $relative . ' must not write.');
        }
    }

    public function test_the_evaluator_is_pure_and_has_no_database_clock_or_randomness(): void
    {
        $code = $this->codeWithoutComments(dirname(__DIR__, 3) . '/app/Library/Seo/SeoAuditEvaluator.php');

        foreach (['/\bDB::/', '/\bCache::/', '/\bnow\s*\(/', '/\bCarbon\b/', '/\brand\s*\(/', '/\bmt_rand\b/', '/\buniqid\b/', '/\btime\s*\(/'] as $pattern) {
            $this->assertSame(0, preg_match($pattern, $code), 'The evaluator must stay pure and deterministic: ' . $pattern);
        }
    }

    // -----------------------------------------------------------------
    // Registry-owned text and the closed rule set.
    // -----------------------------------------------------------------

    public function test_the_rule_set_is_exactly_the_eight_contracted_rules(): void
    {
        $this->assertSame([
            'seo_title_blank',
            'seo_title_over_recommended',
            'meta_description_blank',
            'meta_description_short',
            'duplicate_seo_title',
            'duplicate_meta_description',
            'page_marked_noindex',
            'asset_missing_alt',
        ], SeoAuditRuleRegistry::ruleKeys(), 'Contract 18 §8.7 fixes the v1 rule inventory exactly.');
    }

    public function test_every_rule_template_uses_only_that_rules_declared_facts(): void
    {
        foreach (SeoAuditRuleRegistry::ruleKeys() as $key) {
            // describe() with no facts leaves any unsatisfied placeholder in
            // place, which would prove a template referenced something the
            // rule never declares.
            $rendered = SeoAuditRuleRegistry::describe($key, []);
            $this->assertNotSame('', $rendered);

            preg_match_all('/\{(\w+)\}/', $rendered, $leftover);

            foreach ($leftover[1] as $placeholder) {
                $this->assertNotEmpty(
                    SeoAuditRuleRegistry::validateFacts($key, [$placeholder => 1]),
                    "Rule [{$key}]'s template references [{$placeholder}], which it does not declare as a fact."
                );
            }
        }
    }

    public function test_the_finding_model_stores_no_prose_column(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('seo_audit_findings');

        $this->assertEqualsCanonicalizing(
            ['id', 'seo_audit_run_id', 'page_uid', 'rule_key', 'severity', 'facts', 'created_at'],
            $columns,
            'A finding row must have nowhere to put a sentence.'
        );
    }

    // -----------------------------------------------------------------
    // Fail-closed while Planned (T-SEO-TEN-*, T-SEO-LOCK-1).
    // -----------------------------------------------------------------

    public function test_both_seo_features_remain_planned(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value));
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoModule->value));
    }

    public function test_the_audit_routes_are_404_for_a_fully_permitted_owner_while_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer, $business, $workspace] = $this->entitledTenant($tier);
            $this->publishWebsite($business, [$this->snapshotPage('p1', 'Home')]);
            $this->authenticateAsSeoCustomer($customer);

            $this->get(route('customer.workspaces.businesses.seo.audit.index', [$workspace->uid, $business->uid]))->assertNotFound();
            $this->post(route('customer.workspaces.businesses.seo.audit.rerun', [$workspace->uid, $business->uid]))->assertNotFound();
        }
    }

    public function test_a_foreign_business_is_404_through_the_audit_routes(): void
    {
        [$mine, , $myWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        [, $theirBusiness, $theirWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $this->authenticateAsSeoCustomer($mine);

        $this->get(route('customer.workspaces.businesses.seo.audit.index', [$theirWorkspace->uid, $theirBusiness->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.audit.index', [$myWorkspace->uid, $theirBusiness->uid]))->assertNotFound();
    }

    public function test_the_audit_routes_are_get_and_post_only_and_correctly_named(): void
    {
        $names = ['customer.workspaces.businesses.seo.audit.index', 'customer.workspaces.businesses.seo.audit.rerun'];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "[{$name}] must exist.");
            $this->assertEmpty(
                array_diff($route->methods(), ['GET', 'HEAD', 'POST']),
                "[{$name}] must be GET or POST only: the audit reports, it never deletes."
            );
            // T-SEO-NAMING-2 — nothing SEO may live under the legacy namespace.
            $this->assertStringStartsNotWith('customer.keywords.', $name);
        }
    }

    public function test_no_audit_route_is_on_the_locked_account_allowlist(): void
    {
        $allowlist = $this->lockedAllowlist();

        $this->assertNotEmpty($allowlist, 'The Locked allowlist must be readable for this assertion to mean anything.');

        foreach ($allowlist as $name) {
            $this->assertStringNotContainsString('seo', $name, 'Contract 18 §10.6: nothing SEO is added to the Locked allowlist.');
        }
    }

    /** @return array<int, string> */
    private function lockedAllowlist(): array
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\CustomerAccountAccessGate::class);

        foreach ($reflection->getConstants() as $name => $value) {
            if (is_array($value) && str_contains(strtolower($name), 'allow')) {
                return array_map('strval', $value);
            }
        }

        return [];
    }

    private function codeWithoutComments(string $path): string
    {
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        if (str_ends_with($path, '.blade.php')) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        }

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
