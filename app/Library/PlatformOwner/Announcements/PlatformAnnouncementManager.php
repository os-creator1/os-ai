<?php

namespace App\Library\PlatformOwner\Announcements;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\PlatformAnnouncementStatus as Status;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Models\PlatformAnnouncement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform Owner V1 final — the announcement lifecycle.
 *
 *   Draft ──schedule──▶ Scheduled ──(due / publish)──▶ Published ──(expires_at)──▶ Expired
 *     │                     │                              │
 *     └──────────cancel─────┴──────────────────────────────┘ ▶ Cancelled
 *
 * Expired is derived, never stored (PlatformAnnouncement::effectiveStatus()).
 * Delivery is the PlatformAnnouncementDelivery seam; this class never sends.
 * Every transition is audited. sweepDue() is the one entry the Platform
 * Automations scheduler (or any cron) calls to publish scheduled rows whose
 * time has come; it is idempotent.
 */
class PlatformAnnouncementManager
{
    public const CHANNELS = ['in_app', 'email'];

    public function __construct(
        private readonly PlatformAnnouncementDelivery $delivery,
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    /**
     * @param  array{title: string, body: string, audience: string, audience_tiers?: array<int, string>, channels: array<int, string>, expires_at?: ?CarbonInterface}  $data
     */
    public function createDraft(int $actorId, array $data): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $fields = $this->normalise($data);

        $a = PlatformAnnouncement::create($fields + ['status' => Status::Draft->value, 'created_by' => $actorId, 'updated_by' => $actorId]);
        $this->audit->record($actorId, 'announcement.created', 'announcement', $a->uid, 'Created announcement draft "' . $a->title . '"');

        return $a;
    }

    /** Draft and Scheduled announcements can be edited; anything else is final. */
    public function update(int $actorId, PlatformAnnouncement $a, array $data): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled], 'Only a draft or scheduled announcement can be edited.');

        $a->fill($this->normalise($data) + ['updated_by' => $actorId])->save();
        $this->audit->record($actorId, 'announcement.updated', 'announcement', $a->uid, 'Edited announcement "' . $a->title . '"');

        return $a;
    }

    public function schedule(int $actorId, PlatformAnnouncement $a, CarbonInterface $at): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled], 'Only a draft or scheduled announcement can be scheduled.');

        if ($at->isPast()) {
            throw ValidationException::withMessages(['scheduled_at' => __('Pick a time in the future.')]);
        }

        if ($a->expires_at !== null && $a->expires_at->lte($at)) {
            throw ValidationException::withMessages(['expires_at' => __('The expiry must be after the scheduled time.')]);
        }

        $a->forceFill(['status' => Status::Scheduled->value, 'scheduled_at' => $at, 'updated_by' => $actorId])->save();
        $this->audit->record($actorId, 'announcement.scheduled', 'announcement', $a->uid, 'Scheduled "' . $a->title . '" for ' . $at->toIso8601String());

        return $a;
    }

    public function publishNow(int $actorId, PlatformAnnouncement $a): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled], 'Only a draft or scheduled announcement can be published.');

        if ($a->expires_at !== null && $a->expires_at->isPast()) {
            throw ValidationException::withMessages(['expires_at' => __('The expiry has already passed.')]);
        }

        $this->publish($a, $actorId);
        $this->audit->record($actorId, 'announcement.published', 'announcement', $a->uid, 'Published "' . $a->title . '"');

        return $a;
    }

    public function cancel(int $actorId, PlatformAnnouncement $a): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled, Status::Published], 'This announcement can no longer be cancelled.');

        $wasPublished = $a->status === Status::Published;
        $a->forceFill(['status' => Status::Cancelled->value, 'cancelled_at' => now(), 'updated_by' => $actorId])->save();

        if ($wasPublished) {
            $this->delivery->withdraw($a);
        }

        $this->audit->record($actorId, 'announcement.cancelled', 'announcement', $a->uid, 'Cancelled "' . $a->title . '"');

        return $a;
    }

    /**
     * Publish every Scheduled announcement whose time has come. Safe to run
     * concurrently and repeatedly: each row is re-read under a lock and only
     * published if it is still Scheduled.
     *
     * @return int number published
     */
    public function sweepDue(?int $systemActorId = null): int
    {
        $count = 0;
        $ids = PlatformAnnouncement::query()->where('status', Status::Scheduled->value)->where('scheduled_at', '<=', now())->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count, $systemActorId) {
                $a = PlatformAnnouncement::query()->lockForUpdate()->find($id);

                if ($a && $a->status === Status::Scheduled) {
                    $this->publish($a, $systemActorId);
                    $count++;
                }
            });
        }

        return $count;
    }

    private function publish(PlatformAnnouncement $a, ?int $actorId): void
    {
        $ref = $this->delivery->deliver($a);

        $a->forceFill([
            'status' => Status::Published->value,
            'published_at' => now(),
            'delivery_ref' => $ref,
            'updated_by' => $actorId ?? $a->updated_by,
        ])->save();
    }

    /** @param  list<Status>  $allowed */
    private function assertIn(PlatformAnnouncement $a, array $allowed, string $message): void
    {
        if (! in_array($a->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __($message)]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $audience = (string) ($data['audience'] ?? 'all');
        $tiers = array_values(array_unique((array) ($data['audience_tiers'] ?? [])));
        $channels = array_values(array_unique((array) ($data['channels'] ?? [])));

        if ($title === '') {
            $errors['title'] = __('Give the announcement a title.');
        }

        if ($body === '') {
            $errors['body'] = __('Write the announcement message.');
        }

        if (! in_array($audience, ['all', 'tiers'], true)) {
            $errors['audience'] = __('Choose who should receive it.');
        }

        if ($audience === 'tiers') {
            $valid = array_map(fn (WorkspacePlanTier $t) => $t->value, WorkspacePlanTier::cases());

            if ($tiers === [] || array_diff($tiers, $valid) !== []) {
                $errors['audience_tiers'] = __('Pick at least one plan.');
            }
        } else {
            $tiers = [];
        }

        if ($channels === [] || array_diff($channels, self::CHANNELS) !== []) {
            $errors['channels'] = __('Pick at least one channel.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'audience_tiers' => $tiers === [] ? null : $tiers,
            'channels' => $channels,
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }
}
