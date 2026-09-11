<?php

    namespace App\Models;

    use App\Library\Business\Migration\ChatBoxBusinessBackfillV1;
    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Illuminate\Database\Eloquent\Relations\HasOne;

    /**
     * One conversation between a Business-side number and one external party.
     *
     * ORIENTATION (Slice 2B §4/§5), for every producer without exception:
     *   from = the Business's own sending/receiving identity
     *   to   = the external counterparty
     * An outbound send and an inbound message for the same real-world pair
     * therefore land on the same (user_id, business_id, from, to) tuple and
     * one thread, never two mirrored ones. There is no creation-direction
     * marker and nothing here branches on one.
     *
     * TENANCY. `business_id` is nullable: a historical conversation whose
     * Business cannot be proven stays NULL and is reachable from no Business
     * route. Messages inherit tenancy from this row and carry no business_id
     * of their own.
     *
     * @method static where(string $string, string $uid)
     * @method static create(array $array)
     */
    class ChatBox extends Model
    {
        use HasUid;

        protected $fillable = [
            'user_id',
            'business_id',
            'from',
            'to',
            'notification',
            'sending_server_id',
            'reply_by_customer',
            'pinned',
        ];

        protected $casts = [
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'notification' => 'integer',
        ];

        /**
         * The full thread — for the opened conversation only. Lists use
         * latestMessage() instead, so rendering a preview never loads a
         * conversation's entire history.
         */
        public function chatBoxMessages(): HasMany
        {
            return $this->hasMany(ChatBoxMessage::class, 'box_id', 'id');
        }

        /**
         * Slice 2B §14 — the one message a list row needs.
         *
         * latestOfMany() batches exactly like any other eager-loaded relation,
         * so a list of fifty conversations costs one extra query, not fifty,
         * and never reads more than one message per conversation.
         */
        public function latestMessage(): HasOne
        {
            return $this->hasOne(ChatBoxMessage::class, 'box_id', 'id')->latestOfMany();
        }

        public function business(): BelongsTo
        {
            return $this->belongsTo(Business::class);
        }

        public function boxMessages()
        {
            $this->belongsTo(ChatBoxMessage::class, 'box_id', 'id');
        }

        /**
         * Slice 2B §10 — who this conversation is with, for display only.
         *
         * Replaces the old `belongsTo(Contacts::class, 'to', 'phone')`, which
         * matched a Contact by phone across EVERY tenant: two Businesses with a
         * Contact on the same number could see each other's name. It could not
         * be repaired as a relationship either, because the rule below — more
         * than one match means show no name — has no belongsTo equivalent.
         *
         * Exactly one same-Business Contact: that Contact. None, or several:
         * null, and the caller shows the phone number. Several is never
         * resolved with first() — picking one of two same-number Contacts is a
         * guess, and a wrong name is worse than no name.
         *
         * A conversation does not need a Contact, and none is ever created.
         */
        public function resolveDisplayContact(Business $business): ?Contacts
        {
            return self::displayContactsFor($business, [$this])[$this->id] ?? null;
        }

        /**
         * The same rule for a whole list, in ONE query.
         *
         * @param  iterable<ChatBox>  $boxes
         * @return array<int, Contacts|null> box id => display Contact
         */
        public static function displayContactsFor(Business $business, iterable $boxes): array
        {
            $byPhone = [];

            foreach ($boxes as $box) {
                $phone = ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to);

                if ($phone !== '') {
                    $byPhone[$phone][] = (int) $box->id;
                }
            }

            $result = [];

            foreach ($byPhone as $boxIds) {
                foreach ($boxIds as $boxId) {
                    $result[$boxId] = null;
                }
            }

            if ($byPhone === []) {
                return $result;
            }

            // Scoped to the selected Business, never to the customer: a
            // Contact on the same number in a sibling Business must not leak
            // its name into this one.
            $matches = Contacts::query()
                ->where('business_id', $business->id)
                ->whereIn('phone', array_keys($byPhone))
                ->get()
                ->groupBy(static fn (Contacts $contact): string => (string) $contact->phone);

            foreach ($byPhone as $phone => $boxIds) {
                $candidates = $matches->get((string) $phone);

                $display = ($candidates !== null && $candidates->count() === 1) ? $candidates->first() : null;

                foreach ($boxIds as $boxId) {
                    $result[$boxId] = $display;
                }
            }

            return $result;
        }
    }
