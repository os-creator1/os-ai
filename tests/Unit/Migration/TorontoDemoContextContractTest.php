<?php

namespace Tests\Unit\Migration;

use App\Library\Demo\BrightFrame\DemoContext;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the production command read `$ctx->owner`, but DemoContext exposes `$user`, which crashed the website step
 * after the account and CRM data were already imported. Every property the Toronto code reads from a DemoContext must exist.
 */
class TorontoDemoContextContractTest extends TestCase
{
    private function sources(): array
    {
        $root = __DIR__.'/../../..';

        return [
            'command' => $root.'/app/Console/Commands/TorontoProductionProvisionCommand.php',
            'provisioner' => $root.'/app/Library/Migration/Toronto/TorontoProvisioner.php',
            'website builder' => $root.'/app/Library/Migration/Toronto/TorontoWebsiteBuilder.php',
        ];
    }

    public function test_every_context_property_read_exists_on_demo_context(): void
    {
        $reflection = new \ReflectionClass(DemoContext::class);
        foreach ($this->sources() as $label => $file) {
            preg_match_all('/\$(?:ctx|context)->(\w+)/', (string) file_get_contents($file), $m);
            if ($label === 'command') {
                $this->assertNotEmpty($m[1], 'command: no DemoContext reads found (pattern out of date?)');
            }
            foreach (array_unique($m[1]) as $property) {
                $this->assertTrue($reflection->hasProperty($property) || $reflection->hasMethod($property), "{$label} reads DemoContext::\${$property}, which does not exist.");
            }
        }
    }

    public function test_the_command_passes_the_context_user_to_the_website_builder(): void
    {
        $source = (string) file_get_contents($this->sources()['command']);
        $this->assertStringContainsString('(int) $ctx->user->id', $source);
        $this->assertStringNotContainsString('$ctx->owner', $source);
    }
}
