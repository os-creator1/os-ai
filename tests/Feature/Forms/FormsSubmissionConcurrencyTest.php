<?php

namespace Tests\Feature\Forms;

use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSession;
use App\Models\FormSubmission;
use Illuminate\Support\Str;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * TRUE concurrency for the Forms V1 idempotency boundary: separate OS
 * processes, separate database connections, no mocked lock.
 *
 * The overlap is made deterministic, not hoped for. The parent holds an
 * UNCOMMITTED row on a probe connection that both submitters must pass through,
 * starts BOTH, and only releases it after proving both are still running — i.e.
 * both are genuinely waiting at the same moment. They then race for real, and the
 * database must show exactly one logical submission:
 *
 *   - same token: the probe holds the (deployment, nonce) CLAIM itself, so both
 *     runners queue on the unique index — the real backstop — and one wins, the
 *     other converges on it.
 *   - distinct tokens, one person: the probe holds the Location+phone identity
 *     lock, so both queue on the Contact boundary — one Contact, two submissions.
 *
 * Deliberately does NOT use RefreshDatabase (a child process can only see
 * committed rows). The database is the lane's own TestDatabaseSafety-approved
 * sibling; it is rebuilt with migrate:fresh around the tests, which is only
 * permitted after TestDatabaseSafety has approved the name.
 */
class FormsSubmissionConcurrencyTest extends TestCase
{
    use CreatesFormsFixtures;

    private const PROBE_CONNECTION = 'mysql_forms_race_probe';

    private Business $business;

    private BusinessLocation $location;

    private Form $form;

    private FormDeployment $deployment;

    /** Rebuilt once per class on the way in, and after every test on the way out. */
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseSafety::activeTestDatabase();

        if (! self::$schemaReady) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$schemaReady = true;
        }
    }

    protected function tearDown(): void
    {
        // Committed fixtures must not leak: leave the disposable database as
        // RefreshDatabase expects to find it (migrated, empty of fixtures).
        TestDatabaseSafety::activeTestDatabase();
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    private function fixture(bool $withOpportunity = false): void
    {
        [, $this->business] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business, 'Race Downtown');
        $this->formsPipeline($this->business);
        $this->form = app(FormManager::class)->create($this->business, $this->leadFormInput([
            'create_opportunity' => $withOpportunity,
            'fields' => [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true],
            ],
        ]));
        app(FormManager::class)->activate($this->business, $this->form);
        $this->deployment = $this->deploy($this->business, $this->form, $this->location);
    }

    /** A committed three-page questionnaire deployed at the Location. */
    private function questionnaireFixture(): void
    {
        [, $this->business] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business, 'Race Downtown');
        $this->formsPipeline($this->business);
        $this->form = $this->makeQuestionnaire($this->business);
        $this->deployment = $this->deploy($this->business, $this->form, $this->location);
    }

    /** An UNCOMMITTED claim for (deployment, nonce), so runners queue on the unique index. */
    private function holdClaim(Connection $probe, string $token): void
    {
        $probe->table('form_submissions')->insert([
            'uid' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'business_location_id' => $this->location->id,
            'form_id' => $this->form->id,
            'form_version_id' => $this->form->currentVersion()->id,
            'form_deployment_id' => $this->deployment->id,
            'source' => 'direct_link',
            'operation_nonce' => explode('.', $token)[0],
            'payload_hash' => str_repeat('0', 64),
            'values' => '{}',
            'contact_resolution' => 'none',
            'occurrence_key' => 'form_submission:probe',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stepRunner(string $token, string $page): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $answers = json_encode(array_merge($this->questionnaireAnswers()[$page], ['page' => $page]));

        return new Process([$php, __DIR__.'/Support/concurrent_form_submit_runner.php', $this->deployment->uid, $token, '-', $answers], null, $this->childEnvironment());
    }

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }

    private function runner(string $token, string $phone): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        return new Process([$php, __DIR__.'/Support/concurrent_form_submit_runner.php', $this->deployment->uid, $token, $phone], null, $this->childEnvironment());
    }

    private function probe(): Connection
    {
        config(['database.connections.'.self::PROBE_CONNECTION => config('database.connections.mysql')]);
        DB::purge(self::PROBE_CONNECTION);
        $probe = DB::connection(self::PROBE_CONNECTION);
        $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());

        return $probe;
    }

    /**
     * Holds whatever $hold takes on a second connection, starts every runner,
     * proves they are all blocked behind it, then releases.
     *
     * @param  callable(Connection): void  $hold
     * @param  list<Process>  $processes
     */
    private function raceBehind(callable $hold, array $processes): void
    {
        $probe = $this->probe();
        $probe->beginTransaction();

        try {
            $hold($probe);

            foreach ($processes as $process) {
                $process->start();
            }

            // Long enough for both to boot, connect and queue on the held row.
            usleep(3_000_000);

            foreach ($processes as $i => $process) {
                $this->assertTrue($process->isRunning(), 'runner '.$i.' finished while the row was held — it never waited: '.$process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame(0, FormSubmission::count(), 'nothing may be committed while the row is held');
        } finally {
            $probe->rollBack();
            DB::purge(self::PROBE_CONNECTION);
            config(['database.connections.'.self::PROBE_CONNECTION => null]);
        }

        foreach ($processes as $process) {
            $process->wait();
        }
    }

    /** @return array{submission_id: int, replayed: bool, events: int} */
    private function outcome(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), 'runner failed: '.$process->getErrorOutput().$process->getOutput());

        return json_decode(trim($process->getOutput()), true);
    }

    public function test_two_simultaneous_posts_of_one_token_converge_on_one_of_everything(): void
    {
        $this->fixture(true);
        $token = FormOperationToken::issue($this->deployment);
        $a = $this->runner($token, '5551230001');
        $b = $this->runner($token, '5551230001');

        // Both queue on the unique (deployment, nonce) index behind a competing
        // uncommitted claim for the very same token.
        $this->raceBehind(function (Connection $probe) use ($token): void {
            $probe->table('form_submissions')->insert([
                'uid' => (string) \Illuminate\Support\Str::uuid(),
                'business_id' => $this->business->id,
                'business_location_id' => $this->location->id,
                'form_id' => $this->form->id,
                'form_version_id' => $this->form->currentVersion()->id,
                'form_deployment_id' => $this->deployment->id,
                'source' => 'direct_link',
                'operation_nonce' => explode('.', $token)[0],
                'payload_hash' => str_repeat('0', 64),
                'values' => '{}',
                'contact_resolution' => 'none',
                'occurrence_key' => 'form_submission:probe',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, [$a, $b]);

        [$ra, $rb] = [$this->outcome($a), $this->outcome($b)];

        $this->assertSame(1, FormSubmission::count(), 'two submission rows');
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count(), 'two Contacts');
        $this->assertSame(1, CrmOpportunity::where('business_id', $this->business->id)->count(), 'two Opportunities');

        $this->assertSame($ra['submission_id'], $rb['submission_id'], 'both callers converge on the one logical submission');
        $this->assertSame(1, (int) ! $ra['replayed'] + (int) ! $rb['replayed'], 'exactly one caller created it, the other replayed');
        $this->assertSame(1, $ra['events'] + $rb['events'], 'exactly one event across both processes');
    }

    public function test_two_simultaneous_posts_of_one_token_with_nothing_held_still_converge(): void
    {
        $this->fixture(true);
        $token = FormOperationToken::issue($this->deployment);
        $a = $this->runner($token, '5551230009');
        $b = $this->runner($token, '5551230009');

        // No probe: a plain race. Whoever claims first wins; the other blocks on
        // the winner's uncommitted claim and then replays.
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        [$ra, $rb] = [$this->outcome($a), $this->outcome($b)];

        $this->assertSame(1, FormSubmission::count());
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count());
        $this->assertSame(1, CrmOpportunity::where('business_id', $this->business->id)->count());
        $this->assertSame($ra['submission_id'], $rb['submission_id']);
        $this->assertSame(1, $ra['events'] + $rb['events']);
    }

    public function test_two_simultaneous_distinct_submissions_from_one_person_share_one_contact(): void
    {
        $this->fixture(true);
        $a = $this->runner(FormOperationToken::issue($this->deployment), '5551230002');
        $b = $this->runner(FormOperationToken::issue($this->deployment), '5551230002');

        // Both queue on the Location+phone identity lock — the Contact boundary.
        // The lock row is COMMITTED first (as it is for any phone seen before) and
        // the probe then holds it FOR UPDATE, so the runners queue on a lock, not
        // on an uncommitted insert.
        $this->raceBehind(function (Connection $probe): void {
            $key = ['business_location_id' => $this->location->id, 'normalized_phone' => '5551230002'];
            DB::table('booking_contact_identity_locks')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);
            $probe->table('booking_contact_identity_locks')->where($key)->lockForUpdate()->first();
        }, [$a, $b]);

        [$ra, $rb] = [$this->outcome($a), $this->outcome($b)];

        // Two different renders are two inquiries; the person is one.
        $this->assertSame(2, FormSubmission::count());
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count(), 'a race created a duplicate Contact');
        $this->assertSame(2, CrmOpportunity::where('business_id', $this->business->id)->count());
        $this->assertNotSame($ra['submission_id'], $rb['submission_id']);
        $this->assertSame(1, FormSubmission::where('contact_resolution', 'created')->count());
        $this->assertSame(1, FormSubmission::where('contact_resolution', 'matched')->count());
        $this->assertSame(2, $ra['events'] + $rb['events']);
    }

    public function test_two_simultaneous_final_steps_of_one_questionnaire_converge_on_one_of_everything(): void
    {
        $this->questionnaireFixture();
        $token = FormOperationToken::issue($this->deployment);
        $service = app(\App\Library\Forms\FormSubmissionService::class);

        // Pages 1 and 2 are completed (and committed) by the visitor beforehand.
        foreach (['page_1', 'page_2'] as $page) {
            $service->submit($this->deployment->uid, $this->stepInput($token, $page));
        }
        $this->assertSame(0, FormSubmission::count());

        // The double-clicked FINAL step: two processes, one token, one held claim.
        $a = $this->stepRunner($token, 'page_3');
        $b = $this->stepRunner($token, 'page_3');
        $this->raceBehind(fn (Connection $probe) => $this->holdClaim($probe, $token), [$a, $b]);

        [$ra, $rb] = [$this->outcome($a), $this->outcome($b)];

        $this->assertSame(1, FormSubmission::count(), 'two submissions');
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count(), 'two Contacts');
        $this->assertSame(1, CrmOpportunity::where('business_id', $this->business->id)->count(), 'two Opportunities');
        $this->assertNotNull($ra['submission_id']);
        $this->assertSame($ra['submission_id'], $rb['submission_id']);
        $this->assertSame(1, (int) ! $ra['replayed'] + (int) ! $rb['replayed'], 'exactly one created it, the other replayed');
        $this->assertSame(1, $ra['events'] + $rb['events'], 'exactly one event across both processes');

        $session = FormSession::firstOrFail();
        $this->assertSame((int) $ra['submission_id'], (int) $session->form_submission_id, 'the one session is stamped with the one submission');
        $this->assertNotNull($session->finalized_at);
    }

    public function test_two_simultaneous_first_page_saves_create_one_session_and_no_final_state(): void
    {
        $this->questionnaireFixture();
        $token = FormOperationToken::issue($this->deployment);
        $a = $this->stepRunner($token, 'page_1');
        $b = $this->stepRunner($token, 'page_1');

        // A plain race: the unique (deployment, nonce) index decides who creates
        // the session; the loser retries onto it instead of failing.
        $a->start();
        $b->start();
        $a->wait();
        $b->wait();
        [$ra, $rb] = [$this->outcome($a), $this->outcome($b)];

        $this->assertSame(1, FormSession::count(), 'two sessions for one start');
        $this->assertSame(['page_1'], FormSession::firstOrFail()->completed_pages);
        $this->assertSame('page_2', $ra['progress']);
        $this->assertSame('page_2', $rb['progress']);
        $this->assertNull($ra['submission_id']);
        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(0, Contacts::where('business_id', $this->business->id)->count(), 'a partial page creates no Contact');
        $this->assertSame(0, $ra['events'] + $rb['events']);
    }

    public function test_the_runner_refuses_to_run_against_an_unexpected_database(): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $process = new Process([$php, __DIR__.'/Support/concurrent_form_submit_runner.php', 'x', '-', '5550000000'], null, [
            'DB_DATABASE' => TestDatabaseSafety::activeTestDatabase(),
            'EXPECTED_TEST_DATABASE' => 'ultimatesms_testing_some_other_lane',
        ]);
        $process->run();

        $this->assertSame(3, $process->getExitCode());
    }
}
