<?php

namespace App\Library\Search\Sources;

use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over Contacts (Blueprint §24). Business-scoped, `view_contact`
 * gated (Contacts carries no separate entitlement — CustomerMenuBuilder's own
 * comment records that Contacts is deliberately not entitlement-gated), and
 * Location-filtered per Contract 08B's established convention (ChatBox,
 * CrmOpportunity): a Contact with a proven `location_id` must pass
 * LocationAccessGuard; a NULL `location_id` is an ordinary legacy value and
 * does not gate on its own — the Business-level check already run below
 * governs, exactly as it does for a ChatBox conversation.
 */
final class ContactSearchSource implements SearchSource
{
    public function __construct(private readonly LocationAccessGuard $locations)
    {
    }

    /** @return list<SearchResult> */
    public function search(Business $business, string $query, User $user, int $limit): array
    {
        if (! Gate::forUser($user)->allows('view_contact')) {
            return [];
        }

        $digits = (string) preg_replace('/\D+/', '', $query);
        $like = '%' . addcslashes($query, '%_\\') . '%';

        $candidates = Contacts::query()
            ->where('business_id', $business->id)
            ->where(function ($where) use ($digits, $like): void {
                if ($digits !== '') {
                    $where->orWhere('phone', 'like', '%' . $digits . '%');
                }

                $where->orWhereExists(function ($values) use ($like): void {
                    $values->selectRaw('1')
                        ->from('contacts_custom_field as v')
                        ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
                        ->whereColumn('v.contact_id', 'contacts.id')
                        ->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY'])
                        ->where('v.value', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->limit($limit * 4)
            ->get(['id', 'uid', 'phone', 'location_id']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $names = $this->identityFor($candidates->pluck('id')->all());

        $results = [];

        foreach ($candidates as $contact) {
            if (count($results) >= $limit) {
                break;
            }

            if ($contact->location_id !== null) {
                $location = $contact->location;

                if ($location === null || ! $this->locations->userCanAccessLocation((int) $user->id, $location)) {
                    continue;
                }
            }

            $name = trim($names[$contact->id] ?? '');

            $results[] = new SearchResult(
                domain: 'contacts',
                title: $name !== '' ? $name : $contact->phone,
                subtitle: $name !== '' ? $contact->phone : '',
                url: route('customer.workspaces.businesses.people.show', [$business->workspace->uid, $business->uid, $contact->uid]),
                icon: 'user',
            );
        }

        return $results;
    }

    /**
     * @param  list<int>  $contactIds
     * @return array<int, string>
     */
    private function identityFor(array $contactIds): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->whereIn('v.contact_id', $contactIds)
            ->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME'])
            ->orderBy('v.id')
            ->get(['v.contact_id', 'f.tag', 'v.value']);

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->contact_id] ??= ['FIRST_NAME' => '', 'LAST_NAME' => ''];

            if ($row->value !== null && $row->value !== '') {
                $names[(int) $row->contact_id][$row->tag] = $row->value;
            }
        }

        return array_map(
            static fn (array $parts): string => trim(($parts['FIRST_NAME'] ?? '') . ' ' . ($parts['LAST_NAME'] ?? '')),
            $names,
        );
    }
}
