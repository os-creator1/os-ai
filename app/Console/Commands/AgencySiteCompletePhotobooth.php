<?php

namespace App\Console\Commands;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\OfficialDomainPaths;
use App\Library\AgencySite\SiteSchema;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Brings the EXISTING /photobooth Landing Page draft up to "full original MotionGrove homepage, minus pricing, with the
 * consultation calendar", through the canonical AgencySiteManager::save() (same validation as the editor).
 *
 * Narrow by construction: only the workspace that owns the official `photobooth` path may be named; the site must already
 * exist; only the DRAFT is written (never published, never a revision); existing copy, testimonials and the selected booking
 * type are kept; consultation copy is filled only where it is still blank. Idempotent. Read-only unless --apply is given.
 */
class AgencySiteCompletePhotobooth extends Command
{
    protected $signature = 'agency-site:complete-photobooth {workspace_uid : Immutable uid of the workspace that owns /photobooth} {--apply : Save the changes to the draft (default is a read-only report)}';

    protected $description = 'Make the existing /photobooth draft the full original MotionGrove homepage (no pricing) with the consultation calendar section (dry run unless --apply).';

    /** Filled only when blank, so anything the owner already wrote wins. */
    private const COPY_DEFAULTS = [
        'consultation.title' => 'Free Consultation and Growth Plan',
        'consultation.calendar_title' => 'Book your free consultation',
    ];

    public function handle(AgencySiteManager $sites, AgencySiteBookingService $booking): int
    {
        $uid = (string) $this->argument('workspace_uid');

        if (! OfficialDomainPaths::permits('photobooth', $uid)) {
            $this->error('That workspace does not own the official /photobooth path. Nothing was changed.');

            return self::FAILURE;
        }

        $agency = Workspace::query()->where('uid', $uid)->first();

        if ($agency === null) {
            $this->error('No workspace has that uid.');

            return self::FAILURE;
        }

        $site = $sites->find($agency);

        if ($site === null) {
            $this->error('This workspace has no Landing Page site yet. Create it first with agency-site:init-original.');

            return self::FAILURE;
        }

        $values = AgencySiteManager::values($site);
        $readiness = $booking->readiness($agency, $values['x.booking_type_uid'] ?? null);

        $this->table(['Workspace id', 'Name', 'Site', 'Status', 'Booking type', 'Booking ready'], [[
            $agency->id, $agency->name, $site->public_id, $site->status, $values['x.booking_type_uid'] ?? 'none', $readiness['ready'] ? 'yes' : 'NO: '.($readiness['reason'] ?? '')
        ]]);

        if (! $readiness['ready']) {
            $this->error('The selected booking type is not ready. Choose the 15-minute type in White Label → Landing Page first; nothing was changed.');

            return self::FAILURE;
        }

        $changes = [];
        $set = function (string $path, mixed $value) use ($values, &$changes): void {
            if (($values[$path] ?? null) !== $value) {
                $changes[$path] = $value;
            }
        };

        $set('x.edition', SiteSchema::EDITION_ORIGINAL);
        $set('x.goal', 'book_call');
        $set('x.template', 'home');

        $hidden = array_values(array_diff((array) ($values['x.home_hidden'] ?? []), ['consultation']));
        $shown = array_values(array_unique(array_merge((array) ($values['x.home_shown'] ?? []), ['consultation'])));
        $set('x.home_hidden', $hidden);
        $set('x.home_shown', $shown);

        foreach (self::COPY_DEFAULTS as $path => $default) {
            if (blank($values[$path] ?? null)) {
                $changes[$path] = $default;
            }
        }

        if ($changes === []) {
            $this->info('Already complete: the draft needs no change.');

            return self::SUCCESS;
        }

        $this->table(['Path', 'Before', 'After'], collect($changes)->map(fn ($after, $path) => [$path, json_encode($values[$path] ?? null), json_encode($after)])->values()->all());
        $this->line('Untouched: every other saved value, x.testimonials, x.booking_type_uid, the published revision and White Label branding.');

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Re-run with --apply to update the DRAFT (it is never published).');

            return self::SUCCESS;
        }

        $saved = $sites->save((int) $agency->owner_user_id, $agency, $changes);
        $this->info('Draft updated: site '.$saved->public_id.' (status '.$saved->status.'). Review it in White Label → Landing Page, then publish deliberately.');

        return self::SUCCESS;
    }
}
