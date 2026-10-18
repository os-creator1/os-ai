<?php

namespace Tests\Feature\Calendar;

use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Manual acceptance defect 4 (P1) — searching a booking contact's exact
 * full name on the "New appointment" picker returned "No matching contacts
 * for this location", even for an existing exact-match contact at the
 * right Location. Root cause: ContactDirectory::query() (shared by the
 * Contacts list, the CRM picker and this booking picker) matched the WHOLE
 * search string against a SINGLE identity field's value — but first and
 * last name live in two SEPARATE contacts_custom_field rows, so a
 * multi-word full name could never match either one on its own.
 */
class CalendarContactSearchFullNameTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
    }

    private function scope(): array
    {
        return $this->scopeFor($this->locationA);
    }

    private function nameContact(int $contactId, int $groupId, string $first, string $last): void
    {
        foreach (['FIRST_NAME' => $first, 'LAST_NAME' => $last] as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $groupId, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );

            ContactsCustomField::create(['contact_id' => $contactId, 'field_id' => $field->id, 'value' => $value]);
        }
    }

    public function test_searching_a_contacts_exact_full_name_finds_it(): void
    {
        $this->bookingType();
        $contactId = $this->contactId();
        $groupId = (int) DB::table('contacts')->where('id', $contactId)->value('group_id');
        $this->nameContact($contactId, $groupId, 'Priya', 'Natarajan');

        $html = $this->get($this->calendarUrl('appointments.create', $this->scope()) . '?q=' . urlencode('Priya Natarajan'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Priya Natarajan', $html);
        $this->assertStringNotContainsString('No matching contacts', $html);
    }

    /** The pre-existing single-word behavior is unchanged by the fix. */
    public function test_a_single_word_search_still_works(): void
    {
        $this->bookingType();
        $contactId = $this->contactId();
        $groupId = (int) DB::table('contacts')->where('id', $contactId)->value('group_id');
        $this->nameContact($contactId, $groupId, 'Priya', 'Natarajan');

        $html = $this->get($this->calendarUrl('appointments.create', $this->scope()) . '?q=Natarajan')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Priya Natarajan', $html);
    }
}
