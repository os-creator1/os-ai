<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Models\Templates;
    use App\Repositories\Contracts\TemplatesRepository;
    use Auth;
    use Exception;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\DB;
    use Throwable;

    class EloquentTemplatesRepository extends EloquentBaseRepository implements TemplatesRepository
    {
        /**
         * EloquentTemplatesRepository constructor.
         *
         * @param Templates $template
         */
        public function __construct(Templates $template)
        {
            parent::__construct($template);
        }

        /**
         * @param array $input
         *
         * @return Templates
         *
         * @throws GeneralException
         */
        public function store(array $input): Templates
        {
            /** @var Templates $template */
            $template = $this->make(Arr::only($input, ['name', 'message', 'user_id', 'dlt_template_id', 'dlt_category', 'sender_id']));

            if (config('app.trai_dlt')) {
                if ($input['user_type'] == 'admin' || ! Auth::user()->customer->activeSubscription()->plan->is_dlt) {
                    $template->status   = true;
                    $template->approved = 'approved';
                } else {
                    $template->status   = false;
                    $template->approved = 'in_review';
                }
            } else {
                $template->status   = true;
                $template->approved = 'approved';
            }


            if ( ! $this->save($template)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $template;

        }

        /**
         * @param Templates $template
         *
         * @return bool
         */
        private function save(Templates $template): bool
        {
            if ( ! $template->save()) {
                return false;
            }

            return true;
        }

        /**
         * @param Templates $template
         * @param array     $input
         *
         * @return Templates
         * @throws Exception|Throwable
         *
         * @throws Exception
         */
        public function update(Templates $template, array $input): Templates
        {
            if (isset($input['approved']) && $input['approved'] == 'block') {
                $input['status'] = false;
            }

            if ( ! isset($input['is_dlt'])) {
                $input['dlt_template_id'] = null;
                $input['dlt_category']    = null;
                $input['sender_id']       = null;
            }

            if ( ! $template->update($input)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $template;
        }

        /**
         * @param Templates $template
         *
         * @return bool
         * @throws GeneralException
         */
        public function destroy(Templates $template): bool
        {
            if ( ! $template->delete()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchDestroy(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                // This wont call eloquent events, change to destroy if needed
                if ($this->query()->whereIn('uid', $ids)->delete()) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchActive(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->update(['status' => true])
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

        /**
         * @param array $ids
         *
         * @return mixed
         * @throws Exception|Throwable
         *
         */
        public function batchDisable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->update(['status' => false])
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

    }
