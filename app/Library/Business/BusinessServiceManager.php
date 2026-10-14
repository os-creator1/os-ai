<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessServiceStatus;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settings → Business → Services: the Business's own editor for its BusinessServices.
 *
 * A BusinessService is a service the Business OFFERS (what the website and local SEO are about). It is not a
 * CatalogItem (something sold, priced and invoiced) and shares nothing with the catalog.
 *
 * There is no second persistence path here. Every change is expressed as the full service list and written by
 * BusinessManager::syncServices() -> BusinessServiceRepository::syncForBusiness(), the same canonical seam the
 * onboarding Services step uses, which already guarantees: a service is never deleted (one that is not wanted is
 * set Inactive), slugs and ids are stable (so Website / SEO mappings keep resolving), and exactly one active service is
 * primary whenever any active service exists.
 *
 * Authority is the one every other Business-setup mutation uses -- Workspace owner or active Admin, view-as aware
 * (BusinessLocationManager::canManage) -- re-checked here under the lock, whatever the controller did.
 *
 * Concurrency: the Business row is locked before anything is read, so two overlapping identical requests serialise
 * and the second reconciles against the first one's committed rows (it can neither duplicate a service nor collide on
 * a slug). Every operation is idempotent.
 */
final class BusinessServiceManager
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BusinessManager $businessManager,
        private readonly BusinessLocationManager $access,
    ) {
    }

    public function canManage(int $actorUserId, Business $business): bool
    {
        return $this->access->canManage($actorUserId, $business);
    }

    /** All services, active first (by their order), then inactive. */
    public function services(Business $business): Collection
    {
        return $business->services()
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Add a service, or -- when the Business already has one of that name -- return it (reactivating it if it was
     * inactive). Never creates a second service with the same name.
     */
    public function add(int $actorUserId, Business $business, string $name): BusinessService
    {
        $name = $this->cleanName($name);

        return $this->mutate($actorUserId, $business, function (Collection $existing, array &$rows) use ($name): ?int {
            $match = $existing->first(fn (BusinessService $s) => $this->key($s->name) === $this->key($name));

            if ($match !== null) {
                $this->patch($rows, $match->id, ['status' => BusinessServiceStatus::Active->value]);

                return $match->id;
            }

            $rows[] = ['name' => $name, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => ($existing->max('sort_order') ?? -1) + 1, 'is_primary' => false];

            return null;
        }, $name);
    }

    public function rename(int $actorUserId, Business $business, BusinessService $service, string $name): BusinessService
    {
        $name = $this->cleanName($name);

        return $this->mutate($actorUserId, $business, function (Collection $existing, array &$rows) use ($service, $name): int {
            $this->assertOwned($existing, $service);

            $clash = $existing->first(fn (BusinessService $s) => $s->id !== $service->id && $this->key($s->name) === $this->key($name));

            if ($clash !== null) {
                throw new InvalidArgumentException('You already have a service with that name.');
            }

            $this->patch($rows, $service->id, ['name' => $name]);

            return $service->id;
        });
    }

    public function activate(int $actorUserId, Business $business, BusinessService $service): BusinessService
    {
        return $this->mutate($actorUserId, $business, function (Collection $existing, array &$rows) use ($service): int {
            $this->assertOwned($existing, $service);
            $this->patch($rows, $service->id, ['status' => BusinessServiceStatus::Active->value]);

            return $service->id;
        });
    }

    /** Never deletes: the service stays, inactive. If it was the primary, the first remaining active service becomes primary. */
    public function deactivate(int $actorUserId, Business $business, BusinessService $service): BusinessService
    {
        return $this->mutate($actorUserId, $business, function (Collection $existing, array &$rows) use ($service): int {
            $this->assertOwned($existing, $service);
            $this->patch($rows, $service->id, ['status' => BusinessServiceStatus::Inactive->value, 'is_primary' => false]);

            return $service->id;
        });
    }

    public function makePrimary(int $actorUserId, Business $business, BusinessService $service): BusinessService
    {
        return $this->mutate($actorUserId, $business, function (Collection $existing, array &$rows) use ($service): int {
            $this->assertOwned($existing, $service);

            if ($existing->firstWhere('id', $service->id)->status !== BusinessServiceStatus::Active) {
                throw new InvalidArgumentException('Only an active service can be your primary service.');
            }

            foreach ($rows as $index => $row) {
                $rows[$index]['is_primary'] = isset($row['id']) && $row['id'] === $service->id;
            }

            return $service->id;
        });
    }

    /**
     * @param  callable(Collection, array): ?int  $change  edits the full row list; returns the affected service id
     *                                                    (null for a service the change appended)
     */
    private function mutate(int $actorUserId, Business $business, callable $change, ?string $createdName = null): BusinessService
    {
        return DB::transaction(function () use ($actorUserId, $business, $change, $createdName): BusinessService {
            // The Business row first, before any read: a stale snapshot taken earlier would hide a concurrent write.
            $locked = $this->businesses->findForUpdate((int) $business->id);

            if ($locked === null) {
                throw new InvalidArgumentException('That business no longer exists.');
            }

            if (! $this->access->canManage($actorUserId, $locked)) {
                throw new AuthorizationException('Only the account owner or an account admin can change this business\'s services.');
            }

            $existing = $locked->services()->get();

            // Today's list, unchanged but for the edit: ids, names, status, order and the primary flag. Fields the
            // sync is not given (description, price, images, website mappings) are left exactly as they are.
            $rows = $existing->map(fn (BusinessService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status->value,
                'sort_order' => (int) $s->sort_order,
                'is_primary' => (bool) $s->is_primary,
            ])->all();

            $affected = $change($existing, $rows);

            $owner = Customer::query()->where('user_id', $locked->customer_id)->firstOrFail();
            $synced = $this->businessManager->syncServices($owner, $locked, $rows);

            return $affected !== null
                ? $synced->firstWhere('id', $affected)
                : $synced->first(fn (BusinessService $s) => $this->key($s->name) === $this->key((string) $createdName));
        }, 3);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function patch(array &$rows, int $id, array $changes): void
    {
        foreach ($rows as $index => $row) {
            if (($row['id'] ?? null) === $id) {
                $rows[$index] = array_merge($row, $changes);
            }
        }
    }

    private function assertOwned(Collection $existing, BusinessService $service): void
    {
        if ($existing->firstWhere('id', $service->id) === null) {
            throw new InvalidArgumentException('That service does not belong to this business.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A service name needs 2 to 255 characters.');
        }

        return $name;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
