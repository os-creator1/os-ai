<?php

namespace App\Library\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmOpportunityStatus;
use Illuminate\Http\Request;

/**
 * What the board is narrowed to, read leniently from the query string: an
 * unknown value falls back to the default rather than erroring, because these
 * are bookmarkable view settings, not input to be rejected.
 */
final readonly class CrmBoardFilters
{
    public const STATUS_ALL = 'all';

    public const CONTACT_STATUS_ANY = 'any';

    public function __construct(
        public string $search = '',
        public string $status = 'open',
        public string $contactStatus = self::CONTACT_STATUS_ANY,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $status = (string) $request->query('status', CrmOpportunityStatus::Open->value);
        $contactStatus = (string) $request->query('contact_status', self::CONTACT_STATUS_ANY);

        return new self(
            mb_substr(trim((string) $request->query('q', '')), 0, 100),
            in_array($status, self::statusValues(), true) ? $status : CrmOpportunityStatus::Open->value,
            in_array($contactStatus, self::contactStatusValues(), true) ? $contactStatus : self::CONTACT_STATUS_ANY,
        );
    }

    /** @return list<string> */
    public static function statusValues(): array
    {
        return [...array_map(fn (CrmOpportunityStatus $s) => $s->value, CrmOpportunityStatus::cases()), self::STATUS_ALL];
    }

    /** @return list<string> */
    public static function contactStatusValues(): array
    {
        return [self::CONTACT_STATUS_ANY, ...array_map(fn (CrmContactStatus $s) => $s->value, CrmContactStatus::cases())];
    }

    public function isDefault(): bool
    {
        return $this->search === '' && $this->status === CrmOpportunityStatus::Open->value && $this->contactStatus === self::CONTACT_STATUS_ANY;
    }
}
