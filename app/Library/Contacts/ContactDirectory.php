<?php

namespace App\Library\Contacts;

use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The person-first Contacts read model: every contact of ONE Business,
 * whatever group it sits in, and one contact's profile — built only from
 * what the schema actually stores.
 *
 * What exists (and is shown): phone and subscription status on the contact;
 * name, email, company and any other details as the group's custom fields
 * (contact_group_fields / contacts_custom_field, identity by field tag); the
 * group; when the contact was added; campaign messages to the contact
 * (tracking_logs → campaigns); and the Business's conversation with the same
 * number (chat_boxes, matched by normalized number exactly as the Inbox
 * does). What does not exist is never shown or implied: no form submissions,
 * no source/attribution, no notes, no contact tags.
 *
 * Every read is scoped to the Business — contacts, groups, campaign
 * messages and conversations alike — and the list costs the same fixed
 * number of queries for any page size (no per-row queries).
 */
final class ContactDirectory
{
    public const PER_PAGE = 25;

    /** Custom-field tags that identify a person, in display order. */
    private const IDENTITY_TAGS = ['FIRST_NAME', 'LAST_NAME', 'EMAIL', 'COMPANY'];

    /**
     * @return LengthAwarePaginator rows: array{uid, name, phone, email, company, group, subscribed, added, last_activity}
     */
    public function page(Business $business, string $search): LengthAwarePaginator
    {
        $contacts = $this->query($business, $search)
            ->with('contactGroup:id,business_id,name')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'uid', 'group_id', 'business_id', 'phone', 'status', 'created_at'])
            ->withQueryString();

        $items = collect($contacts->items());
        $identity = $this->identityFor($items->pluck('id')->all());
        $lastActivity = $this->lastActivityFor($business, $items);

        return $contacts->through(fn (Contacts $contact) => [
            'uid' => (string) $contact->uid,
            'name' => $this->fullName($identity[$contact->id] ?? []),
            'phone' => $this->displayPhone($contact),
            'email' => $identity[$contact->id]['EMAIL'] ?? null,
            'company' => $identity[$contact->id]['COMPANY'] ?? null,
            'group' => $this->groupNameFor($business, $contact),
            'subscribed' => $contact->status === Contacts::STATUS_SUBSCRIBE,
            'added' => $contact->created_at,
            'last_activity' => $lastActivity[$contact->id] ?? null,
        ]);
    }

    public function findForBusiness(Business $business, string $contactUid): ?Contacts
    {
        return Contacts::query()
            ->where('business_id', $business->id)
            ->where('uid', $contactUid)
            ->with('contactGroup:id,uid,business_id,name')
            ->first();
    }

    /**
     * @return array{
     *     name: ?string, phone: string, email: ?string, company: ?string,
     *     subscribed: bool, added: \Illuminate\Support\Carbon|null,
     *     group: array{uid: string, name: string}|null,
     *     details: list<array{label: string, value: string}>,
     *     campaigns: list<array{name: string, status: ?string, at: \Illuminate\Support\Carbon|null}>,
     *     conversation: ?ChatBox
     * }
     */
    public function profile(Business $business, Contacts $contact, bool $includeConversation): array
    {
        $fields = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)
            ->orderBy('f.id')
            ->get(['f.tag', 'f.label', 'v.value']);

        $identity = [];
        $details = [];

        foreach ($fields as $field) {
            $value = trim((string) $field->value);

            if ($value === '' || $field->tag === 'PHONE') {
                continue;
            }

            if (in_array($field->tag, self::IDENTITY_TAGS, true)) {
                $identity[$field->tag] ??= $value;

                continue;
            }

            $details[] = ['label' => (string) $field->label, 'value' => $value];
        }

        $group = $contact->contactGroup !== null && (int) $contact->contactGroup->business_id === (int) $business->id
            ? ['uid' => (string) $contact->contactGroup->uid, 'name' => (string) $contact->contactGroup->name]
            : null;

        $conversation = $includeConversation ? $this->conversationFor($business, $contact) : null;

        return [
            'name' => $this->fullName($identity),
            'phone' => $this->displayPhone($contact),
            'email' => $identity['EMAIL'] ?? null,
            'company' => $identity['COMPANY'] ?? null,
            'subscribed' => $contact->status === Contacts::STATUS_SUBSCRIBE,
            'added' => $contact->created_at,
            'group' => $group,
            'details' => $details,
            'campaigns' => $this->campaignMessagesFor($business, $contact),
            'conversation' => $conversation,
            'messages' => $conversation !== null ? $this->latestMessages($conversation) : [],
        ];
    }

    /**
     * The conversation's latest messages, oldest first, for the profile.
     *
     * @return list<array{incoming: bool, text: string, at: \Illuminate\Support\Carbon|null}>
     */
    private function latestMessages(ChatBox $conversation, int $limit = 5): array
    {
        return DB::table('chat_box_messages')
            ->where('box_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['message', 'media_url', 'direction', 'send_by', 'created_at'])
            ->reverse()
            ->map(fn ($row) => [
                'incoming' => $row->direction !== null ? $row->direction === 'incoming' : $row->send_by === 'to',
                'text' => trim((string) $row->message) !== '' ? (string) $row->message : ($row->media_url !== null ? 'Media message' : ''),
                'at' => $row->created_at !== null ? \Illuminate\Support\Carbon::parse($row->created_at) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The contact's campaign messages in this Business, newest first.
     *
     * @return list<array{name: string, status: ?string, at: \Illuminate\Support\Carbon|null}>
     */
    private function campaignMessagesFor(Business $business, Contacts $contact, int $limit = 10): array
    {
        return DB::table('tracking_logs as t')
            ->join('campaigns as c', 'c.id', '=', 't.campaign_id')
            ->where('t.contact_id', $contact->id)
            ->where('t.business_id', $business->id)
            ->where('c.business_id', $business->id)
            ->orderByDesc('t.id')
            ->limit($limit)
            ->get(['c.campaign_name', 't.status', 't.created_at'])
            ->map(fn ($row) => [
                'name' => trim((string) $row->campaign_name) !== '' ? (string) $row->campaign_name : 'Untitled campaign',
                'status' => $row->status !== null ? ucfirst((string) $row->status) : null,
                'at' => $row->created_at !== null ? \Illuminate\Support\Carbon::parse($row->created_at) : null,
            ])
            ->all();
    }

    /**
     * This Business's conversation with the contact's number, if there is one.
     */
    private function conversationFor(Business $business, Contacts $contact): ?ChatBox
    {
        $phone = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $contact->phone);

        if ($phone === '') {
            return null;
        }

        return ChatBox::query()
            ->where('business_id', $business->id)
            ->where('to', 'like', '%' . $phone)
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (ChatBox $box) => ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to) === $phone);
    }

    private function query(Business $business, string $search): Builder
    {
        $query = Contacts::query()->where('business_id', $business->id);

        if ($search === '') {
            return $query;
        }

        $digits = (string) preg_replace('/\D+/', '', $search);
        $like = '%' . addcslashes($search, '%_\\') . '%';

        return $query->where(function (Builder $where) use ($digits, $like) {
            if ($digits !== '') {
                $where->orWhere('phone', 'like', '%' . $digits . '%');
            }

            $where->orWhereExists(function ($values) use ($like) {
                $values->selectRaw('1')
                    ->from('contacts_custom_field as v')
                    ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
                    ->whereColumn('v.contact_id', 'contacts.id')
                    ->whereIn('f.tag', self::IDENTITY_TAGS)
                    ->where('v.value', 'like', $like);
            });
        });
    }

    /**
     * Identity custom fields for a page of contacts, in one query.
     *
     * @param  list<int>  $contactIds
     * @return array<int, array<string, string>> contact id => tag => first non-empty value
     */
    private function identityFor(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $identity = [];

        $rows = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->whereIn('v.contact_id', $contactIds)
            ->whereIn('f.tag', self::IDENTITY_TAGS)
            ->orderBy('v.id')
            ->get(['v.contact_id', 'f.tag', 'v.value']);

        foreach ($rows as $row) {
            $value = trim((string) $row->value);

            if ($value !== '') {
                $identity[(int) $row->contact_id][(string) $row->tag] ??= $value;
            }
        }

        return $identity;
    }

    /**
     * The latest campaign message or conversation update per contact, for a
     * page of contacts, in two queries.
     *
     * @param  Collection<int, Contacts>  $contacts
     * @return array<int, \Illuminate\Support\Carbon> contact id => latest activity
     */
    private function lastActivityFor(Business $business, Collection $contacts): array
    {
        if ($contacts->isEmpty()) {
            return [];
        }

        $latest = [];

        $campaignActivity = DB::table('tracking_logs')
            ->where('business_id', $business->id)
            ->whereIn('contact_id', $contacts->pluck('id')->all())
            ->groupBy('contact_id')
            ->selectRaw('contact_id, max(created_at) as at')
            ->get();

        foreach ($campaignActivity as $row) {
            if ($row->at !== null) {
                $latest[(int) $row->contact_id] = \Illuminate\Support\Carbon::parse($row->at);
            }
        }

        $byPhone = [];

        foreach ($contacts as $contact) {
            $phone = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $contact->phone);

            if ($phone !== '') {
                $byPhone[$phone][] = (int) $contact->id;
            }
        }

        if ($byPhone !== []) {
            $numbers = array_keys($byPhone);
            $variants = array_merge($numbers, array_map(fn (string $phone) => '+' . $phone, $numbers));

            $boxes = DB::table('chat_boxes')
                ->where('business_id', $business->id)
                ->whereIn('to', $variants)
                ->get(['to', 'updated_at']);

            foreach ($boxes as $box) {
                $phone = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to);

                if ($box->updated_at === null || ! isset($byPhone[$phone])) {
                    continue;
                }

                $at = \Illuminate\Support\Carbon::parse($box->updated_at);

                foreach ($byPhone[$phone] as $contactId) {
                    if (! isset($latest[$contactId]) || $at->greaterThan($latest[$contactId])) {
                        $latest[$contactId] = $at;
                    }
                }
            }
        }

        return $latest;
    }

    private function groupNameFor(Business $business, Contacts $contact): ?string
    {
        $group = $contact->contactGroup;

        return $group !== null && (int) $group->business_id === (int) $business->id ? (string) $group->name : null;
    }

    /**
     * @param  array<string, string>  $identity
     */
    private function fullName(array $identity): ?string
    {
        $name = trim(($identity['FIRST_NAME'] ?? '') . ' ' . ($identity['LAST_NAME'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function displayPhone(Contacts $contact): string
    {
        $digits = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $contact->phone);

        return $digits !== '' ? '+' . $digits : '';
    }
}
