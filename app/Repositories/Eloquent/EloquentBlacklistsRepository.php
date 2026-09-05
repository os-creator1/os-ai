<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Models\Blacklists;
    use App\Models\ContactGroups;
    use App\Models\Contacts;
    use App\Repositories\Contracts\BlacklistsRepository;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\DB;
    use Throwable;

    class EloquentBlacklistsRepository extends EloquentBaseRepository implements BlacklistsRepository
    {
        /**
         * EloquentBlacklistsRepository constructor.
         *
         * @param Blacklists $blacklists
         */
        public function __construct(Blacklists $blacklists)
        {
            parent::__construct($blacklists);
        }

        /**
         * store blacklist
         *
         * @param array $input
         *
         * @return Collection
         */
        public function store(array $input): Collection
        {
            $user       = auth()->user();
            $businessId = app(\App\Library\Business\LegacyBusinessResolver::class)->resolveForCustomer((int) $user->id)?->id;
            $delimiter = match ($input['delimiter']) {
                ';' => ';',
                '|' => '|',
                'tab' => "\t",
                'new_line' => "\n",
                default => ',',
            };

            // Split and clean numbers
            $numbers = collect(explode($delimiter, $input['number']))
                ->map(fn($number) => $this->cleanNumber($number))
                ->filter(fn($number) => ! empty($number) && strlen($number) <= 14)
                ->unique();

            
            // Process in chunks
            $numbers->chunk(1000)->each(function ($chunk) use ($input, $user, $businessId) {
                $insertData = [];

                foreach ($chunk as $number) {
                    // Update contacts
                    $query = Contacts::where('phone', $number);
                    if ( ! $user->is_admin) {
                        $query->where('customer_id', $user->id);
                    }
                    $query->update(['status' => 'unsubscribe']);

                    $insertData[] = [
                        'uid'         => uniqid(),
                        'user_id'     => $user->id,
                        'business_id' => $businessId,
                        'number'      => $number,
                        'reason'      => $input['reason'],
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ];
                }

                Blacklists::insert($insertData);
            });


            // Get unique group IDs from the updated contacts
            $groupIdsQuery = Contacts::whereIn('phone', $numbers);
            if ( ! $user->is_admin) {
                $groupIdsQuery->where('customer_id', $user->id);
            }
            $groupIds = $groupIdsQuery->pluck('group_id')->filter()->unique();

// Update cache for each group
            ContactGroups::whereIn('id', $groupIds)->get()->each(function ($contactGroup) {
                $contactGroup->updateCache();
            });

            return $numbers;
        }

// Clean number helper
        protected function cleanNumber(string $number): string
        {
            return str_replace(["\r", "\n", '+', '-', '(', ')', ' '], '', $number);
        }


        /**
         * @param Blacklists $blacklists
         *
         * @return bool|null
         * @throws GeneralException
         * @throws Exception
         */
        public function destroy(Blacklists $blacklists)
        {
            $contactQuery = Contacts::where('phone', $blacklists->number);

            if ( ! $blacklists->user->is_admin) {
                $contactQuery->where('customer_id', $blacklists->user_id);
            }

            $contact = $contactQuery->first();
            $contact?->update([
                'status' => 'subscribe',
            ]);
            if ( ! $blacklists->delete()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Throwable
         */
        public function batchDestroy(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)->delete()) {
                    return true;
                }
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

    }
