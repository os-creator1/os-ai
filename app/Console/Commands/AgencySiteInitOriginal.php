<?php

namespace App\Console\Commands;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\SiteSchema;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Initialises ONE Agency workspace's Landing Page as the ORIGINAL MotionGrove site (call-only), so its owner can edit
 * it in White Label → Landing Page. It never touches the workspace's White Label branding, never overwrites an existing
 * site, never publishes, and never changes marketing.landing.* routing.
 *
 * Read-only unless --apply is given. The workspace is identified by its immutable uid; nothing is guessed.
 */
class AgencySiteInitOriginal extends Command
{
    protected $signature = 'agency-site:init-original {workspace_uid : Immutable workspace uid} {--booking-type= : public booking uuid of the type every Book a Call button should open} {--apply : Write the draft (default is a read-only report)}';

    protected $description = 'Create the draft Landing Page of a workspace as the original MotionGrove site, call-only (dry run unless --apply).';

    public function handle(AgencySiteManager $sites, AgencySiteBookingService $booking): int
    {
        $agency = Workspace::query()->where('uid', (string) $this->argument('workspace_uid'))->first();

        if ($agency === null) {
            $this->error('No workspace has that uid.');

            return self::FAILURE;
        }

        $existing = $sites->find($agency);
        $options = collect($booking->options($agency));
        $this->table(['Workspace id', 'Name', 'Owner user id', 'Existing site', 'Booking types'], [[
            $agency->id, $agency->name, $agency->owner_user_id, $existing?->public_id ?? 'none', $options->count(),
        ]]);
        $this->table(['Booking uuid', 'Name', 'Minutes', 'Active', 'Ready'], $options->map(fn ($o) => [$o['uuid'], $o['name'], $o['duration'], $o['active'] ? 'yes' : 'no', $o['ready'] ? 'yes' : 'NO: '.$o['reason']])->all());

        if ($existing !== null) {
            $this->warn('This workspace already has a Landing Page site. Nothing is overwritten; edit it in White Label → Landing Page.');

            return self::FAILURE;
        }

        $uuid = (string) $this->option('booking-type');
        $type = $options->firstWhere('uuid', strtolower($uuid));

        if ($type === null || ! $type['ready']) {
            $this->error('Pass --booking-type=<uuid> of a READY booking type from the list above (the one every Book a Call button will open).');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: would create a draft (original MotionGrove edition, call-only, booking type "'.$type['name'].'"). Re-run with --apply to write it. It will not be published.');

            return self::SUCCESS;
        }

        $site = $sites->save((int) $agency->owner_user_id, $agency, [
            'x.edition' => SiteSchema::EDITION_ORIGINAL,
            'x.goal' => 'book_call',
            'x.booking_type_uid' => $type['uuid'],
        ]);

        $this->info('Draft created: site '.$site->public_id.' (status '.$site->status.'). Review it in White Label → Landing Page, publish, then set MARKETING_LANDING_PHOTOBOOTH_SITE.');

        return self::SUCCESS;
    }
}
