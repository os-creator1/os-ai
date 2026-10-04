<?php

namespace App\Library\PlatformAutomation\Announcements;

use App\Jobs\PlatformAutomation\DeliverPlatformAnnouncementChunk;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementReceipt;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of announcements. Lifecycle:
 *   draft -> scheduled -> published -> expired        cancelled from draft/scheduled/published
 *
 * Publishing resolves the audience ONCE, records the receipt ledger and fans delivery
 * out in queued chunks, so a large audience never runs in one request and a retried
 * chunk never delivers twice (the receipt's unique key is the idempotency guard).
 */
class PlatformAnnouncementManager
{
    public const CHUNK = 200;

    /** @param array<string, mixed> $input */
    public function create(array $input, int $actorUserId, ?int $sourceRunId = null): PlatformAnnouncement
    {
        $data = $this->validated($input);

        return PlatformAnnouncement::create($data + [
            'uid' => (string) Str::uuid(),
            'status' => PlatformAnnouncement::STATUS_DRAFT,
            'created_by_user_id' => $actorUserId,
            'source_run_id' => $sourceRunId,
        ]);
    }

    /** @param array<string, mixed> $input */
    public function update(PlatformAnnouncement $announcement, array $input): PlatformAnnouncement
    {
        $this->assertEditable($announcement);
        $announcement->forceFill($this->validated($input))->save();

        return $announcement;
    }

    public function schedule(PlatformAnnouncement $announcement): PlatformAnnouncement
    {
        $this->assertEditable($announcement);

        if ($announcement->publish_at === null) {
            throw ValidationException::withMessages(['publish_at' => ['Choose when to publish it.']]);
        }

        $announcement->forceFill(['status' => PlatformAnnouncement::STATUS_SCHEDULED])->save();

        return $announcement;
    }

    public function publishNow(PlatformAnnouncement $announcement): PlatformAnnouncement
    {
        $this->assertEditable($announcement);

        return $this->publish($announcement);
    }

    public function cancel(PlatformAnnouncement $announcement): PlatformAnnouncement
    {
        if (in_array($announcement->status, [PlatformAnnouncement::STATUS_EXPIRED, PlatformAnnouncement::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['announcement' => ['That announcement is already finished.']]);
        }

        $announcement->forceFill(['status' => PlatformAnnouncement::STATUS_CANCELLED])->save();

        return $announcement;
    }

    /** Idempotent: only a draft or scheduled announcement publishes, once. */
    public function publish(PlatformAnnouncement $announcement): PlatformAnnouncement
    {
        $claimed = DB::table('platform_announcements')
            ->where('id', $announcement->id)
            ->whereIn('status', [PlatformAnnouncement::STATUS_DRAFT, PlatformAnnouncement::STATUS_SCHEDULED])
            ->update(['status' => PlatformAnnouncement::STATUS_PUBLISHED, 'published_at' => now(), 'updated_at' => now()]);

        $announcement->refresh();

        if ($claimed === 0) {
            return $announcement;
        }

        $query = PlatformAnnouncementAudience::query((array) $announcement->audience);
        $total = 0;

        $query->orderBy('users.id')->chunk(self::CHUNK, function ($rows) use ($announcement, &$total) {
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $total += count($ids);
            DeliverPlatformAnnouncementChunk::dispatch($announcement->id, $ids);
        });

        $announcement->forceFill(['recipients_total' => $total])->save();

        return $announcement;
    }

    /** Scheduler entry point: publish what is due, expire what has lapsed. */
    public function sweep(): array
    {
        $published = 0;

        PlatformAnnouncement::query()
            ->where('status', PlatformAnnouncement::STATUS_SCHEDULED)
            ->where('publish_at', '<=', now())
            ->orderBy('id')
            ->each(function (PlatformAnnouncement $a) use (&$published) {
                $this->publish($a);
                $published++;
            });

        $expired = PlatformAnnouncement::query()
            ->where('status', PlatformAnnouncement::STATUS_PUBLISHED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => PlatformAnnouncement::STATUS_EXPIRED, 'updated_at' => now()]);

        return ['published' => $published, 'expired' => $expired];
    }

    /** Banners this user may see right now: delivered to them, live, not dismissed. */
    public function bannersFor(User $user)
    {
        return PlatformAnnouncement::query()
            ->join('platform_announcement_receipts as r', function ($join) use ($user) {
                $join->on('r.announcement_id', '=', 'platform_announcements.id')
                    ->where('r.user_id', $user->id)
                    ->where('r.channel', 'banner')
                    ->whereNull('r.dismissed_at');
            })
            ->where('platform_announcements.status', PlatformAnnouncement::STATUS_PUBLISHED)
            ->where(fn ($q) => $q->whereNull('platform_announcements.expires_at')->orWhere('platform_announcements.expires_at', '>', now()))
            ->orderByDesc('platform_announcements.published_at')
            ->limit(3)
            ->get(['platform_announcements.*']);
    }

    public function dismiss(User $user, string $announcementUid): bool
    {
        $announcement = PlatformAnnouncement::query()->where('uid', $announcementUid)->first();

        if ($announcement === null) {
            return false;
        }

        return PlatformAnnouncementReceipt::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->where('channel', 'banner')
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]) > 0;
    }

    /** @return array<string, mixed> */
    private function validated(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $severity = (string) ($input['severity'] ?? 'info');
        $channels = array_values(array_unique(array_filter((array) ($input['channels'] ?? []), fn ($c) => in_array($c, PlatformAnnouncement::CHANNELS, true))));
        $audience = (array) ($input['audience'] ?? []);

        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'][] = 'Give it a title (up to 160 characters).';
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            $errors['body'][] = 'Write the message (up to 5,000 characters).';
        }
        if (! in_array($severity, PlatformAnnouncement::SEVERITIES, true)) {
            $errors['severity'][] = 'Choose a severity.';
        }
        if ($channels === []) {
            $errors['channels'][] = 'Choose at least one channel.';
        }
        foreach (PlatformAnnouncementAudience::errors($audience) as $message) {
            $errors['audience'][] = $message;
        }

        $publishAt = $this->date($input['publish_at'] ?? null);
        $expiresAt = $this->date($input['expires_at'] ?? null);
        if ($publishAt !== null && $expiresAt !== null && $expiresAt->lessThanOrEqualTo($publishAt)) {
            $errors['expires_at'][] = 'It must expire after it publishes.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $title,
            'body' => $body,
            'severity' => $severity,
            'channels' => $channels,
            'audience' => $this->cleanAudience($audience),
            'publish_at' => $publishAt,
            'expires_at' => $expiresAt,
        ];
    }

    /** @param array<string, mixed> $audience @return array<string, mixed> */
    private function cleanAudience(array $audience): array
    {
        $kind = (string) $audience['kind'];
        $clean = ['kind' => $kind];

        if ($kind === 'tier') {
            $clean['tier'] = (string) $audience['tier'];
        }
        if (in_array($kind, ['workspaces', 'businesses', 'users'], true)) {
            $refs = $audience['refs'] ?? [];
            $refs = is_string($refs) ? (preg_split('/[\s,]+/', $refs, -1, PREG_SPLIT_NO_EMPTY) ?: []) : (array) $refs;
            $clean['refs'] = array_slice(array_values(array_unique(array_map(fn ($r) => trim((string) $r), $refs))), 0, 500);
        }

        return $clean;
    }

    private function date(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value);
    }

    private function assertEditable(PlatformAnnouncement $announcement): void
    {
        if (! in_array($announcement->status, [PlatformAnnouncement::STATUS_DRAFT, PlatformAnnouncement::STATUS_SCHEDULED], true)) {
            throw ValidationException::withMessages(['announcement' => ['Only a draft or scheduled announcement can be changed.']]);
        }
    }
}
