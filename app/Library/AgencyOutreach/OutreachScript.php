<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\AgencyProspectUrlPolicy;
use App\Models\AgencyProspectingSetting;
use App\Models\Workspace;

/**
 * One Agency's script as the engine reads it: the saved copy, falling back to
 * the neutral default per field (contract §3).
 *
 * Read-only and scoped to the Workspace it was built for — there is no global
 * script, so Agency A's copy can never reach Agency B's prospects. Fields hold
 * un-rendered, canonical-token text; OutreachScriptRenderer turns it into the
 * message a prospect receives.
 */
final class OutreachScript
{
    private function __construct(
        private readonly Workspace $workspace,
        private readonly ?AgencyProspectingSetting $settings,
    ) {
    }

    public static function forWorkspace(Workspace $workspace): self
    {
        $settings = $workspace->exists
            ? AgencyProspectingSetting::query()->where('workspace_id', (int) $workspace->id)->first()
            : null;

        return new self($workspace, $settings);
    }

    /** The saved text of a script field, else its neutral default; '' for a name that is not a script field. */
    public function get(string $field): string
    {
        $defaults = OutreachScriptDefaults::all();

        if (! array_key_exists($field, $defaults)) {
            return '';
        }

        $saved = $this->settings?->getAttribute($field);

        return is_string($saved) && trim($saved) !== '' ? $saved : $defaults[$field];
    }

    /** Bumped on every save that changes script text; stamped on each ledger row so a message can be traced to its copy. */
    public function version(): int
    {
        return max(1, (int) ($this->settings?->script_version ?? 1));
    }

    /** The Agency's booking link, only when it is a valid http(s) URL. */
    public function calendarUrl(): ?string
    {
        $url = trim((string) $this->settings?->booking_url);

        return AgencyProspectUrlPolicy::isValidHttpUrl($url) ? $url : null;
    }

    /** The name prospects see: the saved agency name, else the Agency Business's own name. */
    public function agencyName(): string
    {
        $saved = trim((string) $this->settings?->agency_name);

        if ($saved !== '') {
            return $saved;
        }

        return trim((string) AgencyOutreachBusinessResolver::forWorkspace($this->workspace)?->name);
    }

    /** Whether the single bounded model call may answer a question the FAQ cannot place (default on). */
    public function aiEnabled(): bool
    {
        return $this->settings === null ? true : (bool) $this->settings->ai_enabled;
    }

    public function followUpEnabled(): bool
    {
        return $this->settings === null ? true : (bool) $this->settings->followup_enabled;
    }

    public function followUpDelayHours(): int
    {
        $hours = (int) ($this->settings?->follow_up_delay_hours ?? 0);

        return $hours > 0 ? $hours : 24;
    }

    public function settings(): ?AgencyProspectingSetting
    {
        return $this->settings;
    }
}
