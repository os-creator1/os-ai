<?php

namespace App\Library\PlatformOwner\Announcements;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\PlatformAnnouncementStatus as Status;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager as CanonicalManager;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Models\PlatformAnnouncement;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * The Platform Owner's face on announcements: authority, the owner-facing validation messages
 * and the audit trail. It holds NO state logic of its own. Every write and every transition
 * goes through the one canonical manager (App\Library\PlatformAutomation\Announcements\
 * PlatformAnnouncementManager), whose publish() calls the PlatformAnnouncementDelivery seam
 * (bound to CanonicalPlatformAnnouncementDelivery) — so there is exactly one announcement
 * authority, one table, one delivery runtime and one banner/notification/email path, shared
 * with the Platform Automations "send announcement" action.
 *
 * "In the app" in the Platform Owner form is the banner + notification channels.
 */
class PlatformAnnouncementManager
{
    public const CHANNELS = ['in_app', 'email'];

    public function __construct(
        private readonly CanonicalManager $canonical,
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
    ) {
    }

    /** @param array<string, mixed> $data title, body, audience (all|tiers), audience_tiers, channels, expires_at */
    public function createDraft(int $actorId, array $data): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $fields = $this->normalise($data);

        $a = $this->canonical->create($this->toCanonical($fields), $actorId);
        $this->audit->record($actorId, 'announcement.created', 'announcement', $a->uid, 'Created announcement draft "' . $a->title . '"');

        return $a;
    }

    /** @param array<string, mixed> $data */
    public function update(int $actorId, PlatformAnnouncement $a, array $data): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled], 'Only a draft or scheduled announcement can be edited.');

        $input = $this->toCanonical($this->normalise($data)) + ['publish_at' => $a->publish_at];
        $a = $this->canonical->update($a, $input);
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

        $a = $this->canonical->update($a, $this->current($a, ['publish_at' => $at]));
        $a = $this->canonical->schedule($a);
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

        $a = $this->canonical->publishNow($a);
        $this->audit->record($actorId, 'announcement.published', 'announcement', $a->uid, 'Published "' . $a->title . '"');

        return $a;
    }

    public function cancel(int $actorId, PlatformAnnouncement $a): PlatformAnnouncement
    {
        $this->authority->assertAdministrator($actorId);
        $this->assertIn($a, [Status::Draft, Status::Scheduled, Status::Published], 'This announcement can no longer be cancelled.');

        $a = $this->canonical->cancel($a);
        $this->audit->record($actorId, 'announcement.cancelled', 'announcement', $a->uid, 'Cancelled "' . $a->title . '"');

        return $a;
    }

    /**
     * The scheduler sweep: publishes what is due (through the same canonical publish, so the
     * delivery seam runs once per announcement) and expires what has lapsed.
     *
     * @return int how many scheduled announcements were published
     */
    public function sweepDue(?int $systemActorId = null): int
    {
        return $this->canonical->sweep()['published'];
    }

    private function assertIn(PlatformAnnouncement $a, array $allowed, string $message): void
    {
        if (! in_array($a->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __($message)]);
        }
    }

    /**
     * The owner-facing validation (and its messages), unchanged.
     *
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
            'audience_tiers' => $tiers,
            'channels' => $channels,
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields  normalised owner-form fields
     * @return array<string, mixed>          the canonical manager's input
     */
    private function toCanonical(array $fields): array
    {
        $channels = [];

        if (in_array('in_app', $fields['channels'], true)) {
            $channels = ['banner', 'notification'];
        }

        if (in_array('email', $fields['channels'], true)) {
            $channels[] = 'email';
        }

        return [
            'title' => $fields['title'],
            'body' => $fields['body'],
            'severity' => 'info',
            'channels' => $channels,
            'audience' => $fields['audience'] === 'tiers'
                ? ['kind' => 'tiers', 'tiers' => $fields['audience_tiers']]
                : ['kind' => 'everyone'],
            'expires_at' => $fields['expires_at'],
        ];
    }

    /**
     * The announcement's present state as canonical input, with overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function current(PlatformAnnouncement $a, array $overrides = []): array
    {
        return $overrides + [
            'title' => $a->title,
            'body' => $a->body,
            'severity' => $a->severity,
            'channels' => (array) $a->channels,
            'audience' => (array) $a->audience,
            'publish_at' => $a->publish_at,
            'expires_at' => $a->expires_at,
        ];
    }
}
