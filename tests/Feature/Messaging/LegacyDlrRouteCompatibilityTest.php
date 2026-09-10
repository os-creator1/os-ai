<?php

namespace Tests\Feature\Messaging;

use App\Http\Controllers\Customer\DLRController;
use App\Models\Reports;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 — Security Correction 37, findings 1, 2 and 5.
 *
 * Correction 36 gave `updateDLR()` a TYPED third parameter. That looked
 * harmless because it was optional — but roughly fifteen legacy handlers had
 * always passed extra positional arguments there (a phone number, a sender
 * id) which the historic two-parameter method silently ignored, as PHP
 * allows. A typed parameter turned every one of those dead arguments into a
 * fatal TypeError, so the correction that hardened this method would have
 * crashed a dozen live delivery-callback routes.
 *
 * The previous suite missed it entirely because it exercised the shared seam
 * through Reflection and never drove a route. This file drives the REAL
 * routes, with realistic payloads, for every handler the adversarial review
 * named.
 *
 * These routes are unauthenticated today and this file does not pretend
 * otherwise; item 5 of the correction covers authenticity separately. What is
 * asserted here is narrower and absolute: **a delivery callback must never
 * produce a PHP type crash.** Fail-closed is an acceptable outcome; a 500 is
 * not.
 */
class LegacyDlrRouteCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Every handler the review named, with the minimum realistic payload its
     * own `$request->input(...)` calls read.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function legacyDlrRouteProvider(): array
    {
        return [
            'routemobile'   => ['dlr.routemobile',   ['sMessageId' => 'DLR_ID_1', 'sStatus' => 'DELIVRD', 'sSender' => 'SENDER', 'sMobileNo' => '14155550001']],
            'plivo'         => ['dlr.plivo',         ['MessageUUID' => 'DLR_ID_2', 'Status' => 'delivered', 'To' => '14155550002', 'From' => 'SENDER']],
            'advancemsgsys' => ['dlr.advancemsgsys', ['MessageId' => 'DLR_ID_3', 'Status' => 'DELIVRD', 'Destination' => '14155550003', 'Source' => 'SENDER']],
            'vonage'        => ['dlr.vonage',        ['messageId' => 'DLR_ID_4', 'status' => 'delivered', 'msisdn' => '14155550004', 'to' => 'SENDER']],
            'easysendsms'   => ['dlr.easysendsms',   ['sms_id' => 'DLR_ID_5', 'response' => 'DELIVRD', 'msisdn' => '14155550005', 'source' => 'SENDER']],
            'africastalking' => ['dlr.africastalking', ['id' => 'DLR_ID_6', 'status' => 'Success', 'phoneNumber' => '+14155550006']],
            '1s2u'          => ['dlr.1s2u',          ['msgid' => 'DLR_ID_7', 'status' => 'DELIVRD', 'mno' => '+14155550007', 'sid' => 'SENDER']],
            'gatewayapi'    => ['dlr.gatewayapi',    ['id' => 'DLR_ID_8', 'status' => 'DELIVERED', 'msisdn' => '+14155550008']],
            'nimbuz'        => ['dlr.nimbuz',        ['requestid' => 'DLR_ID_9', 'status' => 'DELIVRD', 'mobile' => '+14155550009']],
            'gatewaysa'     => ['dlr.gatewaysa',     ['messageId' => 'DLR_ID_10', 'status' => 'DELIVRD', 'mobile' => '+14155550010']],
            'smsmode'       => ['dlr.smsmode',       ['messageId' => 'DLR_ID_11', 'status' => ['value' => 'DELIVRD'], 'from' => '+14155550011']],
            // `mocean-dlr-status` must be one of '1'/'2'/'3': this handler's
            // match() has no default arm, so an unrecognised value throws
            // UnhandledMatchError. That is PRE-EXISTING behaviour, unrelated
            // to Correction 36's typed parameter, and is reported rather than
            // widened into this round's scope — see the correction report.
            'moceanapi'     => ['dlr.moceanapi',     ['mocean-msgid' => 'DLR_ID_12', 'mocean-dlr-status' => '2', 'mocean-to' => '+14155550012']],
            // The two this lane touched directly, kept in the same table so a
            // future change cannot regress one without the other.
            'twilio'        => ['dlr.twilio',        ['MessageSid' => 'DLR_ID_13', 'MessageStatus' => 'delivered']],
            'textlocal'     => ['dlr.textlocal',     ['customID' => 'DLR_ID_14', 'status' => 'D', 'number' => '14155550014']],
        ];
    }

    /**
     * @dataProvider legacyDlrRouteProvider
     */
    public function test_a_realistic_callback_never_produces_a_php_type_crash(string $routeName, array $payload): void
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        if ($route === null) {
            $this->markTestSkipped("Route [{$routeName}] is not registered in this build.");
        }

        $response = $this->post(route($routeName), $payload);

        // The contract of this test, stated exactly: no type crash. Whether
        // the handler resolves a Report or fails closed is its own business
        // and depends on data this test deliberately does not create.
        $this->assertNotSame(500, $response->getStatusCode(), "[{$routeName}] returned a server error.");

        $this->assertNull(
            $response->exception,
            "[{$routeName}] threw " . ($response->exception === null ? '' : get_class($response->exception) . ': ' . $response->exception->getMessage()),
        );
    }

    /**
     * @dataProvider legacyDlrRouteProvider
     */
    public function test_a_resolvable_callback_still_applies_its_delivery_mapping(string $routeName, array $payload): void
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        if ($route === null) {
            $this->markTestSkipped("Route [{$routeName}] is not registered in this build.");
        }

        // Give this handler's own message id a real report to find, so the
        // test proves the mapping still WORKS rather than only that it does
        // not crash.
        $providerMessageId = collect($payload)->first(fn ($v) => is_string($v) && str_starts_with($v, 'DLR_ID_'));
        $owner = $this->createCustomer();

        $report = Reports::create([
            'user_id' => $owner->user_id,
            'to' => '14155559999',
            'message' => 'legacy',
            'sms_type' => 'plain',
            'status' => 'Enroute|' . $providerMessageId,
            'customer_status' => 'Enroute',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => 3,
            'sms_count' => 1,
        ]);

        $this->post(route($routeName), $payload);

        $fresh = $report->fresh();

        $this->assertStringContainsString(
            $providerMessageId,
            (string) $fresh->status,
            "[{$routeName}] must still record its provider message id.",
        );
        $this->assertNotSame('Enroute', $fresh->customer_status, "[{$routeName}] must have applied a mapping.");
    }

    // =================================================================
    // Finding 2 — the sms_unit lost-update race
    // =================================================================

    /**
     * TWO DIFFERENT reports, the SAME customer, both failing.
     *
     * Correction 36 read the balance into PHP and wrote a computed value
     * back. The Reports row lock protects THAT REPORT and says nothing about
     * the User row, so the second write overwrote the first and the customer
     * was credited max(C1, C2) instead of C1 + C2.
     *
     * The credit is now a single `sms_unit = sms_unit + ?` statement, so the
     * database serializes the writers. Deterministic: no sleeps, no threads —
     * the two callbacks are simply both applied and the arithmetic is
     * asserted.
     */
    public function test_two_reports_of_one_customer_both_credit_their_own_cost(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $first = $this->reportFor($owner->user, 'RACE_A', cost: 3);
        $second = $this->reportFor($owner->user, 'RACE_B', cost: 11);

        DLRController::updateDLR('RACE_A', 'Failed');
        DLRController::updateDLR('RACE_B', 'Failed');

        // B + C1 + C2, exactly. Under the lost-update bug this was 111.
        $this->assertSame(114, (int) $owner->user->fresh()->sms_unit);
    }

    public function test_interleaved_credit_and_debit_on_separate_reports_both_land(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $this->reportFor($owner->user, 'MIX_A', cost: 7);
        $refunded = $this->reportFor($owner->user, 'MIX_B', cost: 5);

        // B is already refunded, so its Delivered callback debits.
        DLRController::updateDLR('MIX_B', 'Failed');
        $this->assertSame(105, (int) $owner->user->fresh()->sms_unit);

        // Now a credit and a debit, on different reports, in sequence.
        DLRController::updateDLR('MIX_A', 'Failed');
        DLRController::updateDLR('MIX_B', 'Delivered');

        // 100 + 5 (B credit) + 7 (A credit) - 5 (B debit) = 107.
        $this->assertSame(107, (int) $owner->user->fresh()->sms_unit);
    }

    public function test_the_atomic_update_is_a_single_sql_expression_not_a_read_modify_write(): void
    {
        // Structural: the balance must never be loaded into PHP and written
        // back. `increment()`/`decrement()` compile to `sms_unit = sms_unit +
        // ?`; a computed assignment would show up as a literal value.
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 50;
        $owner->user->save();

        $this->reportFor($owner->user, 'SQL_SHAPE', cost: 9);

        $statements = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'sms_unit')) {
                $statements[] = $query->sql;
            }
        });

        DLRController::updateDLR('SQL_SHAPE', 'Failed');

        $updates = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(strtolower($sql), 'update'),
        ));

        $this->assertNotEmpty($updates, 'The balance change must reach the database.');

        foreach ($updates as $sql) {
            $this->assertStringContainsString(
                'sms_unit` + ',
                $sql,
                'The credit must be a single SQL expression, not a computed value written back.',
            );
        }
    }

    // =================================================================
    // Finding 5 — oversized provider message ids are refused early
    // =================================================================

    public function test_an_oversized_provider_message_id_is_refused_before_any_query(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $max = DLRController::MAX_PROVIDER_MESSAGE_ID_LENGTH;

        // Exactly at the bound: allowed to reach the resolver (it finds
        // nothing, which is correct — nothing was stored under it).
        $atBound = str_repeat('a', $max);
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        DLRController::updateDLR($atBound, 'Failed');
        $this->assertGreaterThan(0, $queries, 'An id at the bound is still looked up.');

        // One over: refused with no query at all.
        $queries = 0;
        DLRController::updateDLR($atBound . 'a', 'Failed');
        $this->assertSame(0, $queries, 'An oversized id must be refused before any query.');

        // Empty and whitespace-only, likewise.
        $queries = 0;
        DLRController::updateDLR('', 'Failed');
        DLRController::updateDLR('     ', 'Failed');
        $this->assertSame(0, $queries);

        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit, 'And none of them moved money.');
    }

    private function reportFor(User $owner, string $providerMessageId, int $cost): Reports
    {
        return Reports::create([
            'user_id' => $owner->id,
            'to' => '14155559998',
            'message' => 'legacy',
            'sms_type' => 'plain',
            'status' => 'Delivered|' . $providerMessageId,
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => $cost,
            'sms_count' => 1,
        ]);
    }
}
