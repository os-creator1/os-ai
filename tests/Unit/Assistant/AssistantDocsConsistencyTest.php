<?php

namespace Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;

/**
 * The Ask MotionGrove product documents must keep telling one story about retention and about the History cursor. These are plain
 * text assertions on the documents themselves (no application boot, no database).
 */
class AssistantDocsConsistencyTest extends TestCase
{
    private function doc(string $name): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/docs/product/' . $name);
        $this->assertNotFalse($text, $name . ' is readable');

        return str_replace("\r\n", "\n", (string) $text);
    }

    /** @return array<string, array{0: string}> */
    public static function documents(): array
    {
        return [
            'ASK-MOTIONGROVE-V1.md' => ['ASK-MOTIONGROVE-V1.md'],
            'ASK-MOTIONGROVE-TRIAL-LAUNCH.md' => ['ASK-MOTIONGROVE-TRIAL-LAUNCH.md'],
            'FORGE-PRODUCTION.md' => ['FORGE-PRODUCTION.md'],
        ];
    }

    /** @dataProvider documents */
    public function test_no_document_still_presents_ninety_days_as_the_retention_default(string $name): void
    {
        $text = $this->doc($name);

        $this->assertStringContainsString('ASSISTANT_RETENTION_DAYS', $text);
        $this->assertDoesNotMatchRegularExpression('/ASSISTANT_RETENTION_DAYS=90\b/', $text, 'The default is 0, not 90.');
        $this->assertDoesNotMatchRegularExpression('/\b90-day (conversation )?retention\b/i', $text, 'No "90-day retention" as a standing policy.');
        $this->assertDoesNotMatchRegularExpression('/ASSISTANT_RETENTION_DAYS`? \(90\)/', $text);
    }

    /** @dataProvider documents */
    public function test_each_document_states_the_default_keeps_conversations_until_deletion(string $name): void
    {
        $text = $this->doc($name);

        $this->assertMatchesRegularExpression('/(default[^.\n]{0,80}\b0\b|`ASSISTANT_RETENTION_DAYS=0`|ASSISTANT_RETENTION_DAYS`? \(default 0)/i', $text);
        $this->assertMatchesRegularExpression('/until (the customer )?(delete|deletes|deleted)/i', $text);
    }

    public function test_the_launch_documents_keep_the_legal_review_requirement_accurate(): void
    {
        foreach (['ASK-MOTIONGROVE-V1.md', 'ASK-MOTIONGROVE-TRIAL-LAUNCH.md'] as $name) {
            $text = $this->doc($name);

            $this->assertMatchesRegularExpression('/sign-off|legal(ly)? review|privacy\/legal/i', $text, $name);
            $this->assertStringContainsString('positive period', $text, $name . ' covers an explicitly configured period too.');
        }
    }

    public function test_the_history_cursor_is_documented_as_the_opaque_next_value_not_a_conversation_uid(): void
    {
        $text = $this->doc('ASK-MOTIONGROVE-V1.md');

        $this->assertStringNotContainsString('[?after=<conversation uid>]', $text);
        $this->assertStringContainsString('[?after=<cursor>]', $text);
        $this->assertStringContainsString("opaque cursor returned in the previous response's `next`", $text);
        // The separate message pagination (by message uid) is still documented.
        $this->assertStringContainsString('&before=<message uid>', $text);
        $this->assertStringContainsString('page.has_more', $text);
    }
}
