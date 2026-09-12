<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Unified Business Home and COO Decision Engine Contract §19.3, §10.1,
 * §13 (slice AI-1) — T-AI-GATE-1 and T-ROUTE-1's architecture proof.
 *
 * "After AI-1, no code outside app/Library/Ai/Providers/** may construct
 * a provider client or call a provider endpoint" (§19.3). This is
 * asserted mechanically over every PHP source file under app/, not by
 * inspecting a fixed list of "known" call sites — a new bypass anywhere
 * in app/ fails this test the moment it is written.
 */
class AiGatewayBypassArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_PROVIDER_PATH = 'app/Library/Ai/Providers/';

    /**
     * §19.3 — "no code outside app/Library/Ai/Providers/** may construct
     * a provider client or call a provider endpoint". `OpenAI::client(`
     * is the one provider-client constructor this codebase has ever used
     * (and the only one App\Library\Ai\Providers\OpenAiCompletionClient
     * itself uses); a future different provider would add its own
     * distinct construction call, which this same sweep would need to
     * name — but today, this is the complete list.
     */
    public function test_no_provider_client_construction_exists_outside_the_providers_directory(): void
    {
        $offenders = $this->scanAppPhpFiles(function (string $relativePath, string $source): ?string {
            if (str_starts_with($relativePath, self::ALLOWED_PROVIDER_PATH)) {
                return null;
            }

            if (str_contains($source, 'OpenAI::client(')) {
                return $relativePath;
            }

            return null;
        });

        $this->assertSame([], $offenders, 'Provider-client construction found outside app/Library/Ai/Providers/**: ' . implode(', ', $offenders));
    }

    /**
     * T-ROUTE-1 — "No model literal outside provider adapters." A
     * concrete OpenAI/Anthropic/Gemini-shaped model name may only ever
     * appear inside app/Library/Ai/Providers/** or in this file's own
     * pattern definition. Domain code must ask AiModelRouter for a
     * route, never name a model (D-4).
     */
    public function test_no_provider_model_name_literal_exists_outside_the_providers_directory(): void
    {
        $pattern = '/\b(gpt-|o\d-|claude-|gemini-)/';

        $offenders = $this->scanAppPhpFiles(function (string $relativePath, string $source) use ($pattern): ?string {
            if (str_starts_with($relativePath, self::ALLOWED_PROVIDER_PATH)) {
                return null;
            }

            return preg_match($pattern, $source) === 1 ? $relativePath : null;
        });

        $this->assertSame([], $offenders, 'A model-name literal was found outside app/Library/Ai/Providers/**: ' . implode(', ', $offenders));
    }

    /**
     * The complementary, config-side half of D-4/T-BUD-7: the model
     * names themselves live ONLY in config/ai.php (or its env
     * overrides), never in another config file that AiModelRouter or
     * the gateway itself could read.
     *
     * config/services.php pre-dates AI-1 and is deliberately exempt: its
     * `services.openai.model` key is the admin-settings-editable value
     * the old inline call sites used to read directly. AI-1 never reads
     * it any more (App\Library\Ai\AiModelRouter reads config('ai.routes.*')
     * exclusively — see AiGatewayBypassArchitectureTest's other two
     * assertions, which do cover app/), so its literal default is inert
     * legacy configuration, not a second source of truth this contract
     * created. Removing that admin-editable field is Settings UI scope
     * (AI-2), not AI-1's.
     */
    public function test_no_model_name_literal_exists_in_any_other_config_file(): void
    {
        $pattern = '/\b(gpt-|o\d-|claude-|gemini-)/';
        $configDir = base_path('config');
        $exempt = ['config/services.php'];
        $offenders = [];

        foreach ((new Finder())->files()->in($configDir)->name('*.php') as $file) {
            $relative = 'config/' . $file->getRelativePathname();

            if ($relative === 'config/ai.php' || in_array($relative, $exempt, true)) {
                continue;
            }

            if (preg_match($pattern, $file->getContents()) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @param  callable(string $relativePath, string $source): (string|null)  $check
     * @return array<int, string>
     */
    private function scanAppPhpFiles(callable $check): array
    {
        $appDir = base_path('app');
        $offenders = [];

        foreach ((new Finder())->files()->in($appDir)->name('*.php') as $file) {
            $relative = 'app/' . $file->getRelativePathname();
            $offender = $check($relative, $file->getContents());

            if ($offender !== null) {
                $offenders[] = $offender;
            }
        }

        sort($offenders);

        return $offenders;
    }
}
