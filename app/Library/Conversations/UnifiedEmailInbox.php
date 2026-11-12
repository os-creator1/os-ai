<?php

namespace App\Library\Conversations;

use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
use App\Library\BusinessEmail\BusinessEmailLocationScope;
use App\Library\Crm\CrmLocationScope;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\ChatBox;
use App\Models\Contacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Email threads for the unified Conversations inbox — a read model composed
 * over the existing email ledger (business_email_messages). It stores nothing,
 * sends nothing and calls no provider.
 *
 * Every read is scoped to the Business and then to the actor's Location reach
 * (BusinessEmailLocationScope), exactly as the former standalone Email page was.
 *
 * ASSOCIATION IS NEVER GUESSED. An email belongs to a person only through the
 * `contact_id` the email ledger already recorded; an SMS conversation is that
 * person's only when its number resolves to exactly one Contact of this
 * Business (ChatBox::displayContactsFor). Display names are never compared.
 */
class UnifiedEmailInbox
{
    public const MAX_MESSAGES = 300;

    private const COLUMNS = ['id', 'uid', 'direction', 'contact_id', 'location_id', 'provider_thread_id', 'from_email', 'from_name', 'to_email', 'subject', 'body_text', 'status', 'received_at', 'created_at'];

    public function __construct(private readonly BusinessEmailLocationScope $locations)
    {
    }

    /** The email rows this actor may see in this Business. */
    public function visible(Business $business, int $userId): Builder
    {
        $query = BusinessEmailMessage::query()->where('business_id', $business->id);
        $this->locations->restrictMessages($query, $userId, $business);

        return $query;
    }

    public static function threadKey(BusinessEmailMessage $message): string
    {
        return $message->provider_thread_id !== null && $message->provider_thread_id !== ''
            ? (string) $message->provider_thread_id
            : (string) $message->uid;
    }

    public static function activityAt(BusinessEmailMessage $message): \Carbon\CarbonInterface
    {
        return $message->received_at ?? $message->created_at;
    }

    /**
     * Threads from the most recent messages, newest activity first.
     *
     * @return Collection<int, array{key: string, subject: string, latest: BusinessEmailMessage, count: int, counterpart: string, contact_id: int|null, at: \Carbon\CarbonInterface}>
     */
    public function threads(Business $business, int $userId, string $search = ''): Collection
    {
        $query = $this->visible($business, $userId);

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('from_email', 'LIKE', $like)
                    ->orWhere('from_name', 'LIKE', $like)
                    ->orWhere('to_email', 'LIKE', $like)
                    ->orWhere('subject', 'LIKE', $like)
                    ->orWhere('body_text', 'LIKE', $like);
            });
        }

        return $query->orderByDesc('id')->limit(self::MAX_MESSAGES)->get(self::COLUMNS)
            ->groupBy(fn (BusinessEmailMessage $m) => self::threadKey($m))
            ->map(function (Collection $messages, $key) {
                $latest = $messages->first();

                return [
                    'key' => (string) $key,
                    'subject' => (string) $latest->subject,
                    'latest' => $latest,
                    'count' => $messages->count(),
                    'counterpart' => (string) ($latest->direction === 'inbound' ? $latest->from_email : $latest->to_email),
                    // The ledger's own association, when any message of the thread carries one.
                    'contact_id' => $messages->pluck('contact_id')->filter()->first(),
                    'at' => self::activityAt($latest),
                ];
            })
            ->sortByDesc(fn (array $thread) => $thread['at']->getTimestamp())
            ->values();
    }

    /** One thread's messages, oldest first. Empty when it is not visible to this actor. */
    public function thread(Business $business, int $userId, string $key): Collection
    {
        return $this->visible($business, $userId)
            ->where(fn ($q) => $q->where('provider_thread_id', $key)->orWhere('uid', $key))
            ->orderBy('id')
            ->limit(100)
            ->get(self::COLUMNS);
    }

    /**
     * The SMS conversation that is provably the same person as each Contact:
     * the box whose number resolves to exactly that Contact.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, ChatBox> contact id => box
     */
    public function boxesByContact(Business $business, int $userId, array $contactIds): array
    {
        $contactIds = array_values(array_unique(array_filter($contactIds)));

        if ($contactIds === []) {
            return [];
        }

        $contacts = Contacts::query()->where('business_id', $business->id)->whereIn('id', $contactIds)->get(['id', 'phone']);

        $variants = [];

        foreach ($contacts as $contact) {
            $digits = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $contact->phone);

            if ($digits !== '') {
                $variants[] = $digits;
                $variants[] = '+' . $digits;
            }
        }

        if ($variants === []) {
            return [];
        }

        $boxQuery = ChatBox::query()->where('business_id', $business->id)->whereIn('to', array_unique($variants));
        app(CrmLocationScope::class)->restrict($boxQuery, $business, $userId, 'chat_boxes.location_id');
        $boxes = $boxQuery->with('latestMessage')->get();

        $resolved = ChatBox::displayContactsFor($business, $boxes);
        $result = [];

        foreach ($boxes as $box) {
            $contact = $resolved[$box->id] ?? null;

            if ($contact !== null && in_array((int) $contact->id, $contactIds, true)) {
                $result[(int) $contact->id] ??= $box;
            }
        }

        return $result;
    }

    /**
     * "First Last" for a set of Contacts the ACTOR may see, in a bounded number of queries (never one per Contact).
     *
     * Visibility is BusinessEmailLocationScope::contactAccessible() applied in bulk: a Contact with no Location is visible; one with a
     * Location only when that Location belongs to this Business and the actor may access it. A restricted Contact's name is never read,
     * so the caller shows the permitted email counterpart instead. The email ROW stays visible under its own Location scope.
     *
     * @param  array<int, int>  $contactIds
     * @return array<int, string> contact id => name (absent when the Contact is hidden or has none)
     */
    public function contactNames(Business $business, int $userId, array $contactIds): array
    {
        $contactIds = array_values(array_unique(array_filter($contactIds)));

        if ($contactIds === []) {
            return [];
        }

        $contacts = Contacts::query()->where('business_id', $business->id)->whereIn('id', $contactIds)->get(['id', 'location_id']);
        $reachable = array_flip($this->locations->accessibleLocationIds($userId, $business));

        // contactAccessible(): the Location must exist in THIS Business and be reachable (accessibleLocationIds is already Business-scoped).
        $owned = $contacts
            ->filter(fn (Contacts $contact) => $contact->location_id === null || isset($reachable[(int) $contact->location_id]))
            ->pluck('id')
            ->all();

        if ($owned === []) {
            return [];
        }

        $tags = [];

        $rows = DB::table('contacts_custom_field as value')
            ->join('contact_group_fields as field', 'field.id', '=', 'value.field_id')
            ->whereIn('value.contact_id', $owned)
            ->whereIn('field.tag', ['FIRST_NAME', 'LAST_NAME'])
            ->orderBy('value.id')
            ->get(['value.contact_id', 'field.tag', 'value.value']);

        foreach ($rows as $row) {
            $tags[(int) $row->contact_id][$row->tag] ??= (string) $row->value;
        }

        $names = [];

        foreach ($owned as $id) {
            $full = trim(($tags[(int) $id]['FIRST_NAME'] ?? '') . ' ' . ($tags[(int) $id]['LAST_NAME'] ?? ''));

            if ($full !== '') {
                $names[(int) $id] = $full;
            }
        }

        return $names;
    }
}
