<?php

namespace App\Library\Tasks;

use App\Models\Business;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Creates and completes contact tasks. Every read/write is keyed by the Business. */
final class ContactTaskManager
{
    /**
     * Idempotent per (enrollment, node): a replay returns the existing task and writes nothing.
     *
     * @return array{id: int, created: bool}
     */
    public function createForStep(Business $business, Contacts $contact, int $enrollmentId, int $nodeId, string $title, ?Carbon $dueAt, ?int $locationId = null, string $kind = 'call'): array
    {
        if ((int) $contact->business_id !== (int) $business->id) {
            throw new InvalidArgumentException('That contact is not part of this Business.');
        }

        $existing = DB::table('contact_tasks')->where('enrollment_id', $enrollmentId)->where('node_id', $nodeId)->where('business_id', $business->id)->value('id');
        if ($existing !== null) {
            return ['id' => (int) $existing, 'created' => false];
        }

        try {
            $id = DB::table('contact_tasks')->insertGetId([
                'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'location_id' => $locationId, 'contact_id' => $contact->id,
                'enrollment_id' => $enrollmentId, 'node_id' => $nodeId, 'kind' => $kind, 'title' => Str::limit($title, 255, ''),
                'due_at' => $dueAt?->clone()->utc(), 'status' => 'open', 'source' => 'automation', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['id' => (int) DB::table('contact_tasks')->where('enrollment_id', $enrollmentId)->where('node_id', $nodeId)->value('id'), 'created' => false];
        }

        return ['id' => (int) $id, 'created' => true];
    }

    public function complete(Business $business, int $taskId, ?int $userId): bool
    {
        return DB::table('contact_tasks')->where('business_id', $business->id)->where('id', $taskId)->where('status', 'open')
            ->update(['status' => 'done', 'completed_at' => now(), 'completed_by_user_id' => $userId, 'updated_at' => now()]) === 1;
    }

    /** Open tasks of one Business, soonest due first. */
    public function open(Business $business, int $limit = 50): array
    {
        return DB::table('contact_tasks')->where('business_id', $business->id)->where('status', 'open')
            ->orderByRaw('due_at is null, due_at')->limit($limit)->get()->all();
    }
}
