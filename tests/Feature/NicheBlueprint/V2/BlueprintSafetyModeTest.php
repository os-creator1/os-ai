<?php

namespace Tests\Feature\NicheBlueprint\V2;

use App\Library\GoogleAds\GoogleAdsHttpTransport;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\NicheBlueprint\Safety\BlueprintMode;
use App\Library\NicheBlueprint\Safety\BlueprintModeRefusedException;
use App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard;
use App\Library\Payments\PaymentManager;
use App\Library\Payments\StripeConnectManager;
use App\Library\Website\WebsitePublisher;
use App\Models\Business;
use App\Models\Website;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Blueprint Safety Mode: the Workspace must NEVER reach the outside world.
 */
class BlueprintSafetyModeTest extends TestCase
{
    private function mode(): BlueprintMode
    {
        return app(BlueprintMode::class);
    }

    protected function tearDown(): void
    {
        $this->mode()->leave();
        $this->mode()->leave();

        parent::tearDown();
    }

    public function test_the_guard_is_a_no_op_outside_blueprint_mode(): void
    {
        foreach (BlueprintSafetyGuard::ACTIONS as $action) {
            BlueprintSafetyGuard::check($action);
        }

        $this->assertFalse($this->mode()->active());
    }

    public function test_every_named_action_is_refused_inside_blueprint_mode(): void
    {
        $this->mode()->enter();

        foreach (BlueprintSafetyGuard::ACTIONS as $action) {
            try {
                BlueprintSafetyGuard::check($action);
                $this->fail("{$action} must be refused.");
            } catch (BlueprintModeRefusedException $e) {
                $this->assertSame($action, $e->action);
            }
        }
    }

    public function test_the_mode_is_depth_counted_and_always_released(): void
    {
        $this->mode()->enter();
        $this->mode()->enter();
        $this->mode()->leave();
        $this->assertTrue($this->mode()->active());
        $this->mode()->leave();
        $this->assertFalse($this->mode()->active());

        try {
            $this->mode()->within(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->mode()->active(), 'within() leaves the mode even when the callback throws.');
    }

    public function test_real_email_is_refused_in_blueprint_mode(): void
    {
        $this->mode()->enter();

        $this->expectException(BlueprintModeRefusedException::class);

        Event::dispatch(new MessageSending((new Email())->from('a@example.test')->to('b@example.test')->text('hi')));
    }

    public function test_real_notifications_are_refused_in_blueprint_mode(): void
    {
        $this->mode()->enter();

        $this->expectException(BlueprintModeRefusedException::class);

        Event::dispatch(new NotificationSending(new Business(), new class extends \Illuminate\Notifications\Notification {}, 'mail'));
    }

    public function test_provider_http_calls_are_refused_in_blueprint_mode(): void
    {
        Http::fake();
        $this->mode()->enter();

        $this->expectException(BlueprintModeRefusedException::class);

        Http::get('https://api.example-provider.test/v1/things');
    }

    public function test_http_still_works_outside_blueprint_mode(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://api.example-provider.test/v1/things')->json('ok'));
    }

    public function test_domain_choke_points_refuse_inside_blueprint_mode(): void
    {
        $this->mode()->enter();

        $business = new Business();
        $make = fn (string $class) => (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $calls = [
            'sms_send' => fn () => $make(ManagedMessageDispatcher::class)->dispatch($business, '+15555550100', 'hi', 'op-key'),
            'payment' => fn () => $make(PaymentManager::class)->start($make(\App\Library\Documents\PublicDocumentAccess::class)),
            'provider_oauth' => fn () => $make(StripeConnectManager::class)->connect(1, $business, 'https://x.test/r', 'https://x.test/b'),
            'website_publish' => fn () => $make(WebsitePublisher::class)->publish(new Website(), 1),
            'booking' => fn () => $make(\App\Library\Calendar\AppointmentBookingService::class)->book(new \App\Models\BookingType(), 1, 1, now()),
            'ads_mutation' => fn () => $make(GoogleAdsHttpTransport::class)->mutate($make(\App\DTO\GoogleAds\GoogleAdsAccessContext::class), 'campaigns', []),
        ];

        foreach ($calls as $action => $call) {
            try {
                $call();
                $this->fail("{$action} must be refused inside Blueprint mode.");
            } catch (BlueprintModeRefusedException $e) {
                $this->assertSame($action, $e->action);
            }
        }
    }

    public function test_mail_is_unaffected_outside_blueprint_mode(): void
    {
        Mail::fake();

        Mail::raw('hello', fn ($m) => $m->to('someone@example.test'));

        $this->assertFalse($this->mode()->active());
    }
}
