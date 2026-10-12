<?php

namespace App\Library\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A MailMessage that can hand the shared transactional layout its brand, icon and
 * details panel (`brand`, `icon`, `details` view data). Nothing else changes: the
 * recipient, From, subject, lines, action and attachments are the stock ones.
 */
class BrandedMailMessage extends MailMessage
{
    /** @param array<string, mixed> $data */
    public function viewData(array $data): static
    {
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }
}
