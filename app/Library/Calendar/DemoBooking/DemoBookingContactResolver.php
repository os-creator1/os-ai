<?php

namespace App\Library\Calendar\DemoBooking;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Illuminate\Support\Facades\DB;

/**
 * The Contact a /book-demo booking belongs to. It runs INSIDE the booking
 * engine's transaction (after the engine's tier 1 and tier 2 locks), exactly
 * where PublicBookingController resolves its own Contact, and it can only ever
 * touch the Business that owns the designated Booking Type: the group, the
 * Location and every lookup are scoped to it, so an internal lead can never
 * land in, or be matched against, another tenant's CRM.
 *
 * With a phone number the identity is the engine's own (Location + normalized
 * phone, serialised on booking_contact_identity_locks). Without one the
 * identity is the lower-cased email, serialised on the SAME lock table under a
 * key that can never equal a normalized phone (it carries an `e:` prefix), so
 * two simultaneous bookings by one person cannot create two Contacts.
 *
 * A matched Contact is updated additively: blank fields are filled, nothing
 * the Business already holds is overwritten by an anonymous submission.
 */
class DemoBookingContactResolver
{
    private const FIELDS = [
        'EMAIL' => 'Email',
        'BUSINESS_NAME' => 'Business name',
        'WEBSITE' => 'Website',
    ];

    public function __construct(private readonly EloquentContactsRepository $contacts)
    {
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, business_name: string, website?: ?string, phone?: ?string}  $data
     */
    public function resolve(BusinessLocation $location, Business $business, array $data): int
    {
        $group = ContactGroups::query()->where('business_id', $business->id)->orderBy('id')->first()
            ?? $this->contacts->store([
                'name' => 'Contacts', 'business_id' => $business->id, 'user_id' => $business->customer_id,
            ]);
        $this->ensureFields($group);

        $fields = [
            'FIRST_NAME' => $data['first_name'],
            'LAST_NAME' => $data['last_name'],
            'EMAIL' => $data['email'],
            'BUSINESS_NAME' => $data['business_name'],
            'WEBSITE' => $data['website'] ?? null,
        ];
        $phone = trim((string) ($data['phone'] ?? ''));

        $contact = $phone !== ''
            ? $this->contacts->findOrCreateForBooking($location, $group, $phone, $fields)
            : $this->byEmail($location, $group, $fields);

        $this->fillBlank($contact->fresh(), $group, $fields);

        return (int) $contact->id;
    }

    private function byEmail(BusinessLocation $location, ContactGroups $group, array $fields): Contacts
    {
        $key = ['business_location_id' => $location->id, 'normalized_phone' => 'e:'.substr(sha1(mb_strtolower($fields['EMAIL'])), 0, 30)];
        DB::table('booking_contact_identity_locks')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('booking_contact_identity_locks')->where($key)->lockForUpdate()->firstOrFail();

        $emailField = $group->getFieldByTag('EMAIL');
        $existing = Contacts::query()
            ->where('business_id', (int) $location->business_id)
            ->where('location_id', $location->id)
            ->where('phone', '')
            ->whereIn('id', ContactsCustomField::query()
                ->where('field_id', $emailField->id)->where('value', $fields['EMAIL'])->select('contact_id'))
            ->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $contact = new Contacts(['phone' => '']);
        $contact->group_id = $group->id;
        $contact->customer_id = $group->customer_id;
        $contact->business_id = $group->business_id;
        $contact->location_id = $location->id;
        $contact->status = Contacts::STATUS_SUBSCRIBE;
        $contact->save();
        $contact->updateFields(array_filter($fields, fn ($value) => $value !== null && $value !== ''));
        $group->updateCache();

        return $contact;
    }

    private function fillBlank(Contacts $contact, ContactGroups $group, array $fields): void
    {
        foreach ($fields as $tag => $value) {
            $field = $group->getFieldByTag($tag);
            if ($field === null || $value === null || trim((string) $value) === '') {
                continue;
            }
            $row = ContactsCustomField::query()->where('contact_id', $contact->id)->where('field_id', $field->id)->first();
            if ($row === null || trim((string) $row->value) === '') {
                ContactsCustomField::updateOrCreate(
                    ['contact_id' => $contact->id, 'field_id' => $field->id],
                    ['value' => $value]
                );
            }
        }
    }

    /** The group row is locked first (we are inside the booking transaction) so racing bookings cannot both add a field. */
    private function ensureFields(ContactGroups $group): void
    {
        $locked = ContactGroups::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
        foreach (self::FIELDS as $tag => $label) {
            if ($locked->getFieldByTag($tag) === null) {
                $locked->contactGroupFields()->create([
                    'contact_group_id' => $locked->id, 'type' => 'text', 'label' => $label,
                    'tag' => $tag, 'required' => false, 'visible' => true,
                ]);
            }
        }
    }
}
