<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_an_unauthenticated_visitor_sees_the_public_marketing_homepage(): void
    {
        // The root used to redirect guests to the login page. It is now the public MotionGrove site
        // (tests/Feature/Marketing); signed-in customers are still sent into the application.
        $this->get('/')->assertOk()->assertSee('More bookings.', false);
    }
}
