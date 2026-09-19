<?php

/**
 * Standalone runner invoked as a separate OS process by
 * AppointmentBookingConcurrencyTest, so Implementation Contract 15 §12.C's
 * required races run on two genuinely independent database connections —
 * something a single PHPUnit process cannot do, because its own transaction
 * would hide the very committed rows the race is about.
 *
 * Boots the app in the testing environment so it resolves the same database
 * as the parent, and INDEPENDENTLY re-verifies that database against
 * EXPECTED_TEST_DATABASE before any write: APP_ENV=testing alone is not proof,
 * since a stale bootstrap/cache/config.php would make Laravel skip .env
 * resolution entirely and silently reuse whatever database was baked in.
 *
 * SYNCHRONIZED START. Every operation takes a <startAtEpochMicros> argument
 * and busy-waits until that instant before entering the engine. Two children
 * handed the same instant therefore enter their critical sections within
 * microseconds of each other, on two real connections — a genuine race, not a
 * simulated one. Each process reports entered_us and elapsed_us so the parent
 * can PROVE the two overlapped rather than assuming it.
 *
 * Exit codes are the parent's evidence, so they are distinct on purpose:
 *   0  the operation committed (prints its result detail)
 *   4  refused cleanly by a domain rule (a BookingRefusedException subclass),
 *      which is the contracted loser outcome — never a raw duplicate-key error
 *   3  refused to run against an unexpected database
 *   1  anything else, with the exception class on STDERR
 *
 * Usage:
 *   php concurrent_booking_runner.php book        <startAtEpochMicros> <bookingTypeId> <staffUserId> <contactId> <slotIso>
 *   php concurrent_booking_runner.php roundrobin  <startAtEpochMicros> <bookingTypeId> <contactId> <slotIso>
 *   php concurrent_booking_runner.php reschedule  <startAtEpochMicros> <appointmentId> <newSlotIso>
 *   php concurrent_booking_runner.php cancel      <startAtEpochMicros> <appointmentId>
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;
const REFUSED_EXIT_CODE = 4;

$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run the booking engine: EXPECTED_TEST_DATABASE was not handed down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run the booking engine: ' . $e->getMessage() . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$operation = $argv[1] ?? '';
$startAtEpochMicros = (int) ($argv[2] ?? 0);

/** Busy-wait to the shared instant so both children enter together. */
$waitForStart = static function (int $target): void {
    if ($target <= 0) {
        return;
    }

    while (true) {
        $now = (int) (microtime(true) * 1_000_000);

        if ($now >= $target) {
            return;
        }

        $remaining = $target - $now;

        if ($remaining > 2_000) {
            usleep((int) min($remaining - 1_000, 50_000));
        }
    }
};

$engine = $app->make(App\Library\Calendar\AppointmentBookingService::class);

try {
    $run = match ($operation) {
        'book' => static function () use ($engine, $argv): string {
            $bookingType = App\Models\BookingType::query()->findOrFail((int) $argv[3]);
            $appointment = $engine->book(
                $bookingType,
                (int) $argv[4],
                (int) $argv[5],
                Illuminate\Support\Carbon::parse($argv[6])
            );

            return 'appointment_id=' . $appointment->id . ' staff_user_id=' . $appointment->staff_user_id;
        },
        'roundrobin' => static function () use ($engine, $argv): string {
            $bookingType = App\Models\BookingType::query()->findOrFail((int) $argv[3]);
            $appointment = $engine->bookWithRoundRobin(
                $bookingType,
                (int) $argv[4],
                Illuminate\Support\Carbon::parse($argv[5])
            );

            return 'appointment_id=' . $appointment->id . ' staff_user_id=' . $appointment->staff_user_id;
        },
        'reschedule' => static function () use ($engine, $argv): string {
            $appointment = App\Models\Appointment::query()->findOrFail((int) $argv[3]);
            $moved = $engine->reschedule($appointment, Illuminate\Support\Carbon::parse($argv[4]));

            return 'appointment_id=' . $moved->id . ' start_at=' . $moved->start_at->utc()->toDateTimeString();
        },
        'cancel' => static function () use ($engine, $argv): string {
            $appointment = App\Models\Appointment::query()->findOrFail((int) $argv[3]);
            $cancelled = $engine->cancel($appointment, null, 'concurrent cancel');

            return 'appointment_id=' . $cancelled->id . ' status=' . $cancelled->status->value;
        },
        default => throw new InvalidArgumentException("Unknown operation [{$operation}]."),
    };

    $waitForStart($startAtEpochMicros);

    $enteredAt = (int) (microtime(true) * 1_000_000);
    fwrite(STDOUT, 'ENTERED entered_us=' . $enteredAt . "\n");

    $detail = $run();

    $elapsed = (int) (microtime(true) * 1_000_000) - $enteredAt;

    fwrite(STDOUT, 'COMMITTED ' . $detail . ' entered_us=' . $enteredAt . ' elapsed_us=' . $elapsed . "\n");
    exit(0);
} catch (App\Exceptions\Calendar\BookingRefusedException $refusal) {
    $elapsed = isset($enteredAt) ? (int) (microtime(true) * 1_000_000) - $enteredAt : 0;

    fwrite(STDOUT, 'REFUSED class=' . (new ReflectionClass($refusal))->getShortName()
        . ' entered_us=' . ($enteredAt ?? 0) . ' elapsed_us=' . $elapsed . "\n");
    exit(REFUSED_EXIT_CODE);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
