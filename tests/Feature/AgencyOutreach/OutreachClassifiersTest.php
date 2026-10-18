<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Library\AgencyOutreach\OutreachClassification;
use App\Library\AgencyOutreach\OutreachDecision;
use App\Library\AgencyOutreach\OutreachIntentClassifier;
use App\Library\AgencyOutreach\OutreachStageMachine;
use App\Library\AgencyOutreach\OutreachStopClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contract §4/§5/§9 — the three deterministic pieces: stop classification, question
 * intent, and the pure stage machine. No database, no model.
 */
class OutreachClassifiersTest extends TestCase
{
    public static function stopCases(): array
    {
        return [
            'STOP' => ['STOP', 'opt_out'],
            'lower stop with punctuation' => ['stop!', 'opt_out'],
            'stop all' => ['Stop all', 'opt_out'],
            'unsubscribe' => ['UNSUBSCRIBE', 'opt_out'],
            'remove me' => ['please remove me from this', 'opt_out'],
            'do not text' => ['Do not text me again', 'opt_out'],
            'dont text' => ["don't text me", 'opt_out'],
            'curly apostrophe' => ["Don\u{2019}t text me", 'opt_out'],
            'leave me alone' => ['Leave me alone', 'opt_out'],
            'wrong number' => ['wrong number', 'opt_out'],
            'cancel' => ['cancel', 'opt_out'],
            'quit' => ['Quit', 'opt_out'],
            'end' => ['end', 'opt_out'],
            'please stop' => ['please stop texting me', 'opt_out'],
            'not interested' => ['Not interested', 'rejected_hard'],
            'not interested thanks' => ['not interested, thanks', 'rejected_hard'],
            'no thanks' => ['No thanks', 'rejected_soft'],
            'nah' => ['nah', 'rejected_soft'],
            'im good' => ["I'm good", 'rejected_soft'],
            'not now' => ['Not now', 'rejected_soft'],
            'maybe later' => ['maybe later', 'rejected_soft'],
            'already have' => ['we already have someone', 'rejected_soft'],
            'nonstop is not stop' => ['we are busy nonstop', 'none'],
            'unstoppable is not stop' => ['we are unstoppable', 'none'],
            'weekend is not end' => ['what about the weekend', 'none'],
            'bystop' => ['bus stopper', 'none'],
            'end inside a sentence' => ['what is the end goal here', 'none'],
            'cancel inside a sentence' => ['can I cancel my other booking first', 'none'],
            'quit inside a sentence' => ['I might quit my job soon', 'none'],
            'stop by is not stop' => ['can you stop by tomorrow', 'none'],
            'stop leading with context' => ["Stop, I'm busy", 'opt_out'],
            'ordinary reply' => ['yes sure tell me more', 'none'],
            'empty' => ['', 'none'],
        ];
    }

    #[DataProvider('stopCases')]
    public function test_stop_classification(string $body, string $expected): void
    {
        $this->assertSame($expected, OutreachStopClassifier::classify($body));
    }

    public static function intentCases(): array
    {
        return [
            ['How much does it cost?', 'pricing'],
            ['what is the price', 'pricing'],
            ['Is there a commission?', 'pricing'],
            ['Are you guys based in Dallas?', 'location'],
            ['where are you located', 'location'],
            ['How did you find me?', 'found_you'],
            ['where did you get my number', 'found_you'],
            ['Do you have a website?', 'website'],
            ['whats your url', 'website'],
            ['Who is this?', 'name'],
            ["what's your company name", 'name'],
            ['What do you do?', 'what_we_do'],
            ['how does this work', 'what_we_do'],
            ['Is this for a wedding on Saturday?', 'clarify'],
            ['Are you free this weekend?', 'clarify'],
            ['Do you take payment by cheque or card or what', 'question_other'],
            ['Can you do the accounting too?', 'question_other'],
            ['ok', 'none'],
            ['Sure', 'none'],
            ['yes tell me', 'none'],
        ];
    }

    #[DataProvider('intentCases')]
    public function test_intent_classification(string $body, string $expected): void
    {
        $this->assertSame($expected, OutreachIntentClassifier::classify($body));
    }

    public function test_scheduling_replies_are_recognised(): void
    {
        foreach (['tomorrow at 3', 'does Tuesday work', 'can we do 2pm', 'what time', 'how about 10:30', 'I am free monday'] as $text) {
            $this->assertTrue(OutreachIntentClassifier::isSchedulingReply($text), $text);
        }

        foreach (['ok', 'sounds good', 'what do you do', ''] as $text) {
            $this->assertFalse(OutreachIntentClassifier::isSchedulingReply($text), $text);
        }
    }

    // ---------------------------------------------------------------- stage machine

    private function decide(int $stage, string $body, bool $linkRepeatUsed = false): OutreachDecision
    {
        return OutreachStageMachine::decide($stage, OutreachClassification::of($body, $linkRepeatUsed));
    }

    public function test_the_stage_sequence_1_to_4_with_the_right_message_each_step(): void
    {
        $one = $this->decide(1, 'yes');
        $this->assertSame([OutreachDecision::SEND_STAGE, 1, 2, 1, false], [$one->action, $one->stageFrom, $one->stageTo, $one->messageNumber, $one->scheduleFollowUp]);

        $two = $this->decide(2, 'sure');
        $this->assertSame([OutreachDecision::SEND_STAGE, 2, 3, 2, false], [$two->action, $two->stageFrom, $two->stageTo, $two->messageNumber, $two->scheduleFollowUp]);

        $three = $this->decide(3, 'ok');
        $this->assertSame([OutreachDecision::SEND_STAGE, 3, 4, 3, true], [$three->action, $three->stageFrom, $three->stageTo, $three->messageNumber, $three->scheduleFollowUp]);
    }

    public function test_a_faq_is_answered_first_in_every_scripted_stage(): void
    {
        foreach ([1, 2, 3] as $stage) {
            $this->assertTrue($this->decide($stage, 'How much is it?')->answerFirst, "stage {$stage}");
            $this->assertFalse($this->decide($stage, 'ok')->answerFirst, "stage {$stage}");
        }
    }

    public function test_the_booking_stage_has_no_scripted_message(): void
    {
        $this->assertSame(OutreachDecision::NONE, $this->decide(4, 'ok thanks')->action);
        $this->assertSame('no_scripted_reply', $this->decide(4, 'ok thanks')->reason);

        $faq = $this->decide(4, 'How much does it cost?');
        $this->assertSame(OutreachDecision::ANSWER_ONLY, $faq->action);
        $this->assertNull($faq->messageNumber);
        $this->assertSame(4, $faq->stageTo);
    }

    public function test_a_scheduling_reply_in_the_booking_stage_gets_the_link_once(): void
    {
        $first = $this->decide(4, 'tomorrow at 3pm works');
        $this->assertSame(OutreachDecision::RESEND_LINK, $first->action);
        $this->assertSame(3, $first->messageNumber);
        $this->assertSame([4, 4], [$first->stageFrom, $first->stageTo]);

        $again = $this->decide(4, 'tomorrow at 3pm works', true);
        $this->assertSame(OutreachDecision::NONE, $again->action, 'The link is repeated once, never twice.');
    }

    public function test_terminal_and_reserved_stages_never_reply(): void
    {
        foreach ([6, 99] as $stage) {
            $this->assertSame(OutreachDecision::NONE, $this->decide($stage, 'How much?')->action);
            $this->assertSame(OutreachDecision::NONE, $this->decide($stage, 'STOP')->action, 'A terminal member is never re-processed.');
        }

        $this->assertSame(OutreachDecision::NONE, $this->decide(5, 'hello')->action);
        $this->assertSame(OutreachDecision::NONE, OutreachStageMachine::decide(AgencyProspectStage::Booked, new OutreachClassification())->action);
    }

    public function test_opt_out_and_hard_rejection_stop_at_every_live_stage(): void
    {
        foreach ([1, 2, 3, 4, 5] as $stage) {
            $out = $this->decide($stage, 'STOP');
            $this->assertSame([OutreachDecision::OPT_OUT, 99], [$out->action, $out->stageTo], "stage {$stage}");

            $no = $this->decide($stage, 'not interested');
            $this->assertSame([OutreachDecision::REJECT, 99], [$no->action, $no->stageTo], "stage {$stage}");
        }
    }

    public function test_a_soft_no_ends_the_conversation_only_once_message_1_has_been_sent(): void
    {
        $this->assertSame(OutreachDecision::SEND_STAGE, $this->decide(1, 'no thanks')->action, 'Before message 1 a soft no is an ordinary reply.');

        foreach ([2, 3, 4] as $stage) {
            $this->assertSame(OutreachDecision::REJECT, $this->decide($stage, 'no thanks')->action, "stage {$stage}");
        }
    }

    public function test_the_machine_is_exhaustive_over_every_stage_and_stop_class(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 99] as $stage) {
            foreach ([OutreachStopClassifier::NONE, OutreachStopClassifier::OPT_OUT, OutreachStopClassifier::REJECTED_HARD, OutreachStopClassifier::REJECTED_SOFT] as $stop) {
                foreach ([OutreachIntentClassifier::NONE, OutreachIntentClassifier::PRICING, OutreachIntentClassifier::QUESTION_OTHER] as $intent) {
                    $decision = OutreachStageMachine::decide($stage, new OutreachClassification($stop, $intent));

                    $this->assertContains($decision->action, [
                        OutreachDecision::SEND_STAGE, OutreachDecision::RESEND_LINK, OutreachDecision::ANSWER_ONLY,
                        OutreachDecision::OPT_OUT, OutreachDecision::REJECT, OutreachDecision::NONE,
                    ]);
                    $this->assertGreaterThanOrEqual($stage === 99 ? 99 : $stage, $decision->stageTo, 'Never moves backwards.');
                }
            }
        }
    }
}
