<?php

namespace Tests\Feature\Workspace\Support;

use App\Enums\Business\BusinessStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;

/**
 * A SECOND (or third, ...) Business in an already-occupied Workspace — the
 * literal pre-Contract-13 legacy shape Contract 10/12's real migration code
 * exists to repair. Every consumer of this trait MUST run inside
 * PreContract13HistoricalTestCase's disposable database, where the
 * businesses_workspace_id_unique constraint genuinely does not exist yet;
 * it is never a way to bypass that constraint anywhere else.
 *
 * Deliberately separate from Tests\Feature\Workspace\Concerns\
 * CreatesCustomerContextFixtures::addBusiness(), which is correctly
 * guarded to throw for a second Business in one Workspace on every OTHER
 * (current-V1) test — this trait exists precisely for the tests that must
 * defeat that guard on purpose, to seed the exact invalid topology the
 * migration under test is asked to fix. It still goes through the same
 * real BusinessRepository::createForCustomerInWorkspace() production seam
 * addBusiness() itself uses, minus only the one-Business check — never a
 * hand-rolled INSERT that could silently drift from what the real
 * creation seam actually persists.
 */
trait CreatesLegacyMultiBusinessFixtures
{
    /**
     * @return array<string, mixed> businessAttributes() defaults — provided
     *         by CreatesBusinessTestData, which every consumer of this
     *         trait is expected to also use (mirroring
     *         CreatesWorkspaceTestData's own documented requirement).
     */
    abstract protected function businessAttributes(array $overrides = []): array;

    /**
     * A Business persisted into $workspace regardless of how many
     * Businesses it already holds — the historical-only counterpart to
     * addBusiness(). Never call this against any database except a
     * PreContract13HistoricalTestCase disposable one.
     */
    protected function legacyBusiness(Customer $customer, Workspace $workspace, string $name, BusinessStatus $status = BusinessStatus::Active): Business
    {
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace(
            $customer,
            $workspace,
            $this->businessAttributes(['name' => $name]),
        );

        DB::table('businesses')->where('id', $business->id)->update(['status' => $status->value]);

        return $business->fresh();
    }
}
