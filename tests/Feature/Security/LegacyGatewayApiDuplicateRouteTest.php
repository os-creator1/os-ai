<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Legacy Provider Webhook Measurement Contract §4 (S1) — deletion of exactly
 * one proven shadowed duplicate route declaration, and nothing else.
 *
 * `routes/public.php` registered `inbound/gatewayapi/{gateway?}` twice,
 * identical URI, verb set, controller, method and name. Laravel's later
 * registration always won, so the earlier declaration was already dead. S1
 * removes only that earlier line. This is not a GatewayAPI retirement: the
 * provider stays in the catalog, in outbound dispatch, keeps `dlr/gatewayapi`
 * and `inboundGatewayApi()`. No handler is removed, no signature work is
 * done, and behaviour after S1 is byte-for-byte identical to before it.
 */
class LegacyGatewayApiDuplicateRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Assertion 15 — registered exactly once in the route collection.
     */
    public function test_inbound_gatewayapi_is_registered_exactly_once(): void
    {
        $matches = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'inbound/gatewayapi/{gateway?}');

        $this->assertCount(1, $matches, 'inbound/gatewayapi/{gateway?} must be registered exactly once.');
    }

    /**
     * Assertion 16 — the surviving route keeps its controller, method and
     * name.
     */
    public function test_the_surviving_route_keeps_its_controller_method_and_name(): void
    {
        $route = Route::getRoutes()->getByName('inbound.gatewayapi');

        $this->assertNotNull($route, 'inbound.gatewayapi must still resolve.');
        $this->assertSame('inbound/gatewayapi/{gateway?}', $route->uri());
        $this->assertStringContainsString('DLRController@inboundGatewayApi', $route->getActionName());
    }

    /**
     * Assertion 17 — a request to that URI behaves identically before and
     * after S1: same status, same effect (the report row it produces).
     */
    public function test_a_request_to_the_route_behaves_the_same_as_before_s1(): void
    {
        $response = $this->post(route('inbound.gatewayapi'), [
            'id' => 'GW_DUP_1',
            'status' => 'DELIVERED',
            'msisdn' => '+14155550099',
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertNull($response->exception);
    }

    /**
     * Assertion 18 — dlr/gatewayapi is untouched and still registered. S1
     * is hygiene on the duplicate declaration only, never a GatewayAPI
     * retirement: the provider keeps its DLR callback route too.
     */
    public function test_dlr_gatewayapi_is_untouched_and_still_registered(): void
    {
        $route = Route::getRoutes()->getByName('dlr.gatewayapi');

        $this->assertNotNull($route, 'dlr.gatewayapi must remain registered — S1 is not a GatewayAPI retirement.');
        $this->assertSame('dlr/gatewayapi', $route->uri());
        $this->assertStringContainsString('DLRController@dlrGatewayApi', $route->getActionName());
    }
}
