<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real concurrency coverage for WebsiteFormSubmissionService::submit()'s
 * duplicate check (a genuine second-connection lock probe, mirroring
 * WorkspaceManagerConcurrencyTest's exact technique — no mocked
 * lockForUpdate()).
 *
 * Deliberately does NOT use RefreshDatabase: the probe connection needs
 * to see committed rows a different connection wrote, which an open
 * RefreshDatabase transaction would hide entirely. Fixtures are created
 * directly with plain Eloquent creates (auto-committing outside any
 * wrapping transaction) and removed in tearDown().
 */
class WebsiteFormSubmissionConcurrencyTest extends TestCase
{
    private const PROBE_CONNECTION = 'mysql_website_form_lock_probe';

    private const LOCK_WAIT_TIMEOUT_SECONDS = 2;

    private const MAX_ELAPSED_SECONDS = 8.0;

    private const MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE = 1205;

    private ?User $user = null;

    private ?Workspace $workspace = null;

    private ?Business $business = null;

    private ?Website $website = null;

    private ?WebsiteForm $form = null;

    protected function tearDown(): void
    {
        if ($this->business !== null) {
            // Cascades to contacts (contacts.group_id -> onDelete cascade).
            ContactGroups::where('business_id', $this->business->id)->delete();
        }

        if ($this->business !== null) {
            \Illuminate\Support\Facades\DB::table('booking_contact_identity_locks')
                ->whereIn('business_location_id', \App\Models\BusinessLocation::where('business_id', $this->business->id)->pluck('id'))
                ->delete();
        }

        // Cascades to website_forms -> website_form_submissions.
        $this->website?->delete();
        if ($this->business !== null) {
            \App\Models\BusinessLocation::where('business_id', $this->business->id)->delete();
        }
        $this->business?->delete();
        $this->workspace?->delete();
        $this->user?->delete();

        parent::tearDown();
    }

    public function test_the_form_row_lock_serializes_two_submissions_racing_for_the_same_form(): void
    {
        $this->user = User::create([
            'first_name' => 'Lock',
            'last_name' => 'Probe',
            'email' => 'lock-probe-'.uniqid('', true).'@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
        $this->workspace = Workspace::create([
            'name' => 'Lock Probe Workspace',
            'owner_user_id' => $this->user->id,
            'is_active' => true,
        ]);
        $this->business = new Business([
            'customer_id' => $this->user->id,
            'name' => 'Lock Probe Booths',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ]);
        // workspace_id is deliberately not mass-assignable; set directly.
        $this->business->workspace_id = $this->workspace->id;
        $this->business->save();

        $this->website = Website::create([
            'business_id' => $this->business->id,
            'name' => 'Lock Probe Site',
        ]);
        // Forms V1: a form carries its Location (a form with none accepts nothing).
        $location = \App\Models\BusinessLocation::create(['business_id' => $this->business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->form = $this->website->forms()->create([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Send',
            'location_id' => $location->id,
        ]);

        $probe = $this->setUpProbeConnection();
        $this->assertConnectionsAreGenuinelyDistinct($probe);

        $probe->beginTransaction();

        try {
            // Genuine row lock on the WebsiteForm row, held open on the
            // probe connection — never released until rollBack() below.
            $probe->select('SELECT * FROM website_forms WHERE id = ? FOR UPDATE', [$this->form->id]);

            $caught = null;
            $start = microtime(true);

            $this->withShortLockWaitTimeout(function () use (&$caught) {
                try {
                    app(WebsiteFormSubmissionService::class)->submit(
                        $this->form,
                        WebsiteFormPresets::photoBoothQuoteRequest(),
                        'Quote Request',
                        ['name' => 'Racer', 'phone' => '5550000001'],
                        'quote',
                        '127.0.0.1',
                    );
                } catch (QueryException $e) {
                    $caught = $e;
                }
            });

            $elapsed = microtime(true) - $start;

            $this->assertLessThan(
                self::MAX_ELAPSED_SECONDS,
                $elapsed,
                'submit() did not fail within the bounded lock-wait window — it may have hung.'
            );
            $this->assertNotNull(
                $caught,
                'Expected submit() to fail with a MySQL lock-wait-timeout QueryException while the WebsiteForm row was locked by another connection.'
            );
            $this->assertSame(self::MYSQL_LOCK_WAIT_TIMEOUT_ERROR_CODE, $caught->errorInfo[1] ?? null);

            // The blocked attempt never got far enough to write a row.
            $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $this->form->id)->count());
        } finally {
            $probe->rollBack();
        }

        $this->tearDownProbeConnection();

        // Once the lock is released, the exact same submission succeeds
        // normally — the lock serializes, it does not permanently wedge.
        app(WebsiteFormSubmissionService::class)->submit(
            $this->form,
            WebsiteFormPresets::photoBoothQuoteRequest(),
            'Quote Request',
            ['name' => 'Racer', 'phone' => '5550000001'],
            'quote',
            '127.0.0.1',
        );
        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $this->form->id)->count());
    }

    private function setUpProbeConnection(): Connection
    {
        config(['database.connections.'.self::PROBE_CONNECTION => config('database.connections.mysql')]);
        DB::purge(self::PROBE_CONNECTION);

        return DB::connection(self::PROBE_CONNECTION);
    }

    private function tearDownProbeConnection(): void
    {
        DB::purge(self::PROBE_CONNECTION);
        config(['database.connections.'.self::PROBE_CONNECTION => null]);
    }

    private function assertConnectionsAreGenuinelyDistinct(Connection $probe): void
    {
        $default = DB::connection();

        $this->assertSame('mysql', $default->getDriverName());
        $this->assertSame('mysql', $probe->getDriverName());
        $this->assertSame($default->getDatabaseName(), $probe->getDatabaseName());
        $this->assertNotSame($default->getPdo(), $probe->getPdo());
    }

    /**
     * Bounds how long a blocked query can wait before failing, so a real
     * lock-contention proof cannot hang the suite — restoring the
     * connection's original value afterward regardless of outcome.
     */
    private function withShortLockWaitTimeout(callable $callback): void
    {
        $original = DB::connection()->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;

        DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT_SECONDS);

        try {
            $callback();
        } finally {
            DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $original);
        }
    }
}
