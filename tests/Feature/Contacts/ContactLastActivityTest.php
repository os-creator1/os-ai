<?php

namespace Tests\Feature\Contacts;

use App\Library\Contacts\ContactDirectory;
use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/** The Contacts list's Last activity is the latest REAL activity across text, email and call, in a fixed number of queries. */
class ContactLastActivityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessEmailFixtures;
    use \Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;

    private function email(object $business, int $contactId, $at, bool $deleted = false): void
    {
        BusinessEmailMessage::create([
            'business_id' => $business->id, 'direction' => 'inbound', 'contact_id' => $contactId, 'operation_key' => 'k-' . Str::uuid(), 'provider' => 'google',
            'provider_message_id' => 'pm-' . Str::uuid(), 'provider_thread_id' => 't', 'internet_message_id' => '<' . Str::uuid() . '@x>', 'from_email' => 'a@b.test',
            'to_email' => 'o@b.test', 'subject' => 'S', 'body_text' => 'B', 'status' => 'received', 'received_at' => $at, 'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function seedCall(object $business, int $contactId, $at): void
    {
        DB::table('business_voice_calls')->insert([
            'uid' => (string) Str::uuid(), 'kind' => 'customer', 'channel' => 'browser', 'business_id' => $business->id, 'contact_id' => $contactId,
            'status' => 'ended', 'idempotency_key' => 'c-' . Str::uuid(), 'client_state_hash' => 'x', 'created_at' => $at, 'updated_at' => $at,
            'business_messaging_number_id' => $this->numberFor($business),
        ]);
    }

    private function numberFor(object $business): int
    {
        static $ids = [];

        return $ids[$business->id] ??= (function () use ($business) {
            $identity = \App\Models\BusinessMessagingIdentity::query()->where('business_id', $business->id)->first() ?? $this->attachIdentity($business);

            return $this->attachNumber($identity, $this->uniqueNumber(), true, \App\Enums\Messaging\BusinessMessagingNumberStatus::Active, 'ref_' . uniqid())->id;
        })();
    }

    private function rows(object $business): array
    {
        $page = app(ContactDirectory::class)->page($business, '', null);

        return collect($page->items())->keyBy('uid')->all();
    }

    public function test_it_shows_the_latest_activity_across_channels_and_ignores_deleted_email(): void
    {
        [, $business] = $this->emailTenant();
        $emailed = $this->contactWithEmails($business, ['a@example.com'], null, 'A');
        $called = $this->contactWithEmails($business, ['b@example.com'], null, 'B');
        $both = $this->contactWithEmails($business, ['c@example.com'], null, 'C');
        $quiet = $this->contactWithEmails($business, ['d@example.com'], null, 'D');
        $deleted = $this->contactWithEmails($business, ['e@example.com'], null, 'E');

        $this->email($business, $emailed->id, now()->subHours(3));
        $this->seedCall($business, $called->id, now()->subHours(2));
        $this->email($business, $both->id, now()->subDays(2));
        $this->seedCall($business, $both->id, now()->subHour());
        $this->email($business, $deleted->id, now()->subHour(), deleted: true);

        $rows = $this->rows($business);
        $this->assertSame('Emailed', $rows[$emailed->uid]['last_activity_type']);
        $this->assertSame('Called', $rows[$called->uid]['last_activity_type']);
        $this->assertSame('Called', $rows[$both->uid]['last_activity_type'], 'The newer call beats the older email.');
        $this->assertNull($rows[$quiet->uid]['last_activity']);
        $this->assertNull($rows[$deleted->uid]['last_activity'], 'An email deleted from MotionGrove is not activity.');
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_contacts(): void
    {
        [, $business] = $this->emailTenant();
        $this->contactWithEmails($business, ['a@example.com'], null, 'A');
        DB::enableQueryLog();
        $this->rows($business);
        $one = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            $c = $this->contactWithEmails($business, ["x{$i}@example.com"], null, 'X' . $i);
            $this->email($business, $c->id, now()->subMinutes($i));
        }

        DB::flushQueryLog();
        $this->rows($business);

        $this->assertSame($one, count(DB::getQueryLog()));
    }

    public function test_the_contacts_page_labels_each_row_with_its_real_channel(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $c = $this->contactWithEmails($business, ['a@example.com'], null, 'A');
        $this->email($business, $c->id, now()->subHours(2));
        $this->authenticateAs($owner);

        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $business->uid]))->assertOk()->assertSee('Emailed · 2h ago');
    }
}
