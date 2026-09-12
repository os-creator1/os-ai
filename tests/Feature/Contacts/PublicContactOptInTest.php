<?php

namespace Tests\Feature\Contacts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerBasedPricingPlan;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The public contact opt-in page (contacts.subscribe_url): anyone with the
 * link may open it, signed in or not, so it has to render for whichever
 * customer owns the group.
 *
 * Its country coverage keeps the precedence it always had — the customer's
 * own configured coverage first, the actively subscribed legacy Ultimate SMS
 * plan's coverage second — with one correction: the legacy plan id is read
 * only when there is an active Subscription to read it from. A Business OS
 * customer on Core, Growth or Agency need not hold one (PR #260), and the
 * page used to fatal on `null->plan_id` before it even looked at step one.
 *
 * Collecting contacts is not a right to send to them: where no coverage is
 * configured, none is invented here.
 */
class PublicContactOptInTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    // -----------------------------------------------------------------
    // No legacy SMS subscription: the page renders instead of fataling
    // -----------------------------------------------------------------

    /**
     * Every current Business OS tier, each with no legacy Subscription at
     * all — the state that used to 500 the public page.
     */
    public function test_the_opt_in_page_renders_for_a_business_os_customer_with_no_legacy_subscription(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, $business] = $this->tenant($tier, 'Tier ' . $tier->value, 'Account ' . $tier->value);
            $group = $this->group($business, 'Newsletter ' . $tier->value);

            $this->assertNull($owner->activeSubscription(), 'Fixture precondition: no legacy SMS subscription.');

            $page = $this->optInPage($group);

            $page->assertOk();
            $this->assertNull($page->exception, $tier->value . ' must not raise an exception on the public opt-in page.');
            $page->assertSee('Newsletter ' . $tier->value);
        }
    }

    /**
     * The page is public: a guest opens it, and nothing about this change
     * put an authentication requirement in front of it.
     */
    public function test_the_opt_in_page_stays_public_and_fails_closed_on_an_unknown_group(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Newsletter');

        $this->assertGuest();
        $this->optInPage($group)->assertOk();

        // The route contract for an unknown or malformed uid is unchanged and
        // not this slice's to change: route-model binding raises
        // ModelNotFoundException, which this application's own Handler::render()
        // turns into the 500 error page outside the local environment — for
        // every bound route, not just this one. What matters here is that it
        // stays fail-closed: no group is named, no form is rendered, and a
        // public page is never turned into a login redirect.
        foreach (['no-such-group', '../../etc/passwd'] as $unknown) {
            $missing = $this->get(route('contacts.subscribe_url', $unknown));

            // A uid that binds to nothing raises ModelNotFoundException, which
            // this Handler renders as 500; one that does not even match the
            // route is a plain 404. Either way nothing is disclosed.
            $this->assertContains($missing->getStatusCode(), [404, 500], "[{$unknown}] must not be served.");
            $missing->assertDontSee('Newsletter');
            $this->assertFalse($missing->isRedirect(), 'A public page must not become a redirect to sign in.');
        }

        $this->assertInstanceOf(
            ModelNotFoundException::class,
            $this->get(route('contacts.subscribe_url', 'no-such-group'))->exception
        );
    }

    // -----------------------------------------------------------------
    // Coverage precedence
    // -----------------------------------------------------------------

    /**
     * Step 1 is unchanged and still wins: a customer with their own active
     * coverage gets it, even when a legacy subscription with different
     * coverage also exists.
     */
    public function test_customer_specific_coverage_is_still_preferred_over_the_legacy_plan(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Newsletter');

        $own = $this->country('Ownland', 'OW', '370');
        $legacy = $this->country('Legacyland', 'LG', '371');
        $plan = $this->legacyPlan($owner);
        $this->subscribe($owner, $plan);

        $mine = CustomerBasedPricingPlan::create(['user_id' => $owner->user->id, 'country_id' => $own->id, 'plan_id' => $plan->id, 'status' => true]);
        PlansCoverageCountries::create(['plan_id' => $plan->id, 'country_id' => $legacy->id, 'status' => true]);

        $coverage = $this->coverageOf($this->optInPage($group)->assertOk());

        $this->assertSame([$mine->id], $coverage->pluck('id')->all());
        $this->assertSame([$own->id], $coverage->pluck('country_id')->all());
    }

    /**
     * Step 2 is unchanged: with no coverage of their own, an actively
     * subscribed customer still falls back to their legacy plan's coverage.
     */
    public function test_the_legacy_plan_coverage_fallback_still_works(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Newsletter');

        $country = $this->country('Legacyland', 'LG', '371');
        $plan = $this->legacyPlan($owner);
        $this->subscribe($owner, $plan);
        $row = PlansCoverageCountries::create(['plan_id' => $plan->id, 'country_id' => $country->id, 'status' => true]);

        $coverage = $this->coverageOf($this->optInPage($group)->assertOk());

        $this->assertSame([$row->id], $coverage->pluck('id')->all());
        $this->assertInstanceOf(PlansCoverageCountries::class, $coverage->first());
    }

    /**
     * Step 3: neither source exists, so the page states no coverage at all.
     * It does not reach for a plan the customer is not subscribed to, and
     * does not quietly hand out every configured country.
     */
    public function test_no_coverage_is_invented_when_there_is_neither_customer_nor_legacy_coverage(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Newsletter');

        // A plan with coverage exists in the system — this customer is simply
        // not subscribed to it.
        $somebodyElsesPlan = $this->legacyPlan($owner, 'Unsubscribed Plan');
        PlansCoverageCountries::create(['plan_id' => $somebodyElsesPlan->id, 'country_id' => $this->country('Elsewhere', 'EL', '372')->id, 'status' => true]);

        $this->assertNull($owner->activeSubscription());

        $coverage = $this->coverageOf($this->optInPage($group)->assertOk());

        $this->assertTrue($coverage->isEmpty(), 'No coverage may be invented for a customer who has none.');
    }

    /**
     * A public link exposes one group's form and its owner's coverage only.
     */
    public function test_another_customers_coverage_cannot_leak_into_this_public_form(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $group = $this->group($business, 'Harbor Newsletter');

        [$neighbour, $neighbourBusiness] = $this->tenant(WorkspacePlanTier::Growth, 'Neighbour Co', 'Neighbour');
        $neighbourGroup = $this->group($neighbourBusiness, 'Neighbour Newsletter');
        $neighbourCountry = $this->country('Neighbourland', 'NB', '373');
        $neighbourPlan = $this->legacyPlan($neighbour);
        $this->subscribe($neighbour, $neighbourPlan);
        $neighbourRow = CustomerBasedPricingPlan::create(['user_id' => $neighbour->user->id, 'country_id' => $neighbourCountry->id, 'plan_id' => $neighbourPlan->id, 'status' => true]);
        PlansCoverageCountries::create(['plan_id' => $neighbourPlan->id, 'country_id' => $neighbourCountry->id, 'status' => true]);

        $page = $this->optInPage($group)->assertOk();

        $this->assertTrue($this->coverageOf($page)->isEmpty(), "The neighbour's coverage must not reach this page.");
        $page->assertSee('Harbor Newsletter');
        $page->assertDontSee('Neighbour Newsletter');
        $page->assertDontSee('Neighbourland');

        // And the neighbour's own link still shows only their own coverage.
        $this->assertSame([$neighbourRow->id], $this->coverageOf($this->optInPage($neighbourGroup)->assertOk())->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // Submitting the form
    // -----------------------------------------------------------------

    /**
     * The opt-in itself is unchanged, and the page it returns to — the same
     * public page — renders for a customer with no legacy subscription.
     */
    public function test_opting_in_still_works_and_returns_to_a_page_that_renders(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $group = $this->group($business, 'Newsletter');
        $this->assertNull($owner->activeSubscription());

        // The opt-in form is reCaptcha-gated by configuration; that gate is
        // not what this change touches, and a test cannot solve it. Turning it
        // off here exercises the part that matters — the opt-in itself.
        config(['no-captcha.registration' => false]);

        $optIn = route('contacts.subscribe_url', $group->uid);

        $this->post($optIn, ['PHONE' => '12025550143'])->assertRedirect($optIn);

        $this->assertDatabaseHas('contacts', [
            'group_id' => $group->id,
            'business_id' => $business->id,
            'phone' => '12025550143',
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        $back = $this->get($optIn);
        $back->assertOk();
        $this->assertNull($back->exception);
    }

    // -----------------------------------------------------------------

    private function optInPage(ContactGroups $group): TestResponse
    {
        return $this->get(route('contacts.subscribe_url', $group->uid));
    }

    /**
     * What the page was actually given as coverage.
     */
    private function coverageOf(TestResponse $page): Collection
    {
        $coverage = $page->original->getData()['coverage'] ?? null;

        $this->assertInstanceOf(Collection::class, $coverage, 'The opt-in page must receive a coverage collection.');

        return $coverage;
    }

    private function group(Business $business, string $name): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => $name,
            'status' => true,
        ]);
    }

    private function country(string $name, string $iso, string $code): Country
    {
        return Country::create(['name' => $name, 'iso_code' => $iso, 'country_code' => $code, 'status' => true]);
    }

    /**
     * A legacy Ultimate SMS plan row. Creating one does not subscribe
     * anybody to it — that is what subscribe() is for.
     */
    private function legacyPlan(Customer $customer, string $name = 'Legacy SMS Plan'): Plan
    {
        $currency = Currency::query()->where('code', 'POT')->first()
            ?? Currency::create(['name' => 'Opt-in Test Dollar', 'code' => 'POT', 'format' => '$', 'status' => true]);

        return Plan::create([
            'user_id' => $customer->user->id,
            'name' => $name,
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
            'options' => json_encode([]),
            'status' => true,
        ]);
    }

    private function subscribe(Customer $customer, Plan $plan): void
    {
        Subscription::create([
            'user_id' => $customer->user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);
    }
}
