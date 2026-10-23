<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Models\ChatBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laravel retries a deadlocked transaction only at the OUTERMOST level, and RefreshDatabase wraps every test in one, so
 * this class does not wrap its test in one, to exercise the actual retry. Synthetic data only.
 */
class LuminaImportRetryTest extends TestCase
{
    use RefreshDatabase;

    /** No wrapping test transaction: the import's own transaction must be the outermost one so a deadlock is really retried. */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        // Nothing was rolled back for us, so leave the database empty for whatever runs next.
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    public function test_a_deadlock_rollback_after_the_chat_box_is_saved_retries_cleanly_without_a_stale_id(): void
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $ctx = app(LuminaAuraProvisioner::class)->provision('owner@example.test', 'a-long-test-password-1', [])['context'];

        $plan = (new GhlImportPlanner())->plan([
            'location' => ['id' => 'L'],
            'contacts' => [['id' => 'C1', 'firstNameRaw' => 'Ann', 'phone' => '(720) 555-0101', 'dateAdded' => '2026-01-02T00:00:00.000Z']],
            'conversations' => [['id' => 'V1', 'contactId' => 'C1']],
            'messages' => ['V1' => [
                ['id' => 'M1', 'messageType' => 'TYPE_SMS', 'direction' => 'outbound', 'dateAdded' => '2026-05-01T10:00:00.000Z', 'body' => 'hi', 'contactId' => 'C1', 'status' => 'delivered'],
                ['id' => 'M2', 'messageType' => 'TYPE_EMAIL', 'direction' => 'inbound', 'dateAdded' => '2026-05-02T10:00:00.000Z', 'body' => 'yo', 'contactId' => 'C1', 'meta' => ['email' => ['subject' => 'S']]],
            ]],
        ]);

        // The first message insert (after the chat box was saved AND remembered) "deadlocks": the attempt is rolled back and retried.
        $deadlocks = 0;
        DB::listen(function ($query) use (&$deadlocks) {
            if ($deadlocks === 0 && str_starts_with($query->sql, 'insert into `chat_box_messages`')) {
                $deadlocks++;

                throw new \Illuminate\Database\QueryException('mysql', $query->sql, [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        app(GhlImportWriter::class)->import($plan, $ctx->business, $ctx->location, '+17205028306', false, 3);

        $this->assertSame(1, $deadlocks, 'The first attempt deadlocked after its chat box was saved and was retried.');
        $boxes = DB::table('chat_boxes')->where('business_id', $ctx->business->id)->pluck('id');
        $this->assertCount(1, $boxes, 'Exactly one chat box survives.');
        $this->assertSame(1, DB::table('chat_box_messages')->count());
        $this->assertSame((int) $boxes[0], (int) DB::table('chat_box_messages')->value('box_id'), 'The message belongs to the box of the SUCCESSFUL attempt.');
        $this->assertSame(1, DB::table('imported_timeline_items')->where('chat_box_id', $boxes[0])->count());
        $this->assertSame((int) $boxes[0], (int) DB::table('external_import_records')->where('source_type', 'conversation')->value('local_id'));
    }
}
