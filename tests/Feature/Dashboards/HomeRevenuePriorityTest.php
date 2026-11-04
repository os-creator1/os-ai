<?php

namespace Tests\Feature\Dashboards;

use App\Library\Dashboard\Home\HomeRevenueReader;
use Tests\TestCase;

class HomeRevenuePriorityTest extends TestCase
{
    public function test_priority_puts_money_the_owner_can_act_on_first(): void
    {
        $p = HomeRevenueReader::PRIORITY;

        $this->assertLessThan($p['signed'], $p['paid']);
        $this->assertLessThan($p['proposal_sent'], $p['signed']);
        $this->assertLessThan($p['new'], $p['proposal_sent']);
        $this->assertSame(count($p), count(array_unique($p)));
    }
}
