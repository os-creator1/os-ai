<?php

namespace Tests\Unit\Website;

use App\Library\Website\GuidedGeneration\GenerationFailureMessage;
use PHPUnit\Framework\TestCase;

/**
 * Website V1 final — a failed generation never leaks internals to the
 * customer: only sentences written for customers pass through.
 */
class GenerationFailureMessageTest extends TestCase
{
    public function test_internal_failure_reasons_become_one_calm_message(): void
    {
        foreach ([
            'Commit failed: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
            'Generation did not produce a valid page batch.',
            'Page "service:abc" is missing required section "hero".',
            '',
            null,
        ] as $internal) {
            $message = GenerationFailureMessage::forCustomer($internal);

            $this->assertSame(GenerationFailureMessage::GENERIC, $message);
            $this->assertStringNotContainsString('SQL', $message);
            $this->assertStringContainsString('Your answers are saved', $message);
        }
    }

    public function test_sentences_already_written_for_customers_pass_through(): void
    {
        foreach ([
            'The included AI generation budget is used up for this period.',
            "Website generation isn't available in this environment right now. Your answers are saved — try again once it is.",
            'This generation was superseded while it was running.',
        ] as $safe) {
            $this->assertSame($safe, GenerationFailureMessage::forCustomer($safe));
        }
    }
}
