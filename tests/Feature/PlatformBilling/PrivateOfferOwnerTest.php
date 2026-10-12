<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\PlatformAdminAction;
use App\Models\PlatformPrivateOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Platform Owner → Billing & Revenue → Private Offers → Create Offer → Copy Link.
 */
class PrivateOfferOwnerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Core, null, '49.00');
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $this->sellableTier(WorkspacePlanTier::Agency, null, '249.00');
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'new_subscription',
            'tier' => 'growth',
            'customer_email' => 'buyer@example.test',
            'trial_days' => '14',
            'expires_at' => now()->addDays(10)->toDateString(),
            'redemption_limit' => '1',
            'reason' => 'Agreed on a call.',
        ], $overrides);
    }

    // =================================================================
    // Authorization
    // =================================================================

    public function test_only_the_platform_owner_reaches_the_offer_screens(): void
    {
        $this->get(route('admin.platform-billing.offers.index'))->assertUnauthorized();
        $this->post(route('admin.platform-billing.offers.store'), $this->form())->assertUnauthorized();

        $this->platformAdminId();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $this->get(route('admin.platform-billing.offers.index'))->assertUnauthorized();
        $this->post(route('admin.platform-billing.offers.store'), $this->form())->assertUnauthorized();

        $this->assertSame(0, PlatformPrivateOffer::query()->count());
    }

    public function test_the_manager_refuses_a_non_administrator_even_if_called_directly(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->offers()->create(['tier' => 'growth', 'customer_email' => 'a@example.test', 'expires_in_days' => 5, 'reason' => 'nope'], (int) $customer->user_id);
    }

    // =================================================================
    // Create offer -> copy link
    // =================================================================

    public function test_the_owner_creates_an_offer_and_copies_a_unique_link(): void
    {
        $this->signInAsOwner();

        $response = $this->post(route('admin.platform-billing.offers.store'), $this->form([
            'custom_price' => '79.00',
            'discount_type' => 'percent', 'discount_value' => '20', 'discount_duration' => 'periods', 'discount_periods' => '3',
        ]));

        $offer = PlatformPrivateOffer::query()->sole();
        $response->assertRedirect(route('admin.platform-billing.offers.show', [$offer->uid]));

        $link = session('offer_link');
        $this->assertMatchesRegularExpression('#/offers/[A-Za-z0-9_-]{43}$#', $link);

        // The page shows the link and a Copy button — once.
        $show = $this->get(route('admin.platform-billing.offers.show', [$offer->uid]));
        $show->assertOk()->assertSee($link, false)->assertSee('Copy link');
        $this->get(route('admin.platform-billing.offers.show', [$offer->uid]))->assertDontSee($link, false);

        // Only the hash is stored: a database read cannot reconstruct a link.
        $token = basename($link);
        $this->assertSame(hash('sha256', $token), $offer->token_hash);
        foreach (DB::table('platform_private_offers')->get() as $row) {
            $this->assertStringNotContainsString($token, json_encode($row));
        }
        $this->assertStringNotContainsString($token, json_encode(PlatformAdminAction::query()->get()->toArray()));

        // The terms as entered.
        $this->assertSame('buyer@example.test', $offer->customer_email);
        $this->assertSame(14, $offer->trial_days);
        $this->assertSame('79.00', (string) $offer->custom_price);
        $this->assertSame('percent', $offer->discount_type);
        $this->assertSame(3, $offer->discount_periods);
        $this->assertSame('growth', $offer->catalog->tier->value);
    }

    public function test_every_link_is_unique_and_unguessable(): void
    {
        $tokens = [];

        for ($i = 0; $i < 25; $i++) {
            $tokens[] = $this->makeOffer(['customer_email' => "buyer{$i}@example.test"])['token'];
        }

        $this->assertCount(25, array_unique($tokens));

        foreach ($tokens as $token) {
            $this->assertGreaterThanOrEqual(43, strlen($token), '256 bits of entropy');
        }
    }

    public function test_the_owner_can_pick_any_of_the_three_plans(): void
    {
        foreach (['core', 'growth', 'agency'] as $tier) {
            $created = $this->makeOffer(['tier' => $tier, 'customer_email' => "{$tier}@example.test"]);

            $this->assertSame($tier, $created['offer']->catalog->tier->value);
        }

        $this->assertSame(3, PlatformPrivateOffer::query()->count());
    }

    public function test_every_creation_is_audited_with_a_reason_and_no_secret(): void
    {
        $this->signInAsOwner();

        $this->post(route('admin.platform-billing.offers.store'), $this->form());

        $action = PlatformAdminAction::query()->where('action', 'private_offer.created')->sole();
        $this->assertSame('Agreed on a call.', $action->reason);
        $this->assertSame($this->platformAdminId(), (int) $action->actor_user_id);
    }

    public function test_an_offer_can_be_assigned_to_an_existing_workspace(): void
    {
        $unpaid = $this->unpaidCustomer();

        $created = $this->makeOffer(['customer_email' => null, 'workspace_uid' => $unpaid['workspace']->uid]);

        $this->assertSame($unpaid['workspace']->id, (int) $created['offer']->target_workspace_id);
        $this->assertNull($created['offer']->customer_email);
    }

    // =================================================================
    // Validation
    // =================================================================

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): array
    {
        return [
            'no customer' => [['customer_email' => ''], 'customer_email'],
            'bad email' => [['customer_email' => 'not-an-email'], 'customer_email'],
            'unknown workspace' => [['customer_email' => '', 'workspace_uid' => 'nope'], 'workspace_uid'],
            'unknown plan' => [['tier' => 'platinum'], 'tier'],
            'trial too long' => [['trial_days' => '900'], 'trial_days'],
            'zero trial' => [['trial_days' => '0'], 'trial_days'],
            'bad price' => [['custom_price' => 'cheap'], 'custom_price'],
            'discount without a value' => [['discount_type' => 'fixed', 'discount_value' => '', 'discount_duration' => 'forever'], 'discount_value'],
            '100% discount' => [['discount_type' => 'percent', 'discount_value' => '100', 'discount_duration' => 'forever'], 'discount_value'],
            'discount without duration' => [['discount_type' => 'fixed', 'discount_value' => '5', 'discount_duration' => ''], 'discount_duration'],
            'one period of "periods"' => [['discount_type' => 'fixed', 'discount_value' => '5', 'discount_duration' => 'periods', 'discount_periods' => '1'], 'discount_periods'],
            'discount larger than the price' => [['discount_type' => 'fixed', 'discount_value' => '500', 'discount_duration' => 'forever'], 'terms'],
            'expired already' => [['expires_at' => '2020-01-01'], 'expires_at'],
            'open for years' => [['expires_at' => '2090-01-01'], 'expires_at'],
            'no reason' => [['reason' => ''], 'reason'],
            'silly limit' => [['redemption_limit' => '500'], 'redemption_limit'],
            'billing change without a workspace' => [['kind' => 'billing_change'], 'workspace_uid'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function test_invalid_offers_are_refused_and_nothing_is_stored(array $overrides, string $field): void
    {
        $this->signInAsOwner();

        $this->post(route('admin.platform-billing.offers.store'), $this->form($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, PlatformPrivateOffer::query()->count());
    }

    // =================================================================
    // View, revoke, new link, track
    // =================================================================

    public function test_the_list_and_detail_pages_track_offers(): void
    {
        $this->signInAsOwner();
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14, 'label' => 'Acme pilot']);
        $this->signInAsOwner();

        $this->get(route('admin.platform-billing.offers.index'))
            ->assertOk()
            ->assertSee('Acme pilot')
            ->assertSee('14-day free trial')
            ->assertSee('Active');

        $this->get(route('admin.platform-billing.offers.show', [$created['offer']->uid]))
            ->assertOk()
            ->assertSee('Nobody has accepted this offer yet.');

        $this->get(route('admin.platform-billing.index'))->assertOk()->assertSee('Private Offers');
    }

    public function test_the_owner_can_revoke_an_offer_and_the_link_stops_working(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAsOwner();

        $this->post(route('admin.platform-billing.offers.revoke', [$created['offer']->uid]), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertFalse($created['offer']->refresh()->isRevoked());

        $this->post(route('admin.platform-billing.offers.revoke', [$created['offer']->uid]), ['reason' => 'Deal fell through.'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($created['offer']->refresh()->isRevoked());
        $this->assertSame('revoked', $created['offer']->status());
        $this->assertNotNull(PlatformAdminAction::query()->where('action', 'private_offer.revoked')->first());

        $this->get(route('offers.show', ['token' => $created['token']]))->assertOk()->assertSee('no longer available');
    }

    public function test_a_new_link_replaces_the_old_one(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAsOwner();

        $this->post(route('admin.platform-billing.offers.new-link', [$created['offer']->uid]), ['reason' => 'Sent to the wrong person.'])
            ->assertRedirect();

        $newToken = basename(session('offer_link'));
        $this->assertNotSame($created['token'], $newToken);

        $this->get(route('offers.show', ['token' => $created['token']]))->assertNotFound();
        $this->get(route('offers.show', ['token' => $newToken]))->assertOk();
    }

    public function test_the_workspace_details_screen_exposes_offer_management(): void
    {
        $unpaid = $this->unpaidCustomer();
        $created = $this->makeOffer(['customer_email' => null, 'workspace_uid' => $unpaid['workspace']->uid, 'label' => 'Workspace deal']);
        $this->signInAsOwner();

        $page = $this->get(route('admin.workspaces.show', [$unpaid['workspace']->uid]));

        $page->assertOk()
            ->assertSee('Private offers')
            ->assertSee('Workspace deal')
            ->assertSee('Create an offer for this Workspace')
            ->assertSee(route('admin.platform-billing.offers.show', [$created['offer']->uid]), false)
            ->assertSee(route('admin.platform-billing.offers.index', ['workspace' => $unpaid['workspace']->uid]), false);

        // Prefilled from the Workspace screen.
        $this->get(route('admin.platform-billing.offers.index', ['workspace' => $unpaid['workspace']->uid]))
            ->assertOk()
            ->assertSee($unpaid['workspace']->uid);
    }
}
