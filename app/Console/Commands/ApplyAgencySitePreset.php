<?php

namespace App\Console\Commands;

use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\Presets\DogTrainingBoardingPreset;
use App\Library\AgencySite\SiteContextFactory;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Writes an industry preset into ONE Agency's own Landing Page DRAFT, through the same validated, owner-authorised
 * write path the editor uses (AgencySiteManager::save). Operator tool, not a feature:
 *
 *  - dry run by default; `--apply` writes;
 *  - refuses to run in the production environment;
 *  - touches only the named Agency's AgencySite (never SiteDefaults, other tenants, brand, logos, plans or Stripe);
 *  - never publishes;
 *  - picks the conversion mode from what actually works (sellable plans, a bookable booking type) and reports what is missing;
 *  - `--create-booking-type` adds a 30-minute demo type only when none is bookable, WITHOUT inventing working hours.
 */
class ApplyAgencySitePreset extends Command
{
    protected $signature = 'agency-site:apply-preset
        {workspaceUid : The Agency workspace uid}
        {--preset=dog-training-boarding : Preset name}
        {--apply : Write the draft (default is a dry run)}
        {--create-booking-type : Create the 30-minute demo booking type when no bookable one exists}';

    protected $description = 'Apply an industry content preset to one Agency\'s Landing Page draft (never publishes).';

    public function handle(AgencySiteManager $sites, AgencySiteBookingService $booking, SiteContextFactory $contexts): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in the production environment.');

            return self::FAILURE;
        }

        if ($this->option('preset') !== DogTrainingBoardingPreset::NAME) {
            $this->error('Unknown preset. Available: '.DogTrainingBoardingPreset::NAME);

            return self::FAILURE;
        }

        $agency = Workspace::query()->where('uid', (string) $this->argument('workspaceUid'))->first();

        if ($agency === null) {
            $this->error('No such workspace.');

            return self::FAILURE;
        }

        $actor = (int) $agency->owner_user_id;
        $apply = (bool) $this->option('apply');

        try {
            // Authority: the workspace owner of an eligible, White Label–entitled Agency, exactly as the editor requires.
            $site = $sites->find($agency) ?? ($apply ? $sites->ensure($actor, $agency) : null);

            // 8 — Book a call: use a bookable type if one exists; optionally create the demo type.
            $options = collect($booking->options($agency));
            $current = $site ? (AgencySiteManager::values($site)['x.booking_type_uid'] ?? null) : null;
            $ready = $options->first(fn ($o) => $o['uuid'] === $current && $o['ready'])
                ?? $options->first(fn ($o) => $o['ready'] && stripos($o['name'], 'MotionGrove Demo') !== false)
                ?? $options->first(fn ($o) => $o['ready']);
            $createdType = false;

            if ($ready === null && $this->option('create-booking-type')) {
                if ($apply) {
                    [$type] = $sites->createAndSelectBookingType($actor, $agency, [
                        'name' => 'MotionGrove Demo — Dog Training & Boarding', 'duration_minutes' => 30, 'default_hours' => false,
                    ]);
                    $site = $sites->find($agency);
                    $createdType = true;
                    $ready = collect($booking->options($agency))->first(fn ($o) => $o['uuid'] === (string) $type->public_booking_uuid);
                    $ready = ($ready['ready'] ?? false) ? $ready : null;
                    $this->warn('Created the demo booking type WITHOUT working hours. Add availability in the Calendar before it can be booked.');
                } else {
                    $this->line('[dry run] would create the 30-minute demo booking type (no working hours).');
                }
            }

            // 7 — Pricing: the Agency's real, currently sellable plans (read only).
            // Needs no site: the same read-only discovery the editor and renderer use, so a first-time dry run reports the
            // Agency's real plans and chooses the same mode --apply will.
            $plans = $contexts->plans($agency, $site ? AgencySiteManager::values($site) : [], (string) $agency->name);
            $sellable = array_values(array_filter($plans, fn ($p) => $p['sellable']));
            $canSell = $sellable !== [];
            $canBook = $ready !== null;

            $values = DogTrainingBoardingPreset::values($canSell, $canBook);

            if ($canBook) {
                $values['x.booking_type_uid'] = $ready['uuid'];
            }

            // One authoritative mode; left as it is when neither route works, so the draft stays honest.
            if ($canSell || $canBook) {
                $values['x.goal'] = $canSell && $canBook ? 'both' : ($canSell ? 'subscriptions' : 'book_call');
            }

            $existing = $site ? AgencySiteManager::values($site) : [];
            $replaced = array_values(array_filter(array_keys($values), fn ($p) => array_key_exists($p, $existing) && $existing[$p] !== $values[$p]));

            $this->table(['Check', 'Result'], [
                ['Agency', $agency->name.' ('.$agency->uid.')'],
                ['Mode', $apply ? 'APPLY (draft only, never published)' : 'DRY RUN'],
                ['Sellable plans', $canSell ? implode('; ', array_map(fn ($p) => ($p['name'] ?? '?'), $sellable)) : 'none'],
                ['Bookable booking type', $canBook ? $ready['name'] : 'none'.($createdType ? ' (just created, needs availability)' : '')],
                ['Conversion goal', $values['x.goal'] ?? 'unchanged (no working conversion route)'],
                ['Values to write', count($values)],
                ['Existing edited values replaced', count($replaced)],
            ]);

            if ($replaced !== []) {
                $this->line('Replaced: '.implode(', ', $replaced));
            }

            if (! $apply) {
                $this->info('Dry run only. Re-run with --apply to write the draft.');

                return self::SUCCESS;
            }

            $site = $sites->save($actor, $agency, $values);
            $failing = array_filter($sites->checklist($agency, $site), fn ($i) => $i['status'] === 'fail');

            $this->info('Draft saved (status: '.$site->status.'). NOT published.');

            foreach ($failing as $item) {
                $this->warn('Blocks publishing: '.$item['label'].' — '.$item['detail']);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Nothing further was written: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
