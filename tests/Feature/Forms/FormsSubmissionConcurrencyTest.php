<?php

namespace Tests\Feature\Forms;

use App\Library\Crm\CrmPipelineService;
use App\Library\Website\WebsiteFormPresets;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * TRUE concurrency for the Forms V1 idempotency boundary: separate OS
 * processes, separate database connections, no mocked lock.
 *
 * The overlap is made deterministic, not hoped for: the parent holds the
 * website_forms row lock on a probe connection, starts BOTH submitters, and
 * only releases the lock after proving both are still running — i.e. both
 * are genuinely waiting on the same form at the same moment. They then
 * proceed serialized and the database must show exactly one logical
 * submission.
 *
 * Deliberately does NOT use RefreshDatabase (a child process can only see
 * committed rows); fixtures are committed and removed in tearDown().
 */
class FormsSubmissionConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_forms_lock_probe';

    private ?User $user = null;

    private ?Workspace $workspace = null;

    private ?Business $business = null;

    private ?Website $website = null;

    private ?WebsiteForm $form = null;

    protected function tearDown(): void
    {
        if ($this->business !== null) {
            DB::table('crm_opportunities')->where('business_id', $this->business->id)->delete();
            ContactGroups::where('business_id', $this->business->id)->delete();
            DB::table('booking_contact_identity_locks')
                ->whereIn('business_location_id', BusinessLocation::where('business_id', $this->business->id)->pluck('id'))
                ->delete();
        }

        // Cascades to website_forms -> website_form_submissions.
        $this->website?->delete();
        if ($this->business !== null) {
            BusinessLocation::where('business_id', $this->business->id)->delete();
        }
        $this->business?->delete();
        $this->workspace?->delete();
        $this->user?->delete();

        parent::tearDown();
    }

    private function fixture(): void
    {
        $this->assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName());

        $this->user = User::create([
            'first_name' => 'Forms', 'last_name' => 'Race',
            'email' => 'forms-race-'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $this->workspace = Workspace::create(['name' => 'Forms Race Workspace', 'owner_user_id' => $this->user->id, 'is_active' => true]);
        $this->business = new Business([
            'customer_id' => $this->user->id, 'name' => 'Forms Race Booths', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);
        $this->business->workspace_id = $this->workspace->id;
        $this->business->save();

        $location = BusinessLocation::create(['business_id' => $this->business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        app(CrmPipelineService::class)->setUpStandardPipeline($this->business);
        $this->website = Website::create(['business_id' => $this->business->id, 'name' => 'Forms Race Site']);
        $this->form = $this->website->forms()->create([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Send',
            'location_id' => $location->id,
        ]);
    }

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }

    private function runner(string $token, string $phone): Process
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        return new Process([$php, __DIR__.'/Support/concurrent_form_submit_runner.php', (string) $this->form->id, $token, $phone], null, $this->childEnvironment());
    }

    /**
     * Holds the form row lock on a second connection, starts every runner,
     * proves they are all blocked behind it, then releases.
     *
     * @param  list<Process>  $processes
     */
    private function raceBehindTheFormLock(array $processes): void
    {
        config(['database.connections.'.self::PROBE_CONNECTION => config('database.connections.mysql')]);
        DB::purge(self::PROBE_CONNECTION);
        /** @var Connection $probe */
        $probe = DB::connection(self::PROBE_CONNECTION);
        $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());

        $probe->beginTransaction();

        try {
            $probe->select('SELECT * FROM website_forms WHERE id = ? FOR UPDATE', [$this->form->id]);

            foreach ($processes as $process) {
                $process->start();
            }

            // Long enough for both to boot, connect and queue on the lock.
            usleep(3_000_000);

            foreach ($processes as $i => $process) {
                $this->assertTrue($process->isRunning(), 'runner '.$i.' finished while the form lock was held — it never waited: '.$process->getErrorOutput().$process->getOutput());
            }
            $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $this->form->id)->count(), 'nothing may be written while the form is locked');
        } finally {
            $probe->rollBack();
            DB::purge(self::PROBE_CONNECTION);
            config(['database.connections.'.self::PROBE_CONNECTION => null]);
        }

        foreach ($processes as $process) {
            $process->wait();
        }
    }

    public function test_two_simultaneous_submits_of_one_logical_submission_produce_one_of_everything(): void
    {
        $this->fixture();
        $token = (string) Str::uuid();
        $a = $this->runner($token, '5551230001');
        $b = $this->runner($token, '5551230001');

        $this->raceBehindTheFormLock([$a, $b]);

        $this->assertTrue($a->isSuccessful(), 'A failed: '.$a->getErrorOutput());
        $this->assertTrue($b->isSuccessful(), 'B failed: '.$b->getErrorOutput());

        $results = [json_decode(trim($a->getOutput()), true), json_decode(trim($b->getOutput()), true)];

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $this->form->id)->count(), 'two submission rows');
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count(), 'two Contacts');
        $this->assertSame(1, CrmOpportunity::where('business_id', $this->business->id)->count(), 'two Opportunities');

        $this->assertSame($results[0]['submission_id'], $results[1]['submission_id'], 'both callers get the one logical submission');
        $this->assertSame(1, (int) $results[0]['created'] + (int) $results[1]['created'], 'exactly one caller created it');
        $this->assertSame(1, $results[0]['events'] + $results[1]['events'], 'duplicate durable events');
    }

    public function test_two_simultaneous_distinct_submits_from_one_person_share_one_contact(): void
    {
        $this->fixture();
        $a = $this->runner((string) Str::uuid(), '5551230002');
        $b = $this->runner((string) Str::uuid(), '5551230002');

        $this->raceBehindTheFormLock([$a, $b]);

        $this->assertTrue($a->isSuccessful(), 'A failed: '.$a->getErrorOutput());
        $this->assertTrue($b->isSuccessful(), 'B failed: '.$b->getErrorOutput());

        // Two different page renders are two inquiries; the person is one.
        $this->assertSame(2, WebsiteFormSubmission::where('website_form_id', $this->form->id)->count());
        $this->assertSame(1, Contacts::where('business_id', $this->business->id)->count());
        $this->assertSame(2, CrmOpportunity::where('business_id', $this->business->id)->count());
        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $this->form->id)->where('contact_resolution', 'created')->count());
        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $this->form->id)->where('contact_resolution', 'matched')->count());
    }

    public function test_the_runner_refuses_to_run_against_an_unexpected_database(): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $process = new Process([$php, __DIR__.'/Support/concurrent_form_submit_runner.php', '1', '-', '5550000000'], null, [
            'DB_DATABASE' => TestDatabaseSafety::activeTestDatabase(),
            'EXPECTED_TEST_DATABASE' => 'ultimatesms_testing_some_other_lane',
        ]);
        $process->run();

        $this->assertSame(3, $process->getExitCode());
    }
}
