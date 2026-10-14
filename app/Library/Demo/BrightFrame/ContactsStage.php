<?php

namespace App\Library\Demo\BrightFrame;

use App\Library\Crm\TagManager;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CustomFieldDefinition;
use App\Models\Tag;

/** Contacts: one group, identity fields, custom fields, tags and 24 fictional people. */
final class ContactsStage implements DemoStage
{
    private const TAG_FOR_SOURCE = [
        'wedding' => 'Wedding', 'corporate' => 'Corporate', 'birthday' => 'Birthday',
        'prom' => 'Prom & School', 'graduation' => 'Prom & School', 'quinceanera' => 'Birthday',
    ];

    private const VENUES = ['The Foundry Loft', 'Birkdale Garden Estate', 'Uptown Skyline Ballroom', 'Lakeview Barn at Davidson', 'South End Brewing Hall', 'The Mint Gallery', 'Ballantyne Country Club'];

    public function __construct(
        private readonly CustomFieldDefinitionManager $definitions,
        private readonly CustomFieldValueService $values,
        private readonly TagManager $tags,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;

        $group = ContactGroups::query()->firstOrCreate(
            ['business_id' => $business->id, 'name' => 'Clients & Leads'],
            ['customer_id' => $business->customer_id, 'status' => true],
        );

        $fields = [];
        foreach (['FIRST_NAME' => 'First name', 'LAST_NAME' => 'Last name', 'EMAIL' => 'Email', 'COMPANY' => 'Company'] as $tag => $label) {
            $fields[$tag] = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => $label, 'type' => $tag === 'EMAIL' ? 'email' : 'text', 'visible' => true, 'required' => false],
            );
        }

        $eventType = $this->definition($business, 'Event type', 'select', ['Wedding', 'Corporate event', 'Birthday', 'Prom or school event', 'Brand activation']);
        $eventDate = $this->definition($business, 'Event date', 'date');
        $venue = $this->definition($business, 'Venue', 'text');
        $guests = $this->definition($business, 'Guest count', 'number');

        $tagRows = [];
        foreach (['Wedding', 'Corporate', 'Birthday', 'Prom & School', 'Hot lead', 'Repeat client', 'Referral'] as $name) {
            $tagRows[$name] = $this->tag($business, $name);
        }

        $eventLabels = ['wedding' => 'Wedding', 'corporate' => 'Corporate event', 'birthday' => 'Birthday', 'prom' => 'Prom or school event', 'graduation' => 'Prom or school event', 'quinceanera' => 'Birthday'];
        $created = 0;

        foreach (BrightFrameData::contacts() as $i => $person) {
            $contact = Contacts::query()->where('business_id', $business->id)->where('phone', $person['phone'])->first();

            if ($contact === null) {
                $contact = Contacts::create([
                    'customer_id' => $business->customer_id,
                    'business_id' => $business->id,
                    'group_id' => $group->id,
                    'phone' => $person['phone'],
                    'status' => Contacts::STATUS_SUBSCRIBE,
                ]);
                $contact->forceFill(['location_id' => $context->location->id, 'created_at' => $context->daysFromNow(-(1 + $i * 2), 9 + $i % 8), 'updated_at' => $context->now])->save();
                $created++;
            }

            $identity = ['FIRST_NAME' => $person['first'], 'LAST_NAME' => $person['last'], 'EMAIL' => $person['email']]
                + ($person['source'] === 'corporate' ? ['COMPANY' => self::companyFor($person['last'])] : []);

            foreach ($identity as $tag => $value) {
                ContactsCustomField::query()->updateOrCreate(['contact_id' => $contact->id, 'field_id' => $fields[$tag]->id], ['value' => $value]);
            }

            $this->values->set($business, $contact, $eventType, $this->optionId($eventType, $eventLabels[$person['source']]));
            $this->values->set($business, $contact, $eventDate, $context->daysFromNow(12 + $i * 9)->toDateString());
            $this->values->set($business, $contact, $venue, self::VENUES[$i % count(self::VENUES)]);
            $this->values->set($business, $contact, $guests, 40 + ($i * 17) % 180);

            $names = [self::TAG_FOR_SOURCE[$person['source']]];
            if ($i % 5 === 0) {
                $names[] = 'Hot lead';
            }
            if ($i % 7 === 3) {
                $names[] = 'Repeat client';
            }
            if ($i % 6 === 1) {
                $names[] = 'Referral';
            }
            foreach ($names as $name) {
                $this->tags->attachTag($business, $contact, $tagRows[$name]);
            }

            $context->refs['contacts'][$person['phone']] = $contact->fresh();
            $context->refs['people'][$person['phone']] = $person;
        }

        $context->refs['contact_group'] = $group;
        $context->note('contacts', Contacts::query()->where('business_id', $business->id)->count());
        $context->note('contacts_created_now', $created);
    }

    private static function companyFor(string $last): string
    {
        return match ($last) {
            'Reyes' => 'Queen City Software',
            'Okafor' => 'Piedmont Health Partners',
            'Dubois' => 'Carolina Craft Distillers',
            'Kowalski' => 'Southline Logistics',
            'Park' => 'Harborview Financial',
            default => $last . ' & Co.',
        };
    }

    /** @param list<string> $options */
    private function definition($business, string $label, string $type, array $options = []): CustomFieldDefinition
    {
        $key = str_replace(' ', '_', strtolower($label));

        return CustomFieldDefinition::query()->where('business_id', $business->id)->where('key', $key)->first()
            ?? $this->definitions->create($business, $label, $type, $options === [] ? null : $options);
    }

    private function optionId(CustomFieldDefinition $definition, string $label): string
    {
        foreach ((array) $definition->options as $option) {
            if (($option['label'] ?? null) === $label) {
                return (string) $option['id'];
            }
        }

        throw new \RuntimeException("No option [{$label}] on [{$definition->label}].");
    }

    private function tag($business, string $name): Tag
    {
        return Tag::query()->where('business_id', $business->id)->where('name', $name)->first()
            ?? $this->tags->createTag($business, $name);
    }
}
