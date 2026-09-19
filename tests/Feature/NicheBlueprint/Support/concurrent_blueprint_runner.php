<?php

/**
 * Standalone runner invoked as a separate OS process by
 * NicheBlueprintPublisherConcurrencyTest, so its scenarios exercise genuinely
 * independent database connections racing for the same row lock — something a
 * single PHPUnit process cannot do on its own.
 *
 * Boots the app in the testing environment so it shares the same database and
 * .env.testing credentials as the parent PHPUnit process, following the merged
 * Usage/Entitlement subprocess runners' exact bootstrap/database-guard/exit-code
 * shape.
 *
 * REGISTERS A TEST ADAPTER. The BlueprintComponentAdapterRegistry ships empty
 * (Slice 20A), and publishVersion()'s gate 1 requires an adapter for every
 * component_type. A child process gets a fresh container, so it must register
 * the same fake the parent uses or every publish would fail closed for the
 * wrong reason.
 *
 * Usage: php concurrent_blueprint_runner.php <mode> <args...>
 *
 * Modes:
 *   create-draft <blueprintId> <actorUserId>
 *   publish <versionId> <actorUserId>
 *   add-component <versionId> <actorUserId> <componentKey>
 *
 *   hold-then <lockSpecs> <holdSeconds> <delegateMode> <delegateArgs...>
 *     Deterministic direction-forcing primitive, identical in shape to the
 *     merged Entitlement runner's: opens one real transaction, takes an
 *     explicit `SELECT ... FOR UPDATE` on each row named in <lockSpecs> (a
 *     '|'-delimited list of 'table:column:id' triples, in the order given),
 *     prints "LOCKED" (flushed) so the parent can confirm the lock is genuinely
 *     held before starting the racing process, sleeps <holdSeconds>, then runs
 *     <delegateMode> inside that SAME transaction. <lockSpecs> must be the
 *     exact prefix of the delegate's own production lock sequence — for this
 *     domain that is always the `niche_blueprints` row, which every publisher
 *     method locks first — so the delegate's own re-acquisition is a no-op and
 *     its real logic proceeds undisturbed. No production sleep exists; the
 *     sleep lives entirely here.
 *
 * Exit codes:
 *   0  the operation succeeded
 *   1  a domain refusal (the expected outcome for a losing racer)
 *   2  an unexpected error
 *   3  wrong database — refused before touching anything
 */

require __DIR__ . '/../../../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const WRONG_DATABASE_EXIT_CODE = 3;

$expected = getenv('EXPECTED_TEST_DATABASE');

if (! is_string($expected) || trim($expected) === '') {
    fwrite(STDERR, "EXPECTED_TEST_DATABASE was not handed down; refusing to run.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

$actual = Illuminate\Support\Facades\DB::connection()->getDatabaseName();

if ($actual !== $expected) {
    fwrite(STDERR, "Refusing to run against [{$actual}]; expected [{$expected}].\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

Tests\Support\TestDatabaseSafety::assertSafeTestDatabaseName($actual);

// The registry ships empty; a child container needs the same fake the parent
// registered, or gate 1 would refuse every publish for the wrong reason.
app(App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry::class)->register(
    new class implements App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapter
    {
        public function componentType(): string
        {
            return 'crm_pipeline';
        }

        public function validateDescriptor(array $payload): void
        {
        }

        public function install(
            App\Models\Business $business,
            array $payload,
            ?int $actorUserId
        ): App\Library\NicheBlueprint\Adapters\InstalledComponentReference {
            return new App\Library\NicheBlueprint\Adapters\InstalledComponentReference('crm_pipeline', 1);
        }
    }
);

$argv = $_SERVER['argv'];
$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);

/** @return callable():void */
function delegate(string $mode, array $args): callable
{
    $publisher = app(App\Library\NicheBlueprint\NicheBlueprintPublisher::class);

    return match ($mode) {
        'create-draft' => function () use ($publisher, $args): void {
            $blueprint = App\Models\NicheBlueprint::findOrFail((int) $args[0]);
            $version = $publisher->createDraftVersion((int) $args[1], $blueprint);
            fwrite(STDOUT, 'DRAFT ' . $version->id . ' v' . $version->version_number . "\n");
        },
        'publish' => function () use ($publisher, $args): void {
            $version = App\Models\NicheBlueprintVersion::findOrFail((int) $args[0]);
            $published = $publisher->publishVersion((int) $args[1], $version);
            fwrite(STDOUT, 'PUBLISHED ' . $published->id . "\n");
        },
        'add-component' => function () use ($publisher, $args): void {
            $version = App\Models\NicheBlueprintVersion::findOrFail((int) $args[0]);
            $component = $publisher->addDraftComponent(
                (int) $args[1],
                $version,
                (string) $args[2],
                'crm_pipeline',
                'crm',
                ['pipeline_key' => 'sales']
            );
            fwrite(STDOUT, 'COMPONENT ' . $component->id . "\n");
        },
        default => throw new InvalidArgumentException("Unknown mode [{$mode}]."),
    };
}

try {
    if ($mode === 'hold-then') {
        $lockSpecs = (string) ($args[0] ?? '');
        $holdSeconds = (float) ($args[1] ?? 0);
        $delegateMode = (string) ($args[2] ?? '');
        $delegateArgs = array_slice($args, 3);

        Illuminate\Support\Facades\DB::transaction(function () use ($lockSpecs, $holdSeconds, $delegateMode, $delegateArgs): void {
            foreach (array_filter(explode('|', $lockSpecs)) as $spec) {
                [$table, $column, $id] = explode(':', $spec);

                Illuminate\Support\Facades\DB::table($table)->where($column, $id)->lockForUpdate()->get();
            }

            fwrite(STDOUT, "LOCKED\n");
            flush();

            usleep((int) ($holdSeconds * 1_000_000));

            delegate($delegateMode, $delegateArgs)();
        });

        exit(0);
    }

    delegate($mode, $args)();
    exit(0);
} catch (App\Exceptions\NicheBlueprint\BlueprintAuthoringException|App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException $e) {
    fwrite(STDERR, 'REFUSED ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
} catch (Illuminate\Auth\Access\AuthorizationException $e) {
    fwrite(STDERR, 'REFUSED AuthorizationException: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(2);
}
