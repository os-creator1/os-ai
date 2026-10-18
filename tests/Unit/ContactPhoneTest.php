<?php

namespace Tests\Unit;

use App\Library\Contacts\ContactPhone;
use App\Library\Messaging\InboundOptOutHandler;
use PHPUnit\Framework\TestCase;

class ContactPhoneTest extends TestCase
{
    public function test_canonical_form(): void
    {
        $this->assertSame('14155551234', ContactPhone::canonical('+1 (415) 555-1234'));
        $this->assertSame('14155551234', ContactPhone::canonical('+14155551234'));
        $this->assertSame('14155551234', ContactPhone::canonical('415-555-1234', 'US'));
        $this->assertSame('14155551234', ContactPhone::canonical('(415) 555 1234', 'us'));
        $this->assertSame('442079460958', ContactPhone::canonical('+44 20 7946 0958', 'US'));
        $this->assertSame('442079460958', ContactPhone::canonical('020 7946 0958', 'GB'));
        $this->assertSame('442079460958', ContactPhone::canonical('442079460958', 'US'));
    }

    public function test_no_region_means_no_guess(): void
    {
        $this->assertSame('4155551234', ContactPhone::canonical('415-555-1234'));
        $this->assertSame('4155551234', ContactPhone::canonical('415-555-1234', 'ZZ'));
        $this->assertSame(['4155551234'], ContactPhone::candidates('415-555-1234'));
        $this->assertSame(['14155551234'], ContactPhone::candidates('+14155551234'));
    }

    public function test_candidates_include_the_historical_national_form_only_for_a_local_number(): void
    {
        $this->assertSame(['14155551234', '4155551234'], ContactPhone::candidates('+1 415 555 1234', 'US'));
        $this->assertSame(['442079460958'], ContactPhone::candidates('+44 20 7946 0958', 'US'));
        $this->assertSame([], ContactPhone::candidates('  ', 'US'));
    }

    public function test_opt_out_keywords_are_whole_message_only(): void
    {
        foreach (['STOP', 'stop', ' Stop. ', 'STOPALL', 'Unsubscribe', 'CANCEL', 'end', 'QUIT!'] as $body) {
            $this->assertTrue(InboundOptOutHandler::isOptOutMessage($body), $body);
        }
        foreach (['please stop by', 'unstoppable', 'cancel my appointment', 'START', 'yes', '', null] as $body) {
            $this->assertFalse(InboundOptOutHandler::isOptOutMessage($body), (string) $body);
        }
    }
}
