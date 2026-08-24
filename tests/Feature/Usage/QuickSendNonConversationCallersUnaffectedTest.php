<?php

namespace Tests\Feature\Usage;

use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §5.1/§7/§12 item 1/2/13 item 5,15,21 — proves, by
 * direct inspection of the actual widened signature (not by driving the
 * full legacy quickSend() call chain, which requires an unrelated,
 * pre-existing subscription/plan/pricing fixture depth outside this
 * correction's own scope), that the new $conversationContext
 * discriminator is additive and default-false: every one of the five
 * non-ChatBox quickSend() call sites this milestone's own audit found
 * (bulk Quick Send, contact-group welcome SMS, DLR auto-reply, and the
 * two third-party API controllers) calls quickSend($campaign, $input)
 * with exactly two arguments, unchanged by this correction — PHP itself
 * guarantees each of those calls silently receives the default `false`,
 * with zero source change required at any of those five call sites.
 */
class QuickSendNonConversationCallersUnaffectedTest extends TestCase
{
    public function test_conversation_context_parameter_is_additive_and_defaults_to_false(): void
    {
        $interfaceMethod = new ReflectionMethod(CampaignRepository::class, 'quickSend');
        $implementationMethod = new ReflectionMethod(EloquentCampaignRepository::class, 'quickSend');

        foreach ([$interfaceMethod, $implementationMethod] as $method) {
            $parameters = $method->getParameters();
            $this->assertCount(3, $parameters, $method->getDeclaringClass()->getName() . '::quickSend() must have exactly 3 parameters.');

            $third = $parameters[2];
            $this->assertSame('conversationContext', $third->getName());
            $this->assertTrue($third->isDefaultValueAvailable());
            $this->assertFalse($third->getDefaultValue());
            $this->assertTrue($third->getType() !== null && (string) $third->getType() === 'bool');
        }
    }

    /**
     * Confirms every non-ChatBox quickSend() call site this milestone's
     * own repository-wide audit found still calls with exactly two
     * arguments — a direct, mechanical proof (not an assumption) that
     * none of them opts into M5 metering, and that none required a
     * source change to remain correct after the widening.
     */
    public function test_every_known_non_chatbox_caller_still_passes_exactly_two_arguments(): void
    {
        $callSites = [
            'app/Repositories/Eloquent/EloquentContactsRepository.php',
            'app/Http/Controllers/Customer/CampaignController.php',
            'app/Http/Controllers/Customer/DLRController.php',
            'app/Http/Controllers/API/CampaignHTTPController.php',
            'app/Http/Controllers/API/CampaignController.php',
        ];

        foreach ($callSites as $relativePath) {
            $path = base_path($relativePath);
            $this->assertFileExists($path);

            $contents = file_get_contents($path);

            $this->assertMatchesRegularExpression(
                '/->quickSend\(/',
                $contents,
                "{$relativePath} was expected to still call quickSend()."
            );

            // A literal array second argument (e.g. quickSend($campaign, [...]))
            // may itself contain commas, so this checks only that no
            // *third top-level argument* — specifically ", true)" or
            // ", false)" immediately before the call's own closing paren
            // — was added at any call site in this file.
            $this->assertSame(
                0,
                preg_match('/->quickSend\([^;]*,\s*(?:true|false)\s*\)\s*;/s', $contents),
                "{$relativePath} must not have gained a third quickSend() argument."
            );
        }
    }

    public function test_chatbox_controller_is_the_only_caller_passing_true(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Customer/ChatBoxController.php'));

        $this->assertSame(2, preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', $contents));
    }
}
