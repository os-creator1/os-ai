<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Business\BusinessLocaleOptions;
use App\Models\Business;
use App\Models\Currency;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Business forms ask for a country, a timezone and a currency by
 * choosing from canonical values — never by typing a code. Covers the three
 * forms that share the fields: Create Business (account page), the
 * onboarding Business step and the Business profile.
 */
class BusinessLocaleFieldsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const FIELDS = ['country_code', 'timezone', 'currency_code'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['EUR', 'EURO'], ['USD', 'US Dollar'], ['CAD', 'Canadian Dollar'], ['ZAR', 'South African Rand'], ['ILS', 'Israeli New Shekel']] as [$code, $name]) {
            Currency::create(['name' => $name, 'code' => $code, 'format' => '{PRICE}', 'status' => true]);
        }

        Currency::create(['name' => 'British Pound', 'code' => 'GBP', 'format' => '£{PRICE}', 'status' => false]);
    }

    public function test_create_business_asks_for_country_timezone_and_currency_with_selects_not_typed_codes(): void
    {
        $response = $this->createBusinessForm();

        foreach (self::FIELDS as $field) {
            $this->assertMatchesRegularExpression('/<select\s[^>]*name="' . $field . '"[^>]*required/', $response->getContent(), "{$field} must be a required select.");
            $this->assertDoesNotMatchRegularExpression('/<input\s[^>]*name="' . $field . '"/', $response->getContent(), "{$field} must not be a text input.");
        }

        $response->assertSee('<label for="country_code" class="form-label text-label">Country</label>', false);
        $response->assertSee('<label for="timezone" class="form-label text-label">Timezone</label>', false);
        $response->assertSee('<label for="currency_code" class="form-label text-label">Currency</label>', false);
        $response->assertDontSee('Country code');
        $response->assertDontSee('Currency code');
    }

    public function test_options_use_friendly_labels_over_canonical_values(): void
    {
        $html = $this->createBusinessForm()->getContent();

        $this->assertOffers($html, 'LT', 'Lithuania (LT)');
        $this->assertOffers($html, 'US', 'United States (US)');
        $this->assertOffers($html, 'CA', 'Canada (CA)');
        $this->assertOffers($html, 'Europe/Vilnius', 'Europe/Vilnius');
        $this->assertOffers($html, 'America/Los_Angeles', 'America/Los_Angeles');
        $this->assertOffers($html, 'America/New_York', 'America/New_York');
        $this->assertOffers($html, 'EUR', 'EUR — Euro');
        $this->assertOffers($html, 'USD', 'USD — US Dollar');
        $this->assertOffers($html, 'CAD', 'CAD — Canadian Dollar');
    }

    /**
     * The form can only submit what it offers, so an arbitrary string can no
     * longer reach the server through it: every offered value is canonical
     * and accepted by the unchanged backend rules, and nothing else is offered.
     */
    public function test_every_offered_value_is_canonical_and_nothing_arbitrary_is_offered(): void
    {
        $html = $this->createBusinessForm()->getContent();

        $countries = $this->optionValues($html, 'country_code');
        $timezones = $this->optionValues($html, 'timezone');
        $currencies = $this->optionValues($html, 'currency_code');

        $this->assertGreaterThan(200, count($countries));
        foreach ($countries as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
        foreach (['EU', 'UN', 'ZZ', 'QO', 'XA', 'XB', 'EZ'] as $notACountry) {
            $this->assertNotContains($notACountry, $countries);
        }

        $this->assertSame(DateTimeZone::listIdentifiers(), $timezones);

        $this->assertSame(['CAD', 'EUR', 'ILS', 'USD', 'ZAR'], $currencies, 'Only active currencies are offered.');
        $this->assertNotContains('GBP', $currencies, 'An inactive currency cannot resolve a wallet, so it is not offered.');
    }

    public function test_a_business_created_with_the_offered_values_stores_the_canonical_codes(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.businesses.store', $workspace->uid), [
            'name' => 'Vilnius Studio',
            'industry' => 'photo_booth_service',
            'country_code' => 'LT',
            'timezone' => 'Europe/Vilnius',
            'currency_code' => 'EUR',
        ])->assertRedirect(route('customer.workspaces.show', $workspace->uid))->assertSessionHasNoErrors();

        $business = Business::query()->where('name', 'Vilnius Studio')->firstOrFail();
        $this->assertSame(['LT', 'Europe/Vilnius', 'EUR'], [$business->country_code, $business->timezone, $business->currency_code]);
    }

    public function test_the_backend_validation_is_unchanged_and_still_rejects_malformed_values(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $this->authenticateAs($owner);

        $this->post(route('customer.workspaces.businesses.store', $workspace->uid), [
            'name' => 'Typed Codes Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'Lithuania',
            'timezone' => 'Europe/Nowhere',
            'currency_code' => 'Euro',
        ])->assertSessionHasErrors(self::FIELDS);

        $this->assertFalse(Business::query()->where('name', 'Typed Codes Co')->exists());
    }

    /**
     * Only a country with exactly one current currency, and only when that
     * currency is offered: Panama, Lesotho and Palestine (two in use, even
     * when one of them is offered) and the UK (GBP inactive here) leave the
     * currency alone.
     */
    public function test_country_to_currency_suggestions_are_deterministic_and_limited_to_offered_currencies(): void
    {
        $response = $this->createBusinessForm();

        $this->assertSame(1, preg_match('/data-country-currency="([^"]*)"/', $response->getContent(), $match));
        $map = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame('EUR', $map['LT'] ?? null);
        $this->assertSame('EUR', $map['DE'] ?? null);
        $this->assertSame('USD', $map['US'] ?? null);
        $this->assertSame('CAD', $map['CA'] ?? null);
        $this->assertSame('ZAR', $map['ZA'] ?? null);
        $this->assertSame('ILS', $map['IL'] ?? null);
        $this->assertArrayNotHasKey('PA', $map);
        $this->assertArrayNotHasKey('LS', $map);
        $this->assertArrayNotHasKey('PS', $map);
        $this->assertArrayNotHasKey('GB', $map);
        $this->assertSame([], array_diff(array_unique(array_values($map)), ['CAD', 'EUR', 'ILS', 'USD', 'ZAR']));
    }

    public function test_new_business_forms_suggest_defaults_and_the_profile_form_does_not(): void
    {
        $this->assertStringContainsString(' data-suggest-defaults ', $this->localeGroupTag($this->createBusinessForm()->getContent()));

        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $profile = $this->get(route('customer.business.edit'))->assertOk();
        $this->assertStringNotContainsString('data-suggest-defaults', $this->localeGroupTag($profile->getContent()));
        $this->assertStringNotContainsString('data-country-currency', $this->localeGroupTag($profile->getContent()));
    }

    public function test_the_business_profile_shows_the_saved_values_selected_and_keeps_one_outside_the_lists(): void
    {
        [$owner, $business] = $this->tenant(WorkspacePlanTier::Growth);
        DB::table('businesses')->where('id', $business->id)->update(['country_code' => 'LT', 'timezone' => 'Europe/Vilnius', 'currency_code' => 'XTS']);
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.business.edit'))->assertOk()->getContent();

        $this->assertOffers($html, 'LT', 'Lithuania (LT)', selected: true);
        $this->assertOffers($html, 'Europe/Vilnius', 'Europe/Vilnius', selected: true);
        // A saved currency outside the list stays selected instead of being replaced on save.
        $this->assertOffers($html, 'XTS', 'XTS', selected: true);
    }

    public function test_the_onboarding_business_step_uses_the_same_selects(): void
    {
        config(['business.onboarding.enabled' => true]);
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer);

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $response = $this->get(route('customer.onboarding.show', ['step' => 'business']))->assertOk();

        foreach (self::FIELDS as $field) {
            $this->assertMatchesRegularExpression('/<select\s[^>]*name="' . $field . '"/', $response->getContent());
        }

        $this->assertStringContainsString(' data-suggest-defaults ', $this->localeGroupTag($response->getContent()));
        $this->assertOffers($response->getContent(), 'EUR', 'EUR — Euro');
        $response->assertDontSee('Country code');
    }

    public function test_the_options_service_labels_are_for_people_and_values_stay_canonical(): void
    {
        $options = app(BusinessLocaleOptions::class);

        $this->assertSame('Lithuania (LT)', $options->countries()['LT']);
        $this->assertSame('EUR — Euro', $options->currencies()['EUR']);
        $this->assertSame('Europe/Vilnius', $options->timezones()['Europe/Vilnius']);
        $this->assertSame(['XTS' => 'XTS', 'EUR' => 'EUR — Euro'], BusinessLocaleOptions::withStoredValue(['EUR' => 'EUR — Euro'], 'XTS'));
        $this->assertSame(['EUR' => 'EUR — Euro'], BusinessLocaleOptions::withStoredValue(['EUR' => 'EUR — Euro'], 'EUR'));
    }

    private function assertOffers(string $html, string $value, string $label, bool $selected = false): void
    {
        $pattern = '/<option value="' . preg_quote(e($value), '/') . '"\s*' . ($selected ? 'selected\s*' : '') . '>' . preg_quote(e($label), '/') . '<\/option>/';

        $this->assertMatchesRegularExpression($pattern, $html, "Expected option [{$value}] labelled [{$label}]" . ($selected ? ' (selected).' : '.'));
    }

    /**
     * The opening tag of the fields' wrapper — its attributes, not the shared
     * script that reads them.
     */
    private function localeGroupTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="row" data-business-locale[^>]*>/', $html, $tag), 'The locale fields wrapper must render once.');

        return $tag[0];
    }

    private function createBusinessForm(): TestResponse
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();
        $response->assertSee('data-workspace-action="businesses"', false);

        return $response;
    }

    /**
     * @return list<string> the non-empty option values of one select
     */
    private function optionValues(string $html, string $name): array
    {
        $this->assertSame(1, preg_match('/<select\s[^>]*name="' . $name . '"[^>]*>(.*?)<\/select>/s', $html, $select), "Select {$name} not found.");
        preg_match_all('/<option value="([^"]*)"/', $select[1], $values);

        return array_values(array_filter(array_map('html_entity_decode', $values[1]), fn (string $value) => $value !== ''));
    }
}
