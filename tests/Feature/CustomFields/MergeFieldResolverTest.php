<?php

namespace Tests\Feature\CustomFields;

use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Merge\MergeContext;
use App\Library\Merge\MergeFieldRegistry;
use App\Library\Merge\MergeFieldResolver;
use App\Models\Appointment;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The canonical merge engine: built-in and custom tokens, missing / unknown
 * behaviour, explicit Opportunity / Appointment context, and tenancy.
 */
class MergeFieldResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function resolver(): MergeFieldResolver
    {
        return app(MergeFieldResolver::class);
    }

    private function define(\App\Models\Business $business, string $label, string $type, mixed $options = null): \App\Models\CustomFieldDefinition
    {
        return app(CustomFieldDefinitionManager::class)->create($business, $label, $type, $options);
    }

    public function test_built_in_contact_business_and_location_tokens_resolve(): void
    {
        [, $business] = $this->crmTenant('Harbor Lane Studios');
        $business->forceFill(['email' => 'hello@harbor.test', 'phone' => '+14155550100', 'website_url' => 'https://harbor.test'])->save();
        $location = BusinessLocation::create([
            'business_id' => $business->id, 'name' => 'Downtown', 'service_mode' => 'storefront', 'country_code' => 'US',
            'address_line_1' => '1 Main St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
        ]);
        $contact = $this->crmContact($business, ['FIRST_NAME' => 'Pat', 'LAST_NAME' => 'Lee', 'EMAIL' => 'pat@example.com', 'COMPANY' => 'Acme'], '14155550123');
        $contact->forceFill(['location_id' => $location->id])->save();

        $text = '{{contact.first_name}}|{{contact.last_name}}|{{contact.full_name}}|{{contact.email}}|{{contact.phone}}|{{contact.company}}'
            . '|{{business.name}}|{{business.email}}|{{business.phone}}|{{business.website}}|{{location.name}}|{{location.address}}';

        $result = $this->resolver()->resolve($text, new MergeContext($business, $contact->fresh(), $location));

        $this->assertSame(
            'Pat|Lee|Pat Lee|pat@example.com|+14155550123|Acme|Harbor Lane Studios|hello@harbor.test|+14155550100|https://harbor.test|Downtown|1 Main St, Austin, TX 78701, US',
            $result->text,
        );
        $this->assertSame([], $result->unknown);
        $this->assertSame([], $result->missing);
    }

    public function test_custom_fields_render_as_readable_text_by_type(): void
    {
        [, $business] = $this->crmTenant();
        $business->forceFill(['currency_code' => 'USD'])->save();
        $contact = $this->crmContact($business, ['FIRST_NAME' => 'Pat']);
        $values = app(CustomFieldValueService::class);
        $date = $this->define($business, 'Event Date', 'date');
        $when = $this->define($business, 'Event Start', 'datetime');
        $guests = $this->define($business, 'Guest Count', 'number');
        $budget = $this->define($business, 'Budget', 'currency');
        $insured = $this->define($business, 'Insured', 'boolean');
        $kind = $this->define($business, 'Event Type', 'select', ['Wedding', 'Corporate']);
        $extras = $this->define($business, 'Extras', 'multi_select', ['Props', 'Backdrop']);

        $values->set($business, $contact, $date, '2027-06-14');
        $values->set($business, $contact, $when, '2027-06-14 18:30');
        $values->set($business, $contact, $guests, '180');
        $values->set($business, $contact, $budget, '2500.50');
        $values->set($business, $contact, $insured, true);
        $values->set($business, $contact, $kind, 'Corporate');
        $values->set($business, $contact, $extras, ['Props', 'Backdrop']);

        $text = '{{contact.event_date}}|{{contact.event_start}}|{{contact.guest_count}}|{{contact.budget}}|{{contact.insured}}|{{contact.event_type}}|{{contact.extras}}';

        $this->assertSame(
            '14 Jun 2027|14 Jun 2027, 6:30 PM|180|USD 2,500.50|Yes|Corporate|Props, Backdrop',
            $this->resolver()->render($text, MergeContext::forContact($contact->fresh())),
        );

        // Renaming the label or an option never changes what the stored value says.
        app(CustomFieldDefinitionManager::class)->update($business, $date, 'When');
        $this->assertSame('14 Jun 2027', $this->resolver()->render('{{contact.event_date}}', MergeContext::forContact($contact->fresh())));
    }

    public function test_a_missing_value_resolves_blank_and_an_unknown_token_is_flagged_never_leaked(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business, ['FIRST_NAME' => 'Pat']);
        $this->define($business, 'Venue', 'text');

        $result = $this->resolver()->resolve(
            'Hi {{contact.first_name}}, venue: [{{contact.venue}}], [{{contact.nope}}], [{{lead_name}}], [{{customer_name}}], [{{ not a token }}], [{{opportunity.name}}]',
            MergeContext::forContact($contact),
        );

        $this->assertSame('Hi Pat, venue: [], [], [], [], [], []', $result->text);
        $this->assertStringNotContainsString('{{', $result->text);
        $this->assertSame(['{{contact.venue}}', '{{opportunity.name}}'], $result->missing);
        $this->assertEqualsCanonicalizing(
            ['{{contact.nope}}', '{{lead_name}}', '{{customer_name}}', '{{ not a token }}'],
            $result->unknown,
            'Aliases are not part of the vocabulary: they are unknown.',
        );
    }

    public function test_unknown_tokens_are_reported_for_editors_by_the_offered_groups(): void
    {
        [, $business] = $this->crmTenant();
        $this->define($business, 'Venue', 'text');

        $unknown = $this->resolver()->unknownTokens(
            '{{contact.first_name}} {{contact.venue}} {{contact.zzz}} {{appointment.start_date}} {{opportunity.name}}',
            $business,
            MergeFieldRegistry::groupsForTrigger(null),
        );

        $this->assertEqualsCanonicalizing(['{{contact.zzz}}', '{{appointment.start_date}}', '{{opportunity.name}}'], $unknown);
    }

    public function test_a_custom_field_of_another_business_is_unknown_and_never_resolves(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($a);
        $contactB = $this->crmContact($b);
        $fieldA = $this->define($a, 'Secret Code', 'text');
        app(CustomFieldValueService::class)->set($a, $contactA, $fieldA, 'A-ONLY');

        $this->assertSame('A-ONLY', $this->resolver()->render('{{contact.secret_code}}', MergeContext::forContact($contactA)));

        $result = $this->resolver()->resolve('[{{contact.secret_code}}]', MergeContext::forContact($contactB));
        $this->assertSame('[]', $result->text);
        $this->assertSame(['{{contact.secret_code}}'], $result->unknown);
    }

    public function test_a_contact_of_another_business_in_the_context_is_treated_as_absent(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $foreign = $this->crmContact($b, ['FIRST_NAME' => 'Leaked', 'EMAIL' => 'leak@example.com']);

        $text = $this->resolver()->render('[{{contact.first_name}}][{{contact.email}}][{{business.name}}]', new MergeContext($a, $foreign));

        $this->assertSame('[][][' . $a->name . ']', $text, 'No Business B data may render in Business A.');
    }

    public function test_an_archived_custom_field_still_resolves_but_is_not_offered_for_new_use(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $field = $this->define($business, 'Venue', 'text');
        $manager = app(CustomFieldDefinitionManager::class);
        app(CustomFieldValueService::class)->set($business, $contact, $field, 'Grand Hotel');
        $manager->archive($business, $field);

        $this->assertSame('Grand Hotel', $this->resolver()->render('{{contact.venue}}', MergeContext::forContact($contact)));

        $registry = app(MergeFieldRegistry::class);
        $tokens = fn (array $catalog): array => collect($catalog)->pluck('fields')->flatten(1)->pluck('token')->all();

        $this->assertNotContains('{{contact.venue}}', $tokens($registry->catalog($business)));
        $this->assertContains('{{contact.venue}}', $tokens($registry->catalog($business, MergeFieldRegistry::groupsForTrigger(null), ['venue'])), 'An existing reference stays visible to its editor.');
    }

    public function test_opportunity_and_appointment_tokens_need_explicit_matching_context(): void
    {
        [, $business] = $this->crmTenant();
        $business->forceFill(['timezone' => 'UTC', 'currency_code' => 'USD'])->save();
        $contact = $this->crmContact($business, ['FIRST_NAME' => 'Pat']);
        $other = $this->crmContact($business, ['FIRST_NAME' => 'Sam']);
        $location = BusinessLocation::create(['business_id' => $business->id, 'name' => 'Downtown', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $deal = $this->deal($business, null, $contact, 'Wedding booth', null, 150000);
        $appointment = new Appointment(['business_location_id' => $location->id, 'contact_id' => $contact->id, 'start_at' => '2027-06-14 18:30:00']);
        $text = '[{{opportunity.name}}][{{opportunity.value}}][{{opportunity.stage}}][{{appointment.start_date}}][{{appointment.start_time}}][{{appointment.timezone}}]';

        // No context: documented missing behaviour, never an arbitrary "latest" row.
        $this->assertSame('[][][][][][]', $this->resolver()->render($text, MergeContext::forContact($contact)));

        $with = $this->resolver()->resolve($text, new MergeContext($business, $contact, null, $deal, $appointment));
        $this->assertSame('[Wedding booth][USD 1,500][New inquiry][14 Jun 2027][6:30 PM][UTC]', $with->text);

        // Records of a different Contact are not this Contact's facts.
        $mismatch = $this->resolver()->render($text, new MergeContext($business, $other, null, $deal, $appointment));
        $this->assertSame('[][][][][][]', $mismatch);
    }

    public function test_opportunity_and_appointment_from_another_business_never_resolve(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($a);
        $locationB = BusinessLocation::create(['business_id' => $b->id, 'name' => 'Elsewhere', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $dealB = $this->deal($b, null, null, 'B deal');
        $appointmentB = new Appointment(['business_location_id' => $locationB->id, 'contact_id' => $contactA->id, 'start_at' => '2027-06-14 18:30:00']);

        $text = $this->resolver()->render('[{{opportunity.name}}][{{appointment.start_date}}]', new MergeContext($a, $contactA, null, $dealB, $appointmentB));

        $this->assertSame('[][]', $text);
    }

    public function test_built_in_identity_is_not_stored_in_the_custom_field_tables(): void
    {
        [, $business] = $this->crmTenant();
        $this->crmContact($business, ['FIRST_NAME' => 'Pat']);

        $this->assertSame(0, \App\Models\CustomFieldDefinition::query()->count());
        $this->assertSame(0, \App\Models\CustomFieldValue::query()->count());
        $this->assertContains('first_name', MergeFieldRegistry::reservedContactKeys());
    }
}
