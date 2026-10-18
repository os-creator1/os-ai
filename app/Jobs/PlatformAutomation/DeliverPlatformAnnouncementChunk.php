<?php

namespace App\Jobs\PlatformAutomation;

use App\Mail\PlatformAutomationMail;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAnnouncementReceipt;
use App\Models\User;
use App\Notifications\PlatformAutomation\PlatformNoticeNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one announcement to one chunk of users. Safe to retry: a (announcement,
 * user, channel) receipt is created exactly once and a channel is only delivered by
 * whoever created that receipt, so a retried or duplicated chunk never double-sends.
 */
class DeliverPlatformAnnouncementChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param list<int> $userIds */
    public function __construct(public readonly int $announcementId, public readonly array $userIds)
    {
    }

    public function handle(): void
    {
        $announcement = PlatformAnnouncement::query()->find($this->announcementId);

        // Cancelled / expired between publish and this chunk: deliver nothing more.
        if ($announcement === null || $announcement->status !== \App\Enums\PlatformOwner\PlatformAnnouncementStatus::Published) {
            return;
        }

        $done = 0; // users who received something NEW in this run (a retried chunk adds nothing)

        foreach (User::query()->whereIn('id', $this->userIds)->get() as $user) {
            $fresh = false;

            foreach ((array) $announcement->channels as $channel) {
                $receipt = PlatformAnnouncementReceipt::query()->firstOrCreate(
                    ['announcement_id' => $announcement->id, 'user_id' => $user->id, 'channel' => $channel],
                );

                if (! $receipt->wasRecentlyCreated) {
                    continue;
                }

                $fresh = true;

                try {
                    $this->deliver($announcement, $user, $channel);
                    $receipt->forceFill(['delivered_at' => now()])->save();
                } catch (Throwable $e) {
                    // One bad address must not fail the chunk. The receipt stays
                    // undelivered; the error is reported, not shown to anyone.
                    report($e);
                }
            }

            $done += $fresh ? 1 : 0;
        }

        DB::table('platform_announcements')->where('id', $announcement->id)->increment('recipients_done', $done);
    }

    private function deliver(PlatformAnnouncement $announcement, User $user, string $channel): void
    {
        if ($channel === 'notification') {
            $user->notify(new PlatformNoticeNotification($announcement->title, $announcement->body, $announcement->severity, $announcement->id));
        } elseif ($channel === 'email') {
            Mail::to($user->email)->send(new PlatformAutomationMail($announcement->title, $announcement->body, 'product_notice'));
        }
        // 'banner' is the receipt itself: the shell shows banners for receipts it can find.
    }
}
