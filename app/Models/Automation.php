<?php

namespace App\Models;

use App\Enums\Automation\AutomationActionType;
use App\Enums\Automation\AutomationTriggerType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B4 Business Automations — a DEFINITION/STATE model only (contract §8).
 *
 * This model no longer extends SendCampaignSMS: sending is composition
 * through the bounded action dispatcher and the existing B1 Business
 * Outreach send core, never inheritance. Every legacy column
 * (`sms_type`, `sender_id`, `media_url`, `language`, `gender`,
 * `dlt_template_id`, `timezone`, `cache`, `contact_list_id`,
 * `sending_server_id`, `data`, `running_pid`, `reason`, `last_error`)
 * remains physically present and is simply unused by B4 (§3) — none is
 * read, written, or repurposed here. Three of them (`contact_list_id`,
 * `sms_type`, `data`) were NOT NULL for the legacy Birthday builder and
 * are nullable since B4 (contract §3.1a): a B4 definition legitimately
 * leaves them NULL, and nothing may invent values for them.
 *
 * Tenancy is `business_id` only (§2/§3.3). A row whose `business_id` is
 * NULL is a preserved-but-inert legacy automation: never executed by the
 * B4 runtime, never reachable through a Business route, never silently
 * reassigned (§3.5).
 */
class Automation extends Model
{
    use HasUid;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id',
        'business_id',
        'name',
        'status',
        'trigger_type',
        'trigger_config',
        'action_type',
        'action_config',
        // Legacy columns — kept fillable so pre-B4 rows/tests can still be
        // constructed; B4 itself never sets them.
        'contact_list_id',
        'sending_server_id',
        'timezone',
        'sender_id',
        'message',
        'media_url',
        'language',
        'gender',
        'sms_type',
        'reason',
        'cache',
        'data',
        'dlt_template_id',
        'last_error',
    ];

    protected $casts = [
        'trigger_type' => AutomationTriggerType::class,
        'action_type' => AutomationActionType::class,
        'trigger_config' => 'array',
        'action_config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Legacy owner relation. Retained for the data-only backfill's owner-
     * consistency rule and for pre-B4 rows; NEVER used as authorization
     * (contract §2.3).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Legacy audience relation (contact_list_id). B4's date trigger reads
     * its audience from trigger_config.contact_group_id instead.
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactGroups::class, 'contact_list_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'automation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * A B4-runnable definition: Business-scoped and carrying a
     * code-backed trigger + action. Legacy NULL-business rows and rows
     * without a B4 definition are never runnable.
     */
    public function isRunnableDefinition(): bool
    {
        return $this->business_id !== null
            && $this->trigger_type instanceof AutomationTriggerType
            && $this->action_type instanceof AutomationActionType;
    }

    public function triggerSummary(): string
    {
        if (! $this->trigger_type instanceof AutomationTriggerType) {
            return 'Legacy (not configured)';
        }

        $config = $this->trigger_config ?? [];

        return match ($this->trigger_type) {
            AutomationTriggerType::ContactDateReached => sprintf(
                'Contact date reached — %s at %s',
                (string) ($config['offset'] ?? '0 day'),
                (string) ($config['send_at'] ?? '--:--'),
            ),
            AutomationTriggerType::ContactCreated => 'Contact created',
        };
    }

    public function actionSummary(): string
    {
        if (! $this->action_type instanceof AutomationActionType) {
            return 'Legacy (not configured)';
        }

        $config = $this->action_config ?? [];

        return match ($this->action_type) {
            AutomationActionType::SendMessage => sprintf(
                'Send %s message',
                strtoupper((string) ($config['sms_type'] ?? 'plain')) === 'MMS' ? 'MMS' : 'SMS',
            ),
            AutomationActionType::UpdateContactField => 'Update contact field',
        };
    }
}
