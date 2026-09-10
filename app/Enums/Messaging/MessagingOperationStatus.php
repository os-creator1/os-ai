<?php

namespace App\Enums\Messaging;

/**
 * Slice 3 §4.2/§4.6.3 — the transport state of one messaging operation.
 *
 * The permitted-next table below is the sole authority on delivery-status
 * transitions. Attempted moves only through the outbound send path itself;
 * a DELIVERY_STATUS callback may only ever move an already-Accepted row,
 * and Delivered/Failed/Rejected are terminal.
 */
enum MessagingOperationStatus: string
{
    case Attempted = 'attempted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * The statuses a DELIVERY_STATUS callback may move this status to.
     *
     * @return list<self>
     */
    public function permittedNextFromDeliveryStatus(): array
    {
        return match ($this) {
            self::Accepted => [self::Delivered, self::Failed],
            self::Attempted, self::Delivered, self::Failed, self::Rejected => [],
        };
    }

    public function allowsDeliveryTransitionTo(self $target): bool
    {
        return in_array($target, $this->permittedNextFromDeliveryStatus(), true);
    }

    public function isTerminal(): bool
    {
        return $this->permittedNextFromDeliveryStatus() === []
            && $this !== self::Attempted;
    }
}
