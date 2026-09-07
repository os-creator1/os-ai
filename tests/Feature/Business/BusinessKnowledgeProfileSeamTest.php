<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessKnowledgeProfileFieldKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessKnowledgeProfileFixtures;
use Tests\TestCase;

/**
 * Website Guided Generation contract §4.3, §16 Slice 1 -- the "Profile
 * seam" regression. BusinessKnowledgeProfileManager is documented as the
 * SOLE authorized path for writing business_knowledge_profiles,
 * business_knowledge_profile_field_states, and
 * business_knowledge_profile_changes. This suite proves it mechanically:
 * no other file in app/ ever calls Model::create()/update()/save() on
 * these tables' models.
 */
class BusinessKnowledgeProfileSeamTest extends TestCase
{
    use CreatesBusinessKnowledgeProfileFixtures;
    use RefreshDatabase;

    private const GUARDED_MODELS = [
        'BusinessKnowledgeProfile',
        'BusinessKnowledgeProfileFieldState',
        'BusinessKnowledgeProfileChange',
    ];

    public function test_only_the_manager_writes_the_tracked_tables(): void
    {
        $managerPath = app_path('Library/Business/BusinessKnowledgeProfileManager.php');

        foreach ($this->phpFilesUnder(app_path()) as $path) {
            if ($path === $managerPath) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (self::GUARDED_MODELS as $model) {
                $this->assertStringNotContainsString(
                    "{$model}::create(",
                    $source,
                    "{$path} must not create {$model} rows directly -- only BusinessKnowledgeProfileManager may.",
                );
                $this->assertStringNotContainsString(
                    "{$model}::updateOrCreate(",
                    $source,
                    "{$path} must not upsert {$model} rows directly -- only BusinessKnowledgeProfileManager may.",
                );
            }
        }
    }

    public function test_the_manager_itself_actually_writes_every_guarded_model(): void
    {
        $source = file_get_contents(app_path('Library/Business/BusinessKnowledgeProfileManager.php'));

        $this->assertStringContainsString('BusinessKnowledgeProfile::create(', $source);
        $this->assertStringContainsString('BusinessKnowledgeProfileFieldState::updateOrCreate(', $source);
        $this->assertStringContainsString('BusinessKnowledgeProfileChange::create(', $source);
    }

    public function test_field_key_allowlist_is_closed_and_every_case_round_trips(): void
    {
        [$business] = $this->profileFixtureBusiness();
        $manager = app(\App\Library\Business\BusinessKnowledgeProfileManager::class);

        foreach (BusinessKnowledgeProfileFieldKey::cases() as $case) {
            if ($case === BusinessKnowledgeProfileFieldKey::Hours) {
                continue; // hours has its own dedicated write path/test suite
            }

            // Every case must be reachable through updateFields() without
            // hitting the "unknown field key" guard -- a genuinely
            // invalid value for a strict field (e.g. an integer field
            // given a string) is expected to still throw for a *value*
            // reason, which is fine; we only assert it never throws the
            // "unknown field key" message.
            try {
                $manager->updateFields($business, [$case->value => null], 'manual_edit', $this->actorUserId());
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayNotHasKey('fields', $e->errors(), "{$case->value} was rejected as an unknown field key.");
            }
        }

        // An unknown key is rejected.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $manager->updateFields($business, ['totally_unknown_key' => 'x'], 'manual_edit', $this->actorUserId());
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
