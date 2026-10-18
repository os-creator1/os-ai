<?php

namespace App\Library\Search\Sources;

use App\Library\Crm\CrmLocationScope;
use App\Library\Search\Contracts\SearchSource;
use App\Library\Search\SearchResult;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Global Search over Contacts (Blueprint §24). Business-scoped, `view_contact`
 * gated (Contacts carries no separate entitlement — CustomerMenuBuilder's own
 * comment records that Contacts is deliberately not entitlement-gated), and
 * Location-filtered per Contract 08B's established convention (ChatBox,
 * CrmOpportunity): a Contact with a proven `location_id` must be at a Location
 * LocationAccessGuard lets the actor reach; a NULL `location_id` is an ordinary
 * legacy value and does not gate on its own — the Business-level check already
 * run below governs, exactly as it does for a ChatBox conversation.
 *
 * The reach is pushed into the SQL (CrmLocationScope), BEFORE the result limit:
 * filtering a fixed window of newest matches afterwards would let a run of newer
 * matches at an unreachable Location push every reachable match out of it.
 */
final class ContactSearchSource implements SearchSource
{
    public function __construct(private readonly CrmLocationScope $scope)
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

        $query = Contacts::query()->where('business_id', $business->id);
        $this->scope->restrict($query, $business, (int) $user->id, 'contacts.location_id');

        $candidates = $query
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
            ->limit($limit)
            ->get(['id', 'uid', 'phone', 'location_id']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $names = $this->identityFor($candidates->pluck('id')->all());

        $results = [];

        foreach ($candidates as $contact) {
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
