<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Automations V2 §10 — "Notify the team".
 *
 * Told to the Business owner and to the active Workspace members who can see
 * that Business, on the `database` and `mail` channels. Who receives it is
 * decided by InternalNotificationNodeExecutor against the real membership rows;
 * this class only says what they are told.
 *
 * WHAT IT MAY CONTAIN. Enough to be actionable — which Business, which workflow,
 * which contact — and nothing that would leak across a tenant boundary. Every
 * recipient has been proven to have access to this Business before the
 * notification is sent, so naming the Business and workflow is safe; the contact
 * is identified by a masked phone rather than the full number, because these
 * messages travel by email and end up in inboxes and forwards.
 *
 * Carries scalars only, never models: a queued notification is serialized, and a
 * model would be re-fetched at send time with whatever state it had by then.
 */
class WorkflowInternalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $businessName,
        public readonly string $workflowName,
        public readonly string $contactReference,
        public readonly string $message,
        public readonly int $businessId,
        public readonly int $workflowId,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->businessName . ': ' . $this->workflowName)
            ->greeting(__('locale.labels.hello') . ',')
            ->line($this->message)
            ->line(__('locale.labels.business') . ': ' . $this->businessName)
            ->line('Workflow: ' . $this->workflowName)
            ->line('Contact: ' . $this->contactReference)
            ->salutation(__('locale.labels.regards', ['appname' => config('app.name')]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'automation_workflow_internal_notification',
            'business_id' => $this->businessId,
            'business_name' => $this->businessName,
            'workflow_id' => $this->workflowId,
            'workflow_name' => $this->workflowName,
            'contact' => $this->contactReference,
            'message' => $this->message,
        ];
    }
}
