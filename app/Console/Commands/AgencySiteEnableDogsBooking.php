<?php

namespace App\Console\Commands;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\OfficialDomainPaths;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Enable the same on-page consultation calendar that /photobooth uses, for
 * the existing /dogs White Label landing page. Preserve the dog's branding,
 * niche, section order, content, selected booking type and published revision.
 */
class AgencySiteEnableDogsBooking extends Command
{
    protected $signature = 'agency-site:enable-dogs-booking {workspace_uid : The workspace that owns /dogs} {--apply : Update the draft only (default: dry run)}';

    protected $description = 'Give /dogs the /photobooth inline booking section and CTA behavior, without publishing.';

    private const COPY_DEFAULTS = [
        'consultation.title' => 'Free Consultation and Growth Plan',
        'consultation.calendar_title' => 'Book your free consultation',
    ];

    public function handle(AgencySiteManager $sites, AgencySiteBookingService $booking): int
    {
        $uid = (string) $this->argument('workspace_uid');

        if (! OfficialDomainPaths::permits('dogs', $uid)) {
            $this->error('That workspace does not own /dogs. Nothing was changed.');

            return self::FAILURE;
        }

        $agency = Workspace::query()->where('uid', $uid)->first();

        if ($agency === null) {
            $this->error('No workspace has that uid.');

            return self::FAILURE;
        }

        $site = $sites->find($agency);

        if ($site === null) {
            $this->error('This workspace has no Landing Page site yet. Nothing was changed.');

            return self::FAILURE;
        }

        $values = AgencySiteManager::values($site);
        $uuid = $values['x.booking_type_uid'] ?? null;
        $readiness = $booking->readiness($agency, $uuid);

        $this->table(
            ['Workspace id', 'Name', 'Site', 'Status', 'Booking type', 'Booking ready'],
            [[$agency->id, $agency->name, $site->public_id, $site->status, $uuid ?? 'none', $readiness['ready'] ? 'yes' : 'NO: '.($readiness['reason'] ?? '')]],
        );

        if (! $readiness['ready']) {
            $this->error('Select a working booking type in White Label → Landing Page before enabling the calendar. Nothing was changed.');

            return self::FAILURE;
        }

        $changes = [];
        $set = function (string $path, mixed $value) use ($values, &$changes): void {
            if (($values[$path] ?? null) !== $value) {
                $changes[$path] = $value;
            }
        };

        // Keep the dog niche and branding. Only make the existing homepage
        // call-only and opt into the SAME consultation component /photobooth uses.
        $set('x.goal', 'book_call');
        $set('x.template', 'home');

        $hidden = (array) ($values['x.home_hidden'] ?? []);
        if (in_array('consultation', $hidden, true)) {
            $set('x.home_hidden', array_values(array_diff($hidden, ['consultation'])));
        }

        $set('x.home_shown', array_values(array_unique(array_merge(
            (array) ($values['x.home_shown'] ?? []),
            ['consultation'],
        ))));

        foreach (self::COPY_DEFAULTS as $path => $copy) {
            if (blank($values[$path] ?? null)) {
                $changes[$path] = $copy;
            }
        }

        if ($changes === []) {
            $this->info('Already complete. The draft needs no changes.');

            return self::SUCCESS;
        }

        $this->table(
            ['Path', 'Before', 'After'],
            collect($changes)->map(fn ($after, $path) => [
                $path, json_encode($values[$path] ?? null), json_encode($after),
            ])->values()->all(),
        );

        $this->line('Preserved: dog niche and branding, every other saved value, selected booking type, approved testimonials, and the live published revision.');

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Re-run with --apply to update the DRAFT only.');

            return self::SUCCESS;
        }

        $saved = $sites->save((int) $agency->owner_user_id, $agency, $changes);
        $this->info('Draft updated: site '.$saved->public_id.'. Review the /dogs preview, then publish deliberately.');

        return self::SUCCESS;
    }
}
