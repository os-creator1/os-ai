<?php

namespace Tests\Feature\Automations;

use App\Enums\Automation\AutomationTriggerType;
use App\Library\Automation\AutomationExecutionClaimService;
use App\Models\Automation;
use App\Models\AutomationExecution;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * B4 — contract §5.2 (Correction 2): the logical-execution claim verifies
 * the definition under a row lock and INSERTs in the same transaction, so
 * a definition edit cannot commit between verification and INSERT.
 *
 * Proven with a SECOND real MySQL session (not a sleep): while worker A
 * holds the Automation row lock at the checkpoint, the other session's
 * UPDATE of that row blocks and is rejected by a short bounded
 * innodb_lock_wait_timeout; after A commits, the same UPDATE succeeds. A
 * second session needs committed fixture rows, hence the fresh schema.
 */
class AutomationsClaimConcurrencyTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;

    private const SECOND_SESSION = 'mysql_b4_second_session';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    protected function tearDown(): void
    {
        AutomationExecutionClaimService::resetTestSeams();
        DB::purge(self::SECOND_SESSION);

        parent::tearDown();
    }

    private function secondSession(): ConnectionInterface
    {
        config(['database.connections.' . self::SECOND_SESSION => config('database.connections.' . config('database.default'))]);

        $session = DB::connection(self::SECOND_SESSION);
        // Bounded wait: a blocked statement fails fast instead of hanging.
        $session->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $session;
    }

    /**
     * @return array{0: Automation, 1: \App\Models\Contacts, 2: string}
     */
    private function contactCreatedCandidate(): array
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $group = $this->contactGroup($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $contact = $this->contact($business, $group, '12025559001');

        return [$automation, $contact, AutomationExecutionClaimService::contactCreatedKey($automation->id, $contact->id)];
    }

    public function test_definition_edit_cannot_commit_through_the_claim_lock(): void
    {
        [$automation, $contact, $key] = $this->contactCreatedCandidate();
        $second = $this->secondSession();
        $blocked = null;

        AutomationExecutionClaimService::$afterDefinitionLock = function () use ($second, $automation, &$blocked): void {
            // Worker B (another session) tries to change the definition
            // while worker A holds the row lock at the checkpoint.
            try {
                $second->update('UPDATE automations SET trigger_type = ? WHERE id = ?', [AutomationTriggerType::ContactDateReached->value, $automation->id]);
                $blocked = false;
            } catch (QueryException $exception) {
                $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
            }
        };

        $execution = app(AutomationExecutionClaimService::class)->claim($automation->id, $contact, AutomationTriggerType::ContactCreated, $key);

        $this->assertTrue($blocked, 'The concurrent definition edit must be held back by the row lock until the claim decision commits.');
        $this->assertInstanceOf(AutomationExecution::class, $execution);
        $this->assertSame(AutomationTriggerType::ContactCreated, $execution->fresh()->trigger_type);
        $this->assertSame(AutomationTriggerType::ContactCreated, $automation->fresh()->trigger_type, 'The blocked edit must not have landed.');
        $this->assertSame(1, AutomationExecution::query()->where('idempotency_key', $key)->count());

        // The lock was released with the commit: the same edit now succeeds.
        $this->assertSame(1, $second->update('UPDATE automations SET trigger_type = ? WHERE id = ?', [AutomationTriggerType::ContactDateReached->value, $automation->id]));
        $this->assertSame(AutomationTriggerType::ContactDateReached, $automation->fresh()->trigger_type);
    }

    public function test_definition_committed_by_another_session_before_the_lock_makes_claim_return_null(): void
    {
        [$automation, $contact, $key] = $this->contactCreatedCandidate();
        $second = $this->secondSession();

        AutomationExecutionClaimService::$beforeClaimTransaction = function () use ($second, $automation): void {
            // Committed by another session after the lock-free eligibility
            // pass and before the locked checkpoint.
            $second->update('UPDATE automations SET trigger_type = ? WHERE id = ?', [AutomationTriggerType::ContactDateReached->value, $automation->id]);
        };

        $execution = app(AutomationExecutionClaimService::class)->claim($automation->id, $contact, AutomationTriggerType::ContactCreated, $key);

        $this->assertNull($execution);
        $this->assertSame(0, AutomationExecution::query()->count());
    }
}
